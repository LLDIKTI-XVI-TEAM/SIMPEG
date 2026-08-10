<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RefHariLibur;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Menyusun konteks keputusan untuk verifikator cuti: preview saldo pemohon,
 * cuti bersama pada tahun acuan, dan riwayat cuti tahunan yang sudah disetujui.
 *
 * Verifikator wajib menilai kecukupan saldo dan riwayat pemakaian pemohon sebelum
 * memutuskan pengajuan, sehingga ketiganya disusun sebagai satu paket baca.
 * Action ini murni membaca; tidak boleh ada efek samping penulisan saldo/ledger.
 */
class BuildVerifierLeaveContextAction
{
    public function __construct(private readonly PreviewLeaveBalanceAction $balancePreview) {}

    /**
     * @return array{
     *     balance: array<string, mixed>,
     *     cutiBersama: Collection<int, RefHariLibur>,
     *     riwayatTahunan: Collection<int, LeaveRequest>
     * }
     */
    public function execute(
        Employee $employee,
        Carbon $asOf,
        ?LeaveRequest $leaveRequest = null,
    ): array {
        return [
            'balance' => $this->balancePreview->execute($employee, $asOf, $leaveRequest),
            'cutiBersama' => RefHariLibur::query()
                ->where('tahun', $asOf->year)
                ->where('is_cuti_bersama', true)
                ->orderBy('tanggal')
                ->get(['id', 'tanggal', 'nama']),
            'riwayatTahunan' => $this->approvedAnnualHistory($employee, $asOf),
        ];
    }

    /**
     * Mengambil riwayat N, N-1, dan N-2 secara terpisah agar aktivitas tahun berjalan
     * tidak menghabiskan slot dua tahun sebelumnya yang dibutuhkan verifikator.
     *
     * @return Collection<int, LeaveRequest>
     */
    private function approvedAnnualHistory(Employee $employee, Carbon $asOf): Collection
    {
        return collect(range($asOf->year, $asOf->year - 2))
            ->flatMap(fn (int $year) => LeaveRequest::query()
                ->where('employee_id', $employee->id)
                ->where('status', 'disetujui')
                ->whereHas('jenisCuti', fn (Builder $query) => $query->where('mengurangi_saldo_tahunan', true))
                ->whereYear('tanggal_mulai', $year)
                ->orderByDesc('tanggal_mulai')
                ->limit(5)
                ->get(['id', 'tanggal_mulai', 'tanggal_selesai', 'jumlah_hari_kerja']))
            ->values();
    }
}
