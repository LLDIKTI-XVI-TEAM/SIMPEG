<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Membangun daftar pengajuan cuti dengan pagination/filtering di level database.
 * Mempertahankan scope data-milik-sendiri (cuti.read_all), menghitung counter ringkasan dari base query,
 * dan menyediakan langkah aktif dinamis dari snapshot leave_request_steps (menggantikan stage_atasan/stage_kepala).
 */
class ListLeaveRequestsAction
{
    /**
     * @return array{
     *   riwayatCuti: LengthAwarePaginator,
     *   totalPengajuan: int, jumlahMenunggu: int, jumlahDisetujui: int, jumlahDitangguhkan: int,
     *   optJenisCutis: Collection<int|string, mixed>,
     *   optUnits: Collection<int|string, mixed>,
     *   optPeriodes: Collection<int, non-falsy-string>,
     *   search: string, status: string, jenis: string, unit: string, periode: string,
     *   isPegawai: bool
     * }
     */
    public function execute(User $user, Request $request): array
    {
        $isPegawai = ! $user->hasPermission('cuti.read_all');
        $search = $isPegawai ? '' : trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');
        $jenis = (string) $request->query('jenis', '');
        $unit = $isPegawai ? '' : (string) $request->query('unit', '');
        $periode = (string) $request->query('periode', '');
        $perPage = min(max((int) $request->query('per_page', 10), 10), 50);

        $query = LeaveRequest::query()
            ->with(['employee', 'jenisCuti', 'steps'])
            ->latest();

        // Role pegawai selalu dibatasi ke data sendiri meski mapping permission salah konfigurasi.
        if ($user->role === 'pegawai' || ! $user->hasPermission('cuti.read_all')) {
            $query->where('employee_id', $user->employee_id);
        }

        // Base query (setelah scope, sebelum filter status) menjadi sumber counter ringkasan.
        $baseQuery = clone $query;

        $statusMap = [
            'pending' => ['menunggu_approval'],
            'menunggu' => ['menunggu_approval'],
            'disetujui' => ['disetujui'],
            'ditunda' => ['ditangguhkan'],
            'ditangguhkan' => ['ditangguhkan'],
            'perlu_perubahan' => ['perlu_perubahan'],
            'tidak_disetujui' => ['tidak_disetujui'],
        ];

        if ($status !== '' && isset($statusMap[$status])) {
            $query->whereIn('status', $statusMap[$status]);
        }
        if ($jenis !== '') {
            $query->whereHas('jenisCuti', fn ($jenisQuery) => $jenisQuery->where('nama', $jenis));
        }
        if ($unit !== '') {
            $query->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('jabatan_terakhir', $unit));
        }
        if ($periode !== '') {
            $parts = explode('-', $periode);
            if (count($parts) === 2) {
                $query->whereYear('tanggal_mulai', $parts[0])->whereMonth('tanggal_mulai', $parts[1]);
            }
        }
        if ($search !== '') {
            $query->whereHas('employee', function ($employeeQuery) use ($search): void {
                $employeeQuery->where('nama_lengkap', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%");
            });
        }

        $riwayatCuti = $query->paginate($perPage)->withQueryString();
        $riwayatCuti->getCollection()->transform(fn (LeaveRequest $r): array => $this->mapRow($r));

        return [
            'riwayatCuti' => $riwayatCuti,
            'totalPengajuan' => (clone $baseQuery)->count(),
            'jumlahMenunggu' => (clone $baseQuery)->where('status', 'menunggu_approval')->count(),
            'jumlahDisetujui' => (clone $baseQuery)->where('status', 'disetujui')->count(),
            'jumlahDitangguhkan' => (clone $baseQuery)->where('status', 'ditangguhkan')->count(),
            'optJenisCutis' => RefJenisCuti::orderBy('nama')->pluck('nama'),
            'optUnits' => $isPegawai
                ? collect()
                : Employee::query()
                    ->whereNotNull('jabatan_terakhir')
                    ->distinct()
                    ->orderBy('jabatan_terakhir')
                    ->pluck('jabatan_terakhir'),
            // Portable periode options (verified current producer): 12 bulan terakhir, tanpa SQL PostgreSQL-only.
            'optPeriodes' => collect(range(0, 11))->map(fn (int $offset): string => now()->subMonths($offset)->format('Y-m')),
            'search' => $search,
            'status' => $status,
            'jenis' => $jenis,
            'unit' => $unit,
            'periode' => $periode,
            'isPegawai' => $isPegawai,
        ];
    }

    /**
     * Bentuk baris daftar: pertahankan status runtime mentah dan tambahkan langkah aktif dinamis.
     * Tidak lagi memancarkan slot tetap stage_atasan/stage_kepala.
     *
     * @return array<string, mixed>
     */
    private function mapRow(LeaveRequest $r): array
    {
        $activeStep = $r->steps->firstWhere('status', 'active');

        return [
            'id' => $r->id,
            'nama' => $r->employee?->nama_lengkap ?? '-',
            'nip' => $r->employee?->nip ?? '-',
            'unit' => $r->employee?->jabatan_terakhir ?? '-',
            'jenis' => $r->jenisCuti?->nama ?? '-',
            'mulai' => optional($r->tanggal_mulai)->toDateString(),
            'selesai' => optional($r->tanggal_selesai)->toDateString(),
            'hari' => $r->jumlah_hari_kerja,
            'alasan' => $r->alasan,
            // Status runtime MENTAH (menunggu_approval|disetujui|ditangguhkan|perlu_perubahan|tidak_disetujui).
            'status' => $r->status,
            // role_label langkah aktif untuk menampilkan "Menunggu {label}" pada baris yang menunggu.
            'current_step' => $activeStep?->role_label,
            'periode' => optional($r->tanggal_mulai)->format('Y-m'),
        ];
    }
}
