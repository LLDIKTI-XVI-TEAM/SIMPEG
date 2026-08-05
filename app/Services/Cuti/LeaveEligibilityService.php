<?php

namespace App\Services\Cuti;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestCase;
use App\Models\RefJenisCuti;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Menegakkan kelayakan yang melekat pada jenis cuti.
 *
 * Service ini sengaja memakai kode reference jenis cuti, bukan nama tampilan.
 * FormRequest memakainya untuk umpan balik awal dan Action memakainya lagi di
 * dalam transaksi untuk mencegah bypass maupun race condition.
 */
class LeaveEligibilityService
{
    private const CASE_TYPE_CODES = ['melahirkan', 'cltn'];

    /**
     * Memvalidasi pengajuan baru atau pengiriman ulang tanpa menulis data.
     * Request yang dikirim ulang dikeluarkan dari agregat agar tanggal lamanya
     * tidak ikut dihitung bersama versi tanggal yang sedang divalidasi.
     */
    public function assertCanBeSubmitted(
        Employee $employee,
        RefJenisCuti $leaveType,
        Carbon $startDate,
        Carbon $endDate,
        ?string $leaveRequestCaseId = null,
        ?LeaveRequest $excludingLeaveRequest = null,
    ): void {
        $this->assertSingleCalendarYear($startDate, $endDate);
        $this->assertPnsOnlyType($employee, $leaveType);
        $this->assertCutiBesarServiceLength($employee, $leaveType, $startDate);
        $this->assertCutiBesarDuration($leaveType, $startDate, $endDate);

        if (! $this->requiresExplicitCase($leaveType)) {
            if ($leaveRequestCaseId !== null) {
                throw $this->validationError(
                    'leave_request_case_id',
                    'Keterkaitan rangkaian hanya berlaku untuk Cuti Melahirkan dan CLTN.',
                );
            }

            return;
        }

        if ($leaveRequestCaseId === null) {
            $this->assertWithinCalendarLimit($leaveType, $startDate, $endDate);

            return;
        }

        $leaveCase = LeaveRequestCase::query()->find($leaveRequestCaseId);

        if ($leaveCase === null) {
            throw $this->validationError('leave_request_case_id', 'Rangkaian pengajuan cuti yang dipilih tidak ditemukan.');
        }

        $this->assertCaseMatches($leaveCase, $employee, $leaveType);
        $this->assertCasePeriodAllowed($leaveCase, $leaveType, $startDate, $endDate, $excludingLeaveRequest);
    }

    /**
     * Menentukan atau membuat rangkaian di dalam transaksi submit.
     *
     * @return array{0: LeaveRequestCase|null, 1: bool} [rangkaian, dibuatBaru]
     */
    public function resolveForNewSubmission(
        Employee $employee,
        RefJenisCuti $leaveType,
        Carbon $startDate,
        Carbon $endDate,
        ?string $leaveRequestCaseId,
        ?User $actor = null,
    ): array {
        $this->assertSingleCalendarYear($startDate, $endDate);
        $this->assertPnsOnlyType($employee, $leaveType);
        $this->assertCutiBesarServiceLength($employee, $leaveType, $startDate);
        $this->assertCutiBesarDuration($leaveType, $startDate, $endDate);

        if (! $this->requiresExplicitCase($leaveType)) {
            if ($leaveRequestCaseId !== null) {
                throw $this->validationError(
                    'leave_request_case_id',
                    'Keterkaitan rangkaian hanya berlaku untuk Cuti Melahirkan dan CLTN.',
                );
            }

            return [null, false];
        }

        if ($leaveRequestCaseId === null) {
            $this->assertWithinCalendarLimit($leaveType, $startDate, $endDate);

            return [
                LeaveRequestCase::create([
                    'employee_id' => $employee->id,
                    'jenis_cuti_id' => $leaveType->id,
                    'created_by' => $actor?->id,
                ]),
                true,
            ];
        }

        $leaveCase = LeaveRequestCase::query()
            ->whereKey($leaveRequestCaseId)
            ->lockForUpdate()
            ->first();

        if ($leaveCase === null) {
            throw $this->validationError('leave_request_case_id', 'Rangkaian pengajuan cuti yang dipilih tidak ditemukan.');
        }

        $this->assertCaseMatches($leaveCase, $employee, $leaveType);
        $this->assertCasePeriodAllowed($leaveCase, $leaveType, $startDate, $endDate);

        return [$leaveCase, false];
    }

    /**
     * Validasi ulang rangkaian pengajuan yang sama ketika pemohon mengirim
     * ulang perubahan tanggal. Rangkaian sengaja tidak dapat ditukar saat
     * resubmit agar jejak hubungan awalnya tetap auditabel.
     */
    public function assertResubmissionAllowed(
        LeaveRequest $leaveRequest,
        Employee $employee,
        Carbon $startDate,
        Carbon $endDate,
    ): void {
        $leaveRequest->loadMissing('jenisCuti');
        $leaveType = $leaveRequest->jenisCuti;

        if ($leaveType === null) {
            throw $this->validationError('jenis_cuti_id', 'Jenis cuti pengajuan tidak tersedia.');
        }

        $this->assertPnsOnlyType($employee, $leaveType);
        $this->assertSingleCalendarYear($startDate, $endDate);
        $this->assertCutiBesarServiceLength($employee, $leaveType, $startDate);
        $this->assertCutiBesarDuration($leaveType, $startDate, $endDate);

        if (! $this->requiresExplicitCase($leaveType)) {
            return;
        }

        if ($leaveRequest->leave_request_case_id === null) {
            // Baris legacy tanpa rangkaian tidak boleh lolos resubmit diam-diam;
            // migration Tahap 5 mengisi relasinya, dan error ini menjadi fail-closed
            // bila ada data yang belum dimigrasikan dengan benar.
            throw $this->validationError(
                'leave_request_case_id',
                'Rangkaian pengajuan cuti belum tersedia. Hubungi Admin Kepegawaian untuk memeriksa data pengajuan ini.',
            );
        }

        $leaveCase = LeaveRequestCase::query()
            ->whereKey($leaveRequest->leave_request_case_id)
            ->lockForUpdate()
            ->first();

        if ($leaveCase === null) {
            throw $this->validationError('leave_request_case_id', 'Rangkaian pengajuan cuti tidak ditemukan.');
        }

        $this->assertCaseMatches($leaveCase, $employee, $leaveType);
        $this->assertCasePeriodAllowed($leaveCase, $leaveType, $startDate, $endDate, $leaveRequest);
    }

    /**
     * Rangkaian yang dapat dipilih pemohon pada form. Hanya rangkaian miliknya
     * sendiri dengan pengajuan yang belum berstatus Tidak Disetujui yang tampil;
     * otorisasi dan kecocokan jenis tetap divalidasi ulang di server.
     *
     * @return Collection<int, LeaveRequestCase>
     */
    public function continuationCasesFor(Employee $employee): Collection
    {
        return LeaveRequestCase::query()
            ->where('employee_id', $employee->id)
            ->whereHas('jenisCuti', fn ($query) => $query->whereIn('code', self::CASE_TYPE_CODES))
            ->whereHas('leaveRequests', fn ($query) => $query->where('status', '!=', 'tidak_disetujui'))
            ->with([
                'jenisCuti:id,nama,code',
                'leaveRequests' => fn ($query) => $query
                    ->select(['id', 'leave_request_case_id', 'tanggal_mulai', 'tanggal_selesai', 'status'])
                    ->orderBy('tanggal_mulai'),
            ])
            ->latest('created_at')
            ->get();
    }

    public function requiresExplicitCase(RefJenisCuti $leaveType): bool
    {
        return in_array($leaveType->code, self::CASE_TYPE_CODES, true);
    }

    /**
     * Memvalidasi ulang fakta Cuti Besar yang sudah tersimpan tepat sebelum
     * persetujuan final agar perubahan data pegawai atau request tidak membypass aturan statutory.
     */
    public function assertCutiBesarCanBeFinallyApproved(LeaveRequest $leaveRequest, Employee $employee): void
    {
        $leaveRequest->loadMissing('jenisCuti');
        $leaveType = $leaveRequest->jenisCuti;

        if ($leaveType === null) {
            throw $this->validationError('jenis_cuti_id', 'Jenis cuti pengajuan tidak tersedia.');
        }

        if ($leaveType->code !== 'besar') {
            return;
        }

        $startDate = $leaveRequest->tanggal_mulai->copy()->startOfDay();
        $endDate = $leaveRequest->tanggal_selesai->copy()->startOfDay();

        $this->assertSingleCalendarYear($startDate, $endDate);
        $this->assertPnsOnlyType($employee->loadMissing('jenisPegawai'), $leaveType);
        $this->assertCutiBesarServiceLength($employee, $leaveType, $startDate);
        $this->assertCutiBesarDuration($leaveType, $startDate, $endDate);
    }

    private function assertPnsOnlyType(Employee $employee, RefJenisCuti $leaveType): void
    {
        if ($leaveType->khusus_pns && $employee->jenisPegawai?->nama !== 'PNS') {
            throw $this->validationError('jenis_cuti_id', 'Jenis cuti ini hanya dapat diajukan oleh pegawai berstatus PNS.');
        }
    }

    private function assertCutiBesarServiceLength(Employee $employee, RefJenisCuti $leaveType, Carbon $startDate): void
    {
        if ($leaveType->code !== 'besar') {
            return;
        }

        $tmt = $employee->appointments()
            ->whereNotNull('tmt_pengangkatan')
            ->orderBy('tmt_pengangkatan')
            ->value('tmt_pengangkatan');

        if ($tmt === null) {
            throw $this->validationError(
                'tanggal_mulai',
                'Data TMT pengangkatan pegawai belum tersedia sehingga syarat masa kerja Cuti Besar tidak dapat dihitung.',
            );
        }

        $eligibleFrom = Carbon::parse($tmt)->startOfDay()->addYearsNoOverflow(5);

        if ($startDate->lt($eligibleFrom)) {
            throw $this->validationError(
                'tanggal_mulai',
                "Cuti Besar hanya dapat diajukan setelah masa kerja minimal 5 tahun kalender sejak TMT pengangkatan ({$eligibleFrom->format('d-m-Y')}).",
            );
        }
    }

    /** Cuti Besar dibatasi tiga bulan kalender dari tanggal mulai yang telah tersimpan. */
    private function assertCutiBesarDuration(RefJenisCuti $leaveType, Carbon $startDate, Carbon $endDate): void
    {
        if ($leaveType->code !== 'besar') {
            return;
        }

        $latestAllowedDate = $startDate->copy()->addMonthsNoOverflow(3)->subDay();

        if (! $endDate->gt($latestAllowedDate)) {
            return;
        }

        throw $this->validationError(
            'tanggal_selesai',
            "Cuti Besar paling lama 3 bulan kalender. Batas akhir pengajuan ini adalah {$latestAllowedDate->format('d-m-Y')}.",
        );
    }

    /**
     * Menjaga satu pengajuan berada dalam satu tahun kalender pada FormRequest,
     * service, dan kelak Action langsung agar tidak ada jalur bypass lintas tahun.
     */
    public function assertSingleCalendarYear(CarbonInterface $startDate, CarbonInterface $endDate): void
    {
        if ($startDate->year === $endDate->year) {
            return;
        }

        throw $this->validationError(
            'tanggal_selesai',
            'Pengajuan cuti tidak boleh melewati tahun kalender. Pisahkan menjadi dua pengajuan terpisah untuk tiap tahun.',
        );
    }

    private function assertCaseMatches(LeaveRequestCase $leaveCase, Employee $employee, RefJenisCuti $leaveType): void
    {
        if ($leaveCase->employee_id !== $employee->id) {
            throw $this->validationError('leave_request_case_id', 'Rangkaian pengajuan cuti yang dipilih bukan milik pegawai ini.');
        }

        if ($leaveCase->jenis_cuti_id !== $leaveType->id) {
            throw $this->validationError('leave_request_case_id', 'Rangkaian pengajuan cuti tidak sesuai dengan jenis cuti yang dipilih.');
        }
    }

    private function assertCasePeriodAllowed(
        LeaveRequestCase $leaveCase,
        RefJenisCuti $leaveType,
        Carbon $startDate,
        Carbon $endDate,
        ?LeaveRequest $excludingLeaveRequest = null,
    ): void {
        $periods = $leaveCase->leaveRequests()
            ->when(
                $excludingLeaveRequest !== null,
                fn ($query) => $query->where('id', '!=', $excludingLeaveRequest->id),
            )
            ->where('status', '!=', 'tidak_disetujui')
            ->get(['tanggal_mulai', 'tanggal_selesai']);

        $earliestStart = $startDate->copy();
        $latestEnd = $endDate->copy();

        foreach ($periods as $period) {
            if ($period->tanggal_mulai->lt($earliestStart)) {
                $earliestStart = $period->tanggal_mulai->copy();
            }

            if ($period->tanggal_selesai->gt($latestEnd)) {
                $latestEnd = $period->tanggal_selesai->copy();
            }
        }

        $this->assertWithinCalendarLimit($leaveType, $earliestStart, $latestEnd);
    }

    /**
     * Batas disusun dengan aritmetika kalender dari tanggal awal rangkaian,
     * bukan konstanta 90 atau 1.095 hari. Seluruh potongan dalam satu rangkaian
     * diperiksa sebagai satu jendela kalender sehingga pemecahan Desember–Januari
     * tidak membuka kuota baru.
     */
    private function assertWithinCalendarLimit(RefJenisCuti $leaveType, Carbon $startDate, Carbon $endDate): void
    {
        $latestAllowedDate = match ($leaveType->code) {
            'melahirkan' => $startDate->copy()->addMonthsNoOverflow(3)->subDay(),
            'cltn' => $startDate->copy()->addYearsNoOverflow(3)->subDay(),
            default => null,
        };

        if ($latestAllowedDate === null || ! $endDate->gt($latestAllowedDate)) {
            return;
        }

        $label = $leaveType->code === 'melahirkan'
            ? 'Cuti Melahirkan dalam satu rangkaian paling lama 3 bulan kalender.'
            : 'CLTN dalam satu rangkaian paling lama 3 tahun kalender.';

        throw $this->validationError(
            'tanggal_selesai',
            "{$label} Batas akhir rangkaian ini adalah {$latestAllowedDate->format('d-m-Y')}.",
        );
    }

    private function validationError(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
