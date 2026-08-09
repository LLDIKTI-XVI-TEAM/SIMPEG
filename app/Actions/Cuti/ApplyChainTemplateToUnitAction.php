<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\RefUnitKerja;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menyalin konfigurasi rantai approval cuti milik satu pegawai ke seluruh anggota satu unit kerja.
 *
 * Keanggotaan unit diturunkan dari riwayat jabatan terkini karena tabel pegawai tidak menyimpan unit
 * kerja. Aksi ini tidak membuat rantai berlaku per unit: setiap pegawai tetap memiliki tepat satu
 * rantai aktif miliknya sendiri, sehingga tidak ada pertanyaan precedence saat runtime.
 *
 * Sub-unit sengaja tidak diikutkan supaya cakupan unit tidak menyelundup menjadi lapisan resolusi.
 */
class ApplyChainTemplateToUnitAction
{
    /**
     * Nama kunci dasar advisory lock. Kunci diturunkan per unit supaya penerapan pada unit berbeda
     * tidak saling menunggu, sementara dua penerapan pada unit yang sama tetap berurutan.
     */
    private const LOCK_PREFIX = 'simpeg.leave_chain_unit:';

    public function __construct(private readonly SaveEmployeeApprovalChainAction $saveChain) {}

    /**
     * @return array{
     *     applied_employee_ids: list<string>,
     *     overwritten_employee_ids: list<string>,
     *     skipped_inactive_employee_ids: list<string>,
     *     skipped_missing_kepala_bagian_employee_ids: list<string>,
     *     skipped_self_approval_employee_ids: list<string>,
     *     unreachable_without_latest_position_count: int
     * }
     */
    public function execute(
        RefUnitKerja $unitKerja,
        Employee $sumber,
        User $actor,
        string $reason,
        ?Request $request = null,
    ): array {
        $langkahSumber = $this->langkahSumber($sumber);

        // Penerapan dan jejaknya disatukan dalam satu transaksi supaya konfigurasi persetujuan unit
        // tidak pernah berpindah sebagian tanpa baris audit yang menerangkan cakupan perubahannya.
        return DB::transaction(function () use ($unitKerja, $sumber, $actor, $reason, $request, $langkahSumber): array {
            $this->lockUnit($unitKerja);

            $hasil = [
                'applied_employee_ids' => [],
                'overwritten_employee_ids' => [],
                'skipped_inactive_employee_ids' => [],
                'skipped_missing_kepala_bagian_employee_ids' => [],
                'skipped_self_approval_employee_ids' => [],
                'unreachable_without_latest_position_count' => $this->jumlahPegawaiTanpaRiwayatTerkini(),
            ];

            $this->anggotaUnit($unitKerja)
                ->chunkById(100, function (EloquentCollection $anggota) use (&$hasil, $sumber, $actor, $reason, $request, $langkahSumber): void {
                    foreach ($anggota as $pegawai) {
                        if ($pegawai->id === $sumber->id) {
                            continue;
                        }

                        if ($pegawai->status_aktif !== 'Aktif') {
                            $hasil['skipped_inactive_employee_ids'][] = $pegawai->id;

                            continue;
                        }

                        // Tanpa Kepala Bagian efektif, resolver akan menolak pengajuan pegawai ini,
                        // jadi rantai tidak dibuat agar tidak tampak berhasil padahal tidak terpakai.
                        // Penugasan dibaca lewat method pegawai, bukan disalin ulang sebagai kondisi
                        // tanggal di sini, supaya aturan atasan efektif tetap satu definisi bersama
                        // resolver dan form konfigurasi. Konsekuensinya satu kueri per pegawai, dan
                        // itu diterima karena penerapan ini dijalankan sesekali untuk satu unit.
                        $kepalaBagianId = $pegawai->currentSupervisor()?->kepala_bagian_id;

                        if ($kepalaBagianId === null) {
                            $hasil['skipped_missing_kepala_bagian_employee_ids'][] = $pegawai->id;

                            continue;
                        }

                        $langkahTujuan = $this->langkahUntukTujuan($langkahSumber, $kepalaBagianId, $pegawai);

                        // Bila pegawai tujuan justru menjadi approver pada langkah yang tidak dapat
                        // dibuang, rantainya tidak dibuat. Snapshot pengajuan membuang approver yang
                        // sama dengan pemohon lalu menandai langkah terakhir yang tersisa sebagai
                        // final, sehingga keputusan akhir akan berpindah ke pejabat yang tidak
                        // ditunjuk. Pegawai seperti ini dilaporkan agar rantainya diatur manual.
                        if ($langkahTujuan === null) {
                            $hasil['skipped_self_approval_employee_ids'][] = $pegawai->id;

                            continue;
                        }

                        $sudahPunyaRantai = LeaveApprovalChain::query()
                            ->where('employee_id', $pegawai->id)
                            ->where('is_active', true)
                            ->exists();

                        $this->saveChain->execute(
                            $pegawai,
                            $langkahTujuan,
                            $actor,
                            $reason,
                            $request,
                        );

                        if ($sudahPunyaRantai) {
                            $hasil['overwritten_employee_ids'][] = $pegawai->id;

                            continue;
                        }

                        $hasil['applied_employee_ids'][] = $pegawai->id;
                    }
                });

            AuditService::logOrFail(
                'CONFIG_UPDATE',
                'RefUnitKerja',
                $unitKerja->id,
                null,
                [
                    'unit_kerja_id' => $unitKerja->id,
                    'unit_kerja_nama' => $unitKerja->nama,
                    'source_employee_id' => $sumber->id,
                    'reason' => $reason,
                    'applied_count' => count($hasil['applied_employee_ids']),
                    'overwritten_count' => count($hasil['overwritten_employee_ids']),
                    'skipped_inactive_count' => count($hasil['skipped_inactive_employee_ids']),
                    'skipped_missing_kepala_bagian_count' => count($hasil['skipped_missing_kepala_bagian_employee_ids']),
                    'skipped_self_approval_count' => count($hasil['skipped_self_approval_employee_ids']),
                    'unreachable_without_latest_position_count' => $hasil['unreachable_without_latest_position_count'],
                    'applied_employee_ids' => $hasil['applied_employee_ids'],
                    'overwritten_employee_ids' => $hasil['overwritten_employee_ids'],
                    'skipped_inactive_employee_ids' => $hasil['skipped_inactive_employee_ids'],
                    'skipped_missing_kepala_bagian_employee_ids' => $hasil['skipped_missing_kepala_bagian_employee_ids'],
                    'skipped_self_approval_employee_ids' => $hasil['skipped_self_approval_employee_ids'],
                ],
                $request,
            );

            return $hasil;
        });
    }

    /**
     * Mengambil langkah rantai aktif pegawai sumber sebagai bentuk template.
     *
     * @return list<array{step_type:string, role_label:string, approver_employee_id:string, approver_role_key:?string, is_final:bool}>
     */
    private function langkahSumber(Employee $sumber): array
    {
        $rantai = LeaveApprovalChain::query()
            ->with(['steps' => fn ($query) => $query->orderBy('step_order')])
            ->where('employee_id', $sumber->id)
            ->where('is_active', true)
            ->first();

        if ($rantai === null) {
            throw new RuntimeException('Pegawai sumber belum memiliki rantai approval aktif untuk disalin.');
        }

        return $rantai->steps
            ->map(fn ($step): array => [
                'step_type' => (string) $step->step_type,
                'role_label' => (string) $step->role_label,
                'approver_employee_id' => (string) $step->approver_employee_id,
                'approver_role_key' => $step->approver_role_key,
                'is_final' => (bool) $step->is_final,
            ])
            ->values()
            ->all();
    }

    /**
     * Menyesuaikan langkah template untuk satu pegawai tujuan.
     *
     * Langkah Kepala Bagian diisi atasan pegawai tujuan, bukan atasan pegawai sumber, karena rantai
     * milik pegawai wajib menunjuk atasannya sendiri. Approver yang sama dengan pegawai tujuan
     * dibuang supaya tidak ada orang yang menyetujui pengajuannya sendiri, dan approver yang berulang
     * berurutan dilewati agar satu orang tidak menyetujui dua tahap beruntun.
     *
     * Mengembalikan null bila pegawai tujuan menjadi approver pada langkah yang tidak dapat dibuang,
     * karena rantai seperti itu akan memindahkan keputusan akhir ke pejabat yang tidak ditunjuk.
     *
     * @param  list<array{step_type:string, role_label:string, approver_employee_id:string, approver_role_key:?string, is_final:bool}>  $langkahSumber
     * @return list<array{step_type:string, role_label:string, approver_employee_id:string, approver_role_key:?string, is_final:bool}>|null
     */
    private function langkahUntukTujuan(array $langkahSumber, string $kepalaBagianId, Employee $tujuan): ?array
    {
        $hasil = [];
        $approverSebelumnya = null;

        foreach ($langkahSumber as $langkah) {
            if ($langkah['step_type'] === 'kepala_bagian') {
                $langkah['approver_employee_id'] = $kepalaBagianId;
            }

            $wajibAda = $langkah['is_final'] || $langkah['step_type'] === 'kepala_bagian';

            if ($wajibAda && $langkah['approver_employee_id'] === $tujuan->id) {
                return null;
            }

            if (! $wajibAda && ($langkah['approver_employee_id'] === $tujuan->id || $langkah['approver_employee_id'] === $approverSebelumnya)) {
                continue;
            }

            $hasil[] = $langkah;
            $approverSebelumnya = $langkah['approver_employee_id'];
        }

        return $hasil;
    }

    /**
     * Anggota unit ditentukan dari riwayat jabatan yang ditandai terkini, sumber yang sama dengan
     * yang dipakai halaman daftar pegawai, supaya unit di layar dan unit yang dipakai aksi sama.
     *
     * @return Builder<Employee>
     */
    private function anggotaUnit(RefUnitKerja $unitKerja)
    {
        return Employee::query()
            ->whereHas('positionHistories', fn ($query) => $query
                ->where('is_latest', true)
                ->where('unit_kerja_id', $unitKerja->id))
            ->orderBy('nama_lengkap');
    }

    /**
     * Pegawai aktif tanpa riwayat jabatan terkini tidak dapat dipetakan ke unit mana pun, sehingga
     * jumlahnya dilaporkan sebagai peringatan agar admin tahu ada pegawai yang tidak terjangkau.
     */
    private function jumlahPegawaiTanpaRiwayatTerkini(): int
    {
        return Employee::query()
            ->where('status_aktif', 'Aktif')
            ->whereDoesntHave('positionHistories', fn ($query) => $query->where('is_latest', true))
            ->count();
    }

    /**
     * Advisory lock hanya mencegah dua penerapan massal pada unit yang sama berjalan bersamaan.
     * Invariant satu rantai aktif per pegawai tetap dijaga unique index dan lock baris di penyimpanan.
     */
    private function lockUnit(RefUnitKerja $unitKerja): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::select(
            'select pg_advisory_xact_lock(hashtextextended(?, 0))',
            [self::LOCK_PREFIX.$unitKerja->id],
        );
    }
}
