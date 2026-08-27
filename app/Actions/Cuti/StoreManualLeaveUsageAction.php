<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\AuditService;
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

final class StoreManualLeaveUsageAction
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
     * Mencatat fakta manual hanya setelah exact Admin guard, lookup pegawai, dan hitung hari kerja server.
     * Transaksi fail-closed; file UUID baru dikompensasi bila mutasi, replay, dokumen, atau audit gagal.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(
        string $employee,
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
            'approval_document_number' => ['nullable', 'string', 'max:255'],
            'approval_steps' => ['required', 'array', 'min:2', 'max:10'],
        ])->validate();
        $approvalSteps = $this->approvalChains->normalize($data['approval_steps'] ?? null);
        $employee = $this->resolveEmployee($employee);
        $administrativeNote = $this->text->required(
            (string) $validated['alasan'],
            'alasan',
            'Alasan pemakaian manual wajib diisi.',
        );
        $stored = null;

        try {
            $record = DB::transaction(function () use ($employee, $validated, $administrativeNote, $approvalSteps, $actor, $request, $document, &$stored): LeaveUsageRecord {
                $stored = $document instanceof UploadedFile
                    ? $this->documents->store($document, $employee->id)
                    : null;
                $lockedEmployee = $this->overlap->lockEmployee($employee);
                $leaveType = RefJenisCuti::query()->whereKey($validated['leave_type_id'])->firstOrFail();
                $start = Carbon::createFromFormat('Y-m-d', $validated['tanggal_mulai'])->startOfDay();
                $end = Carbon::createFromFormat('Y-m-d', $validated['tanggal_selesai'])->startOfDay();

                [$leaveCase, $caseCreated] = $this->eligibility->resolveForManualUsage(
                    $lockedEmployee,
                    $leaveType,
                    $start,
                    $end,
                    $validated['leave_request_case_id'] ?? null,
                    null,
                    true,
                    $actor,
                );

                if ($caseCreated && $leaveCase !== null) {
                    // Rangkaian baru menjadi bukti domain sebelum fakta pemakaian ditulis;
                    // kegagalan audit membatalkan transaksi dan file baru dikompensasi.
                    AuditService::logAsOrFail(
                        $actor->id,
                        $actor->name,
                        'CREATE',
                        'LeaveRequestCase',
                        $leaveCase->id,
                        null,
                        [
                            'employee_id' => $lockedEmployee->id,
                            'jenis_cuti_id' => $leaveType->id,
                            'jenis_cuti_code' => $leaveType->code,
                            'tanggal_mulai_rangkaian' => $start->toDateString(),
                        ],
                        $request,
                    );
                }

                $this->overlap->assertNoOverlap($lockedEmployee, $start, $end);
                $workdays = $this->workdays->calculate($start, $end);

                if ($workdays <= 0) {
                    throw ValidationException::withMessages([
                        'tanggal_selesai' => 'Rentang pemakaian wajib memiliki sedikitnya satu hari kerja.',
                    ]);
                }

                $record = $this->records->recordManual(
                    $lockedEmployee,
                    $leaveType,
                    $start->toDateString(),
                    $end->toDateString(),
                    $workdays,
                    $administrativeNote,
                    $leaveCase?->id,
                    $this->nullableString($validated['approval_document_number'] ?? null),
                    $approvalSteps,
                    $actor,
                    $stored === null ? [] : $this->documents->auditMetadata($stored),
                    $request,
                );
                if ($stored !== null) {
                    $this->documents->attachToUsage($record, $stored, $actor);
                }

                return $record->fresh('documents');
            });
        } catch (\Throwable $exception) {
            if ($stored !== null) {
                $this->documents->deleteNewFile($stored);
            }

            throw $exception;
        }

        if ($stored !== null) {
            // Adoption dilakukan setelah commit agar task PREPARED tetap memulihkan crash di antara write dan metadata.
            $this->documents->adoptNewDocument(
                $stored['recovery_task_id'],
                $employee->id,
                $stored['path'],
            );
        }

        return $record;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function resolveEmployee(string $employee): Employee
    {
        abort_unless(Str::isUuid($employee), 404);

        return Employee::query()->whereKey($employee)->firstOrFail();
    }
}
