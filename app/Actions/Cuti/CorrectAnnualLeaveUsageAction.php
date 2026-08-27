<?php

namespace App\Actions\Cuti;

use App\Models\LeaveUsageReconciliationSet;
use App\Models\User;
use App\Services\Cuti\AnnualLeaveBusinessClock;
use App\Services\Cuti\LeaveUsageAuthorizationService;
use App\Services\Cuti\LeaveUsageDocumentService;
use App\Services\Cuti\LeaveUsageReconciliationService;
use App\Services\Cuti\LeaveUsageTextNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CorrectAnnualLeaveUsageAction
{
    public function __construct(
        private readonly LeaveUsageAuthorizationService $authorization,
        private readonly LeaveUsageDocumentService $documents,
        private readonly LeaveUsageReconciliationService $reconciliations,
        private readonly LeaveUsageTextNormalizer $text,
        private readonly AnnualLeaveBusinessClock $businessClock,
    ) {}

    /**
     * Mengganti seluruh snapshot tiga tahun dan menyimpan satu bukti privat.
     * File baru dikompensasi bila versioning, replay, metadata, atau audit gagal.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(
        string $current,
        array $data,
        UploadedFile $document,
        User $actor,
        ?Request $request = null,
    ): LeaveUsageReconciliationSet {
        $this->authorization->assertCanReconcile($actor);
        $current = $this->resolveActiveSet($current);
        $validated = Validator::make($data, [
            'balance_year' => ['required', 'integer', 'between:1900,'.$this->businessClock->currentYear()],
            'usage_n2' => ['required', 'integer', 'min:0'],
            'usage_n1' => ['required', 'integer', 'min:0'],
            'usage_current' => ['required', 'integer', 'min:0'],
            'administrative_note' => ['required', 'string', 'max:2000'],
            'correction_reason' => ['required', 'string', 'max:2000'],
        ])->validate();

        if ((int) $validated['balance_year'] !== $current->balance_year) {
            throw ValidationException::withMessages([
                'balance_year' => 'Tahun saldo perbaikan harus sama dengan data aktif yang diganti.',
            ]);
        }

        $note = $this->text->required(
            (string) $validated['administrative_note'],
            'administrative_note',
            'Keterangan atau sumber data wajib diisi.',
        );
        $reason = $this->text->required(
            (string) $validated['correction_reason'],
            'correction_reason',
            'Alasan perbaikan wajib diisi.',
        );
        $stored = null;

        try {
            $replacement = DB::transaction(function () use ($current, $validated, $note, $reason, $document, $actor, $request, &$stored): LeaveUsageReconciliationSet {
                $stored = $this->documents->store($document, $current->employee_id);
                $balanceYear = $current->balance_year;
                $replacement = $this->reconciliations->replaceAnnualReconciliationSet(
                    $current,
                    [
                        $balanceYear - 2 => (int) $validated['usage_n2'],
                        $balanceYear - 1 => (int) $validated['usage_n1'],
                        $balanceYear => (int) $validated['usage_current'],
                    ],
                    $this->businessClock->now(),
                    $note,
                    $reason,
                    $actor,
                    $request,
                    $this->documents->auditMetadata($stored),
                );
                $this->documents->attachToReconciliation($replacement, $stored, $actor);

                return $replacement->fresh(['records', 'memberships', 'documents']);
            });
        } catch (\Throwable $exception) {
            $this->documents->deleteNewFile($stored);

            throw $exception;
        }

        /** @var array{recovery_task_id:string,path:string} $stored */
        // Dokumen replacement baru dianggap sah setelah set dan metadata append-only committed.
        $this->documents->adoptNewDocument(
            $stored['recovery_task_id'],
            $current->employee_id,
            $stored['path'],
        );

        return $replacement;
    }

    private function resolveActiveSet(string $current): LeaveUsageReconciliationSet
    {
        abort_unless(Str::isUuid($current), 404);

        return LeaveUsageReconciliationSet::query()
            ->whereKey($current)
            ->where('status', LeaveUsageReconciliationSet::STATUS_ACTIVE)
            ->firstOrFail();
    }
}
