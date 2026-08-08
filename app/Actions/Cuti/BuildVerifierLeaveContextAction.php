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
    public function execute(Employee $employee, Carbon $asOf): array
    {
        return [
            'balance' => $this->balancePreview->execute($employee, $asOf),
            'cutiBersama' => RefHariLibur::query()
                ->where('tahun', $asOf->year)
                ->where('is_cuti_bersama', true)
                ->orderBy('tanggal')
                ->get(['id', 'tanggal', 'nama']),
            // Riwayat dibatasi pada pengajuan pengurang saldo tahunan yang sudah final disetujui;
            // pengajuan aktif/ditolak bukan bukti pemakaian hak. Dibatasi lima terbaru agar
            // halaman detail tetap ringan.
            'riwayatTahunan' => LeaveRequest::query()
                ->where('employee_id', $employee->id)
                ->where('status', 'disetujui')
                ->whereHas('jenisCuti', fn (Builder $query) => $query->where('mengurangi_saldo_tahunan', true))
                ->orderByDesc('tanggal_mulai')
                ->limit(5)
                ->get(['id', 'tanggal_mulai', 'tanggal_selesai', 'jumlah_hari_kerja']),
        ];
    }
}
