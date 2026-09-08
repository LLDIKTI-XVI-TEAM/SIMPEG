<?php

namespace App\Actions\Cuti;

use App\Exceptions\LeaveProofGenerationException;
use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Cuti\LeaveProofDocumentStorageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class GenerateLeaveProofAction
{
    public function __construct(
        private readonly DownloadOfficialLeavePdfAction $officialPdf,
        private readonly LeaveProofDocumentStorageService $documents,
    ) {}

    /**
     * Menulis PDF final ke storage privat dengan nama UUID tanpa menimpa bukti lama.
     * Path baru dikembalikan agar orkestrator dapat mengompensasinya bila transaksi gagal belakangan.
     *
     * @return array{proof: LeaveProof, new_document_path: string|null, recovery_task_id: string|null}
     */
    public function execute(LeaveRequest $leaveRequest, User $generatedBy): array
    {
        // Model pemanggil bisa usang; keputusan administratif tersimpan tidak boleh menerbitkan PDF pengganti.
        if (LeaveRequest::query()->whereKey($leaveRequest->id)->value('status') !== 'disetujui') {
            throw ValidationException::withMessages([
                'status' => 'Bukti cuti hanya dapat diterbitkan untuk pengajuan yang sudah disetujui final.',
            ]);
        }

        $proof = LeaveProof::query()->where('leave_request_id', $leaveRequest->id)->firstOrFail();

        if ($proof->generated_by !== $generatedBy->id) {
            throw new RuntimeException('Penerbit dokumen bukti tidak sesuai aktor persetujuan final.');
        }

        $leaveRequest->setRelation('proof', $proof);

        if ($proof->document_path !== null) {
            $this->documents->assertValidExistingDocument(
                $leaveRequest->id,
                $proof->document_path,
                $proof->document_mime,
            );

            return ['proof' => $proof, 'new_document_path' => null, 'recovery_task_id' => null];
        }

        $path = $this->documents->newPath($leaveRequest->id);

        try {
            $contents = Pdf::loadView('admin.cuti.pdf.formulir-cuti', $this->officialPdf->viewData($leaveRequest))
                ->setPaper([0, 0, 612, 1008], 'portrait')
                ->output();
            $recoveryTask = $this->documents->prepareNewDocument($leaveRequest->id, $path, $contents);
            $this->documents->putPdf($leaveRequest->id, $path, $contents);

            $proof->forceFill([
                'document_path' => $path,
                'document_mime' => LeaveProofDocumentStorageService::MIME,
            ])->save();
        } catch (Throwable $exception) {
            if (DB::transactionLevel() === 0) {
                $this->deleteNewDocument($leaveRequest->id, $path);

                throw $exception;
            }

            // Cleanup wajib dijadwalkan sesudah transaksi pemanggil rollback agar manifest tidak ikut hilang.
            throw new LeaveProofGenerationException($path, $exception);
        }

        $result = [
            'proof' => $proof->fresh(),
            'new_document_path' => $path,
            'recovery_task_id' => $recoveryTask->id,
        ];

        if (DB::transactionLevel() === 0) {
            $this->adoptNewDocument($recoveryTask->id, $leaveRequest->id, $path);
        }

        return $result;
    }

    /**
     * Menghapus hanya file UUID yang baru dibuat oleh boundary ini; path lain dan bukti historis
     * tidak pernah menjadi target kompensasi walaupun caller mengirim nilai yang salah.
     */
    public function deleteNewDocument(string $leaveRequestId, ?string $path): void
    {
        $this->documents->deleteNewDocument($leaveRequestId, $path);
    }

    /** Menyelesaikan manifest setelah transaksi pemanggil committed; retry recovery menjadi fallback. */
    public function adoptNewDocument(string $taskId, string $leaveRequestId, string $path): bool
    {
        return $this->documents->adoptNewDocument($taskId, $leaveRequestId, $path);
    }
}
