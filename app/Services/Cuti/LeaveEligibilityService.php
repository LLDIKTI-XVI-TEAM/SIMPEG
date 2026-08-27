<?php

namespace App\Services\Cuti;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestCase;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
    public const FORM_OPTION_LIMIT = 100;

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
     * Menentukan rangkaian fakta eksternal tanpa menerapkan kelayakan pemohon normal.
     * Hanya pencatatan pertama yang boleh membuat rangkaian; koreksi wajib memilih
     * rangkaian existing agar perubahan jenis tidak membentuk hubungan diam-diam.
     *
     * @return array{0: LeaveRequestCase|null, 1: bool} [rangkaian, dibuatBaru]
     */
    public function resolveForManualUsage(
        Employee $employee,
        RefJenisCuti $leaveType,
        Carbon $startDate,
        Carbon $endDate,
        ?string $leaveRequestCaseId = null,
        ?LeaveUsageRecord $excludingManual = null,
        bool $allowCreate = false,
        ?User $actor = null,
    ): array {
        $this->assertSingleCalendarYear($startDate, $endDate);

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

            if (! $allowCreate) {
                throw $this->validationError(
                    'leave_request_case_id',
                    'Rangkaian pengajuan cuti wajib dipilih saat mengoreksi Cuti Melahirkan atau CLTN.',
                );
            }

            return [
                LeaveRequestCase::query()->create([
                    'employee_id' => $employee->id,
                    'jenis_cuti_id' => $leaveType->id,
                    'created_by' => $actor?->id,
                ]),
                true,
            ];
        }

        // Caller telah mengunci pegawai lebih dahulu; urutan ini mencegah inversi lock pegawai-rangkaian.
        $leaveCase = LeaveRequestCase::query()
            ->whereKey($leaveRequestCaseId)
            ->lockForUpdate()
            ->first();

        if ($leaveCase === null) {
            throw $this->validationError('leave_request_case_id', 'Rangkaian pengajuan cuti yang dipilih tidak ditemukan.');
        }

        $this->assertCaseMatches($leaveCase, $employee, $leaveType);
        $this->assertCasePeriodAllowed(
            $leaveCase,
            $leaveType,
            $startDate,
            $endDate,
            null,
            $excludingManual,
        );

        return [$leaveCase, false];
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
            // Resubmit tanpa rangkaian eksplisit ditolak agar periode terpisah tidak dapat
            // membuka kembali batas kalender Melahirkan atau CLTN.
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
     * sendiri yang memiliki pengajuan aktif atau fakta manual eksternal aktif
     * yang tampil; otorisasi dan kecocokan jenis tetap divalidasi ulang di server.
     *
     * @return Collection<int, LeaveRequestCase>
     */
    public function continuationCasesFor(Employee $employee, ?string $selectedCaseId = null): Collection
    {
        $query = LeaveRequestCase::query()
            ->where('employee_id', $employee->id)
            ->whereHas('jenisCuti', fn ($query) => $query->whereIn('code', self::CASE_TYPE_CODES))
            ->where(function ($query): void {
                $query->whereHas('leaveRequests', fn ($requestQuery) => $requestQuery
                    ->whereIn('status', LeaveUsageOverlapService::ACTIVE_REQUEST_STATUSES))
                    ->orWhereHas('leaveUsageRecords', fn ($usageQuery) => $usageQuery
                        ->where('source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)
                        ->where('record_status', LeaveUsageRecord::STATUS_ACTIVE));
            })
            ->select([
                'leave_request_cases.id',
                'leave_request_cases.employee_id',
                'leave_request_cases.jenis_cuti_id',
                'leave_request_cases.created_at',
            ])
            ->with([
                'jenisCuti:id,nama,code',
            ])
            // Periode diringkas oleh subquery agregat agar memori dan payload form
            // tidak bertambah mengikuti jumlah histori pada setiap rangkaian.
            ->withMin([
                'leaveRequests as request_period_start' => fn ($query) => $query
                    ->whereIn('status', LeaveUsageOverlapService::ACTIVE_REQUEST_STATUSES),
            ], 'tanggal_mulai')
            ->withMax([
                'leaveRequests as request_period_end' => fn ($query) => $query
                    ->whereIn('status', LeaveUsageOverlapService::ACTIVE_REQUEST_STATUSES),
            ], 'tanggal_selesai')
            ->withMin([
                'leaveUsageRecords as manual_period_start' => fn ($query) => $query
                    ->where('source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)
                    ->where('record_status', LeaveUsageRecord::STATUS_ACTIVE),
            ], 'start_date')
            ->withMax([
                'leaveUsageRecords as manual_period_end' => fn ($query) => $query
                    ->where('source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)
                    ->where('record_status', LeaveUsageRecord::STATUS_ACTIVE),
            ], 'end_date');

        // Nilai lama yang valid diprioritaskan supaya kegagalan validasi tidak
        // menghilangkan pilihan hanya karena rangkaian tersebut berada di luar halaman terbaru.
        if ($selectedCaseId !== null && Str::isUuid($selectedCaseId)) {
            $query->orderByRaw('CASE WHEN leave_request_cases.id = ? THEN 0 ELSE 1 END', [$selectedCaseId]);
        }

        return $query
            ->latest('leave_request_cases.created_at')
            // UUID menjadi tie-breaker agar batas opsi tidak bergeser ketika case tercatat pada detik yang sama.
            ->orderByDesc('leave_request_cases.id')
            ->limit(self::FORM_OPTION_LIMIT)
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
        ?LeaveUsageRecord $excludingManual = null,
    ): void {
        $requestBounds = $leaveCase->leaveRequests()
            ->when(
                $excludingLeaveRequest !== null,
                fn ($query) => $query->where('id', '!=', $excludingLeaveRequest->id),
            )
            ->whereIn('status', LeaveUsageOverlapService::ACTIVE_REQUEST_STATUSES)
            ->toBase()
            ->selectRaw('MIN(tanggal_mulai) AS earliest_start, MAX(tanggal_selesai) AS latest_end')
            ->first();
        $manualBounds = $leaveCase->leaveUsageRecords()
            ->where('source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)
            ->where('record_status', LeaveUsageRecord::STATUS_ACTIVE)
            ->when(
                $excludingManual !== null,
                fn ($query) => $query->where('id', '!=', $excludingManual->id),
            )
            ->toBase()
            ->selectRaw('MIN(start_date) AS earliest_start, MAX(end_date) AS latest_end')
            ->first();

        $earliestStart = $startDate->copy();
        $latestEnd = $endDate->copy();

        foreach ([$requestBounds, $manualBounds] as $bounds) {
            if ($bounds?->earliest_start !== null) {
                $candidateStart = Carbon::parse($bounds->earliest_start)->startOfDay();

                if ($candidateStart->lt($earliestStart)) {
                    $earliestStart = $candidateStart;
                }
            }

            if ($bounds?->latest_end !== null) {
                $candidateEnd = Carbon::parse($bounds->latest_end)->startOfDay();

                if ($candidateEnd->gt($latestEnd)) {
                    $latestEnd = $candidateEnd;
                }
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
