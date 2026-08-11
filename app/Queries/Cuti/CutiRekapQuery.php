<?php

namespace App\Queries\Cuti;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Support\Cuti\CutiPeriodFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CutiRekapQuery
{
    /**
     * Status final persetujuan cuti; hanya status ini yang memotong hak cuti pegawai.
     */
    private const STATUS_DISETUJUI = 'disetujui';

    /**
     * Menyusun sumber detail kanonis tanpa mengambil kolom relasi pegawai yang sensitif dan tidak dibutuhkan.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<LeaveRequest>
     */
    public function detailRows(array $filters): Builder
    {
        $this->guardUuidFilters($filters);
        $period = $this->parsePeriod($filters['periode'] ?? null);

        return LeaveRequest::query()
            ->select([
                'id', 'employee_id', 'jenis_cuti_id', 'tanggal_mulai', 'tanggal_selesai',
                'jumlah_hari_kerja', 'status', 'created_at',
            ])
            ->with([
                'employee:id,nama_lengkap,nip,jabatan_terakhir',
                'jenisCuti:id,nama',
                'steps:id,leave_request_id,step_order,role_label,status',
            ])
            ->when($this->stringFilter($filters, 'unit'), function (Builder $query, string $unit): void {
                $query->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery
                    ->where('jabatan_terakhir', $unit));
            })
            ->when($this->stringFilter($filters, 'pegawai'), fn (Builder $query, string $pegawai) => $query
                ->where('employee_id', $pegawai))
            ->when($this->stringFilter($filters, 'jenis'), fn (Builder $query, string $jenis) => $query
                ->where('jenis_cuti_id', $jenis))
            ->when($period, fn (Builder $query, CutiPeriodFilter $periodFilter) => $periodFilter
                ->applyToDateColumn($query, 'tanggal_mulai'))
            ->orderByDesc('tanggal_mulai')
            ->orderBy('id');
    }

    /**
     * Menyusun sumber saldo dari field materialized ledger; saldo tidak dihitung ulang dari jatah tetap.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<LeaveBalance>
     */
    public function balanceRows(array $filters): Builder
    {
        $this->guardUuidFilters($filters);
        $period = $this->parsePeriod($filters['periode'] ?? null);

        return LeaveBalance::query()
            ->select([
                'id', 'employee_id', 'tahun', 'jatah_awal', 'carry_over', 'terpakai', 'sisa',
                'sisa_n2', 'sisa_n1', 'sisa_tahun_berjalan', 'terpakai_tahun_berjalan', 'hangus',
            ])
            ->with('employee:id,nama_lengkap,nip,jabatan_terakhir')
            ->when($this->stringFilter($filters, 'unit'), function (Builder $query, string $unit): void {
                $query->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery
                    ->where('jabatan_terakhir', $unit));
            })
            ->when($this->stringFilter($filters, 'pegawai'), fn (Builder $query, string $pegawai) => $query
                ->where('employee_id', $pegawai))
            ->when($period, fn (Builder $query, CutiPeriodFilter $periodFilter) => $query
                ->where('tahun', $periodFilter->year))
            ->orderByDesc('tahun')
            ->orderBy('employee_id')
            ->orderBy('id');
    }

    /**
     * Menyusun rekap hari cuti per pegawai per jenis untuk laporan Excel dan PDF.
     *
     * Hanya pengajuan berstatus final disetujui yang dihitung; status menunggu, ditangguhkan,
     * perlu perubahan, dan tidak disetujui tidak mengurangi hak cuti sehingga tidak boleh masuk rekap.
     * Pegawai tanpa pengajuan disetujui pada periode filter sengaja tidak muncul, mengikuti
     * perilaku laporan pimpinan agar kedua laporan tidak berbeda tafsir.
     *
     * @param  Collection<int, LeaveRequest>  $details  Baris detail yang sudah dimuat pemanggil; menghindari query ganda.
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{employee_id: string, nip: string, nama: string, jenis: string, total_hari: int, sisa_saldo: int|string, saldo_tahun: int}>
     */
    public function summaryRows(Collection $details, array $filters): Collection
    {
        $rows = $details->where('status', self::STATUS_DISETUJUI);
        $saldoTahun = $this->saldoYear($filters);
        $balances = LeaveBalance::query()
            ->select(['id', 'employee_id', 'tahun', 'sisa'])
            ->where('tahun', $saldoTahun)
            ->whereIn('employee_id', $rows->pluck('employee_id')->unique())
            ->get()
            ->keyBy('employee_id');

        return $rows
            // Dikelompokkan memakai id jenis cuti karena nama jenis tidak dijamin unik oleh skema.
            ->groupBy(fn (LeaveRequest $row): string => $row->employee_id.'|'.$row->jenis_cuti_id)
            ->values()
            ->map(function (Collection $group) use ($balances, $saldoTahun): array {
                $first = $group->first();
                /** @var int|string $sisaSaldo Tanda '-' dipakai bila pegawai belum punya baris saldo tahun tersebut. */
                $sisaSaldo = $balances->get($first->employee_id)?->sisa ?? '-';

                return [
                    'employee_id' => (string) $first->employee_id,
                    'nip' => (string) ($first->employee?->nip ?? '-'),
                    'nama' => (string) ($first->employee?->nama_lengkap ?? '-'),
                    'jenis' => (string) ($first->jenisCuti?->nama ?? '-'),
                    'total_hari' => (int) $group->sum('jumlah_hari_kerja'),
                    'sisa_saldo' => $sisaSaldo,
                    'saldo_tahun' => $saldoTahun,
                ];
            })
            // Diurutkan agar baris satu pegawai berdekatan; groupBy hanya mempertahankan urutan kemunculan.
            ->sortBy(['nama', 'jenis'])
            ->values();
    }

    /**
     * Tahun saldo yang dipakai rekap; tanpa filter periode saldo mengikuti tahun berjalan.
     *
     * @param  array<string, mixed>  $filters
     */
    public function saldoYear(array $filters): int
    {
        return $this->parsePeriod($filters['periode'] ?? null)?->year ?? (int) now()->year;
    }

    /**
     * Label periode dipakai pada nama berkas unduhan agar penerima mengenali cakupan laporan.
     *
     * @param  array<string, mixed>  $filters
     */
    public function periodLabel(array $filters): string
    {
        return $this->parsePeriod($filters['periode'] ?? null)?->label() ?? 'Semua_Tahun';
    }

    /**
     * Pertahanan berlapis diperlukan karena query dapat dipanggil tanpa melewati FormRequest.
     *
     * @param  array<string, mixed>  $filters
     */
    private function guardUuidFilters(array $filters): void
    {
        foreach (['pegawai', 'jenis'] as $field) {
            if (! array_key_exists($field, $filters) || $filters[$field] === null) {
                continue;
            }

            $value = $filters[$field];
            if (is_array($value) || ! is_string($value) || ! Str::isUuid($value)) {
                abort(404);
            }
        }
    }

    /** @param array<string, mixed> $filters */
    private function stringFilter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Tafsir periode dipusatkan pada helper bersama agar rekap dan daftar pengajuan tidak berbeda hasil.
     */
    private function parsePeriod(mixed $period): ?CutiPeriodFilter
    {
        return CutiPeriodFilter::parse($period);
    }
}
