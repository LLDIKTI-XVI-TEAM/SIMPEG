<?php

namespace App\Actions\Cuti;

use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\Cuti\LeaveEligibilityService;
use App\Services\Cuti\LeaveUsageAuthorizationService;
use App\Services\Cuti\LeaveUsageDocumentService;
use App\Services\Cuti\LeaveUsageOverlapService;
use App\Services\Cuti\LeaveUsageRecordService;
use App\Services\Cuti\LeaveUsageTextNormalizer;
use App\Services\Cuti\ManualExternalApprovalChainService;
use App\Services\WorkdayCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CorrectManualLeaveUsageAction
{
    public function __construct(
        private readonly LeaveUsageAuthorizationService $authorization,
        private readonly LeaveUsageDocumentService $documents,
        private readonly LeaveUsageOverlapService $overlap,
        private readonly LeaveEligibilityService $eligibility,
        private readonly WorkdayCalculator $workdays,
        private readonly LeaveUsageRecordService $records,
        private readonly LeaveUsageTextNormalizer $text,
        private readonly ManualExternalApprovalChainService $approvalChains,
    ) {}

    /**
     * Membuat versi pengganti setelah permission dan scope pemilik diperiksa, lalu menghitung hari kerja server.
     * Versi lama serta file historis dipertahankan; file koreksi baru dihapus bila transaksi gagal.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(
        string $current,
        array $data,
        ?UploadedFile $document,
        User $actor,
        ?Request $request = null,
    ): LeaveUsageRecord {
        $this->authorization->assertCanManageManual($actor);
        $validated = Validator::make($data, [
            'leave_type_id' => ['bail', 'required', 'uuid', 'exists:ref_jenis_cuti,id'],
            'leave_request_case_id' => ['bail', 'nullable', 'uuid', 'exists:leave_request_cases,id'],
            'tanggal_mulai' => ['required', 'date_format:Y-m-d'],
            'tanggal_selesai' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_mulai'],
            'alasan' => ['required', 'string', 'max:2000'],
            'correction_reason' => ['required', 'string', 'max:2000'],
            'approval_document_number' => ['nullable', 'string', 'max:255'],
            'approval_steps' => ['required', 'array', 'min:2', 'max:10'],
        ])->validate();
        $approvalSteps = $this->approvalChains->normalize($data['approval_steps'] ?? null);
        $current = $this->resolveManualUsage($current, $actor);
        $administrativeNote = $this->text->required(
            (string) $validated['alasan'],
            'alasan',
            'Alasan pemakaian manual wajib diisi.',
        );
        $correctionReason = $this->text->required(
            (string) $validated['correction_reason'],
            'correction_reason',
            'Alasan koreksi wajib diisi.',
        );
        $stored = null;

        try {
            $replacement = DB::transaction(function () use ($current, $validated, $administrativeNote, $correctionReason, $approvalSteps, $actor, $request, $document, &$stored): LeaveUsageRecord {
                $stored = $document instanceof UploadedFile
                    ? $this->documents->store($document, $current->employee_id)
                    : null;
                $employee = $this->overlap->lockEmployee($current->employee_id);
                $lockedCurrent = LeaveUsageRecord::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();

                if ($lockedCurrent->source_type !== LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL
                    || $lockedCurrent->record_status !== LeaveUsageRecord::STATUS_ACTIVE) {
                    throw ValidationException::withMessages(['usage_record' => 'Hanya fakta manual aktif yang dapat dikoreksi.']);
                }

                $leaveType = RefJenisCuti::query()->whereKey($validated['leave_type_id'])->firstOrFail();
                $start = Carbon::createFromFormat('Y-m-d', $validated['tanggal_mulai'])->startOfDay();
                $end = Carbon::createFromFormat('Y-m-d', $validated['tanggal_selesai'])->startOfDay();
                [$leaveCase] = $this->eligibility->resolveForManualUsage(
                    $employee,
                    $leaveType,
                    $start,
                    $end,
                    $validated['leave_request_case_id'] ?? null,
                    $lockedCurrent,
                );
                $this->overlap->assertNoOverlap($employee, $start, $end, $lockedCurrent->id);
                $workdays = $this->workdays->calculate($start, $end);

                if ($workdays <= 0) {
                    throw ValidationException::withMessages([
                        'tanggal_selesai' => 'Rentang pemakaian wajib memiliki sedikitnya satu hari kerja.',
                    ]);
                }

                $replacement = $this->records->replace(
                    $lockedCurrent,
                    [
                        'leave_type_id' => $leaveType->id,
                        'leave_request_case_id' => $leaveCase?->id,
                        'usage_year' => $start->year,
                        'effective_date' => $start->toDateString(),
                        'start_date' => $start->toDateString(),
                        'end_date' => $end->toDateString(),
                        'workdays' => $workdays,
                        'administrative_note' => $administrativeNote,
                        'approval_document_number' => $this->nullableString($validated['approval_document_number'] ?? null),
                    ],
                    $correctionReason,
                    $actor,
                    $approvalSteps,
                    $request,
                    [
                        'operation' => 'manual_usage_corrected',
                        'document' => $stored === null ? [] : $this->documents->auditMetadata($stored),
                    ],
                );
                if ($stored !== null) {
                    $this->documents->attachToUsage($replacement, $stored, $actor);
                }

                return $replacement->fresh('documents');
            });
        } catch (\Throwable $exception) {
            if ($stored !== null) {
                $this->documents->deleteNewFile($stored);
            }

            throw $exception;
        }

        if ($stored !== null) {
            // Metadata pengganti harus committed sebelum intent file dinyatakan adopted.
            $this->documents->adoptNewDocument(
                $stored['recovery_task_id'],
                $current->employee_id,
                $stored['path'],
            );
        }

        return $replacement;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function resolveManualUsage(string $current, User $actor): LeaveUsageRecord
    {
        abort_unless(Str::isUuid($current), 404);

        return LeaveUsageRecord::query()
            ->whereKey($current)
            ->whereIn('employee_id', $this->authorization->employeeScope($actor)->select('employees.id'))
            ->where('source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)
            ->firstOrFail();
    }
}
