<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\BuildsDocumentAuditPayload;
use App\Models\Document;
use App\Models\Employee;
use App\Services\AuditService;
use App\Services\TransactionSideEffectManager;
use App\Support\Documents\BerkasLainnyaMutationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteBerkasLainnyaAction
{
    use BuildsDocumentAuditPayload;

    public function __construct(
        private readonly TransactionSideEffectManager $sideEffects,
        private readonly BerkasLainnyaMutationGuard $guard,
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
            if (! $this->guard->fileIsStillReferenced($filePath)) {
                Storage::disk(Document::STORAGE_DISK)->delete($filePath);
            }
        };

        if (! $this->sideEffects->afterCommit($deleteFile)) {
            $deleteFile();
        }
    }
}
