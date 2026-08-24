<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\BuildsDocumentAuditPayload;
use App\Models\Document;
use App\Models\Employee;
use App\Services\AuditService;
use App\Services\Documents\EmployeeDocumentFileCleanupService;
use App\Services\TransactionSideEffectManager;
use App\Support\Documents\BerkasLainnyaMutationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeleteBerkasLainnyaAction
{
    use BuildsDocumentAuditPayload;

    public function __construct(
        private readonly TransactionSideEffectManager $sideEffects,
        private readonly BerkasLainnyaMutationGuard $guard,
        private readonly EmployeeDocumentFileCleanupService $fileCleanup,
    ) {}

    /**
     * Menghapus Berkas Lainnya standalone dengan audit fail-closed.
     *
     * File privat baru dihapus setelah transaksi terluar commit agar rollback
     * tidak menghasilkan metadata yang masih ada tetapi file fisiknya hilang.
     */
    public function execute(Employee $employee, Document $document, ?Request $request = null): void
    {
        $filePath = DB::transaction(function () use ($employee, $document, $request): string {
            /** @var Document $lockedDocument */
            $lockedDocument = Document::query()
                ->whereKey($document->id)
                ->where('employee_id', $employee->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->guard->assertCanMutate($employee, $lockedDocument);
            $filePath = $lockedDocument->file_path;

            AuditService::logOrFail(
                'DELETE',
                'Document',
                $lockedDocument->id,
                $this->auditPayload($lockedDocument),
                null,
                $request,
            );

            $lockedDocument->delete();

            return $filePath;
        });

        $deleteFile = function () use ($filePath): void {
            $this->fileCleanup->deleteOrScheduleRetry($filePath);
        };

        if (! $this->sideEffects->afterCommit($deleteFile)) {
            $deleteFile();
        }
    }
}
