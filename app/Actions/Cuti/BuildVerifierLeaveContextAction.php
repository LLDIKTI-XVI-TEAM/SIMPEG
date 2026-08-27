<?php

namespace App\Actions\Cuti;

use App\Data\Cuti\VerifierLeaveHistoryRow;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\RefHariLibur;
use App\Models\RefJenisCuti;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     *     riwayatTahunan: Collection<int, VerifierLeaveHistoryRow>
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
     * @return Collection<int, VerifierLeaveHistoryRow>
     */
    private function approvedAnnualHistory(Employee $employee, Carbon $asOf): Collection
    {
        return collect(range($asOf->year, $asOf->year - 2))
            ->flatMap(function (int $year) use ($employee): Collection {
                $approvedRequests = DB::table('leave_requests as requests')
                    ->join('ref_jenis_cuti as leave_types', 'leave_types.id', '=', 'requests.jenis_cuti_id')
                    ->where('requests.employee_id', $employee->id)
                    ->where('requests.status', 'disetujui')
                    ->where('leave_types.code', RefJenisCuti::CODE_TAHUNAN)
                    ->whereYear('requests.tanggal_mulai', $year)
                    ->select([
                        'requests.id',
                        DB::raw("'approved_request' as source_type"),
                        'requests.tanggal_mulai',
                        'requests.tanggal_selesai',
                        'requests.jumlah_hari_kerja',
                    ]);
                $manualDecisions = DB::table('leave_usage_records as usage')
                    ->join('ref_jenis_cuti as leave_types', 'leave_types.id', '=', 'usage.leave_type_id')
                    ->where('usage.employee_id', $employee->id)
                    ->where('usage.source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)
                    ->where('usage.record_status', LeaveUsageRecord::STATUS_ACTIVE)
                    ->where('leave_types.code', RefJenisCuti::CODE_TAHUNAN)
                    ->whereYear('usage.start_date', $year)
                    ->select([
                        'usage.id',
                        'usage.source_type',
                        'usage.start_date as tanggal_mulai',
                        'usage.end_date as tanggal_selesai',
                        'usage.workdays as jumlah_hari_kerja',
                    ]);

                // UNION hanya memuat field presentasi aman. Catatan administrasi, nomor
                // dokumen, approver, dan metadata file privat tidak pernah masuk read model.
                return DB::query()
                    ->fromSub($approvedRequests->unionAll($manualDecisions), 'annual_history')
                    ->orderByDesc('tanggal_mulai')
                    ->orderByDesc('id')
                    ->limit(5)
                    ->get()
                    ->map(fn (object $row): VerifierLeaveHistoryRow => new VerifierLeaveHistoryRow(
                        id: (string) $row->id,
                        source_type: (string) $row->source_type,
                        tanggal_mulai: CarbonImmutable::parse((string) $row->tanggal_mulai),
                        tanggal_selesai: CarbonImmutable::parse((string) $row->tanggal_selesai),
                        jumlah_hari_kerja: (int) $row->jumlah_hari_kerja,
                    ));
            })
            ->values();
    }
}
