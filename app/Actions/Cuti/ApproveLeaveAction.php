<?php

namespace App\Actions\Cuti;

use App\Actions\Cuti\Concerns\BuildsLeaveDecisionAuditPayload;
use App\Exceptions\LeaveProofGenerationException;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Services\LeaveApprovalService;
use App\Services\NotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Mengoordinasikan tindakan menyetujui pengajuan cuti.
 * Logika transisi snapshot dan pemotongan saldo berada di LeaveApprovalService.
 * Action ini menangani orkestrasi tepian: pencatatan audit dan notifikasi pihak terkait setelah transisi.
 */
class ApproveLeaveAction
{
    use BuildsLeaveDecisionAuditPayload;

    public function __construct(
        private readonly LeaveApprovalService $approvals,
        private readonly NotificationService $notifications,
        private readonly GenerateLeaveProofAction $proofs,
    ) {}

    /**
     * Menyetujui pengajuan dengan aktor manusia eksplisit dan transaksi fail-closed.
     * File PDF baru dikompensasi bila transaksi database atau notifikasi gagal.
     */
    public function execute(LeaveRequest $leaveRequest, Employee $actor, string $expectedActiveStepId, ?string $komentar, Request $request): LeaveRequest
    {
        $requestUser = $request->user();

        if (! $requestUser instanceof User || $requestUser->employee_id !== $actor->id) {
            throw new AuthorizationException('Akun Anda tidak cocok dengan approver yang berwenang untuk tahap persetujuan ini.');
        }

        $newDocumentPath = null;
        $newDocumentRecoveryTaskId = null;

        try {
            $approved = DB::transaction(function () use (
                $leaveRequest,
                $actor,
                $komentar,
                $request,
                $requestUser,
                $expectedActiveStepId,
                &$newDocumentPath,
                &$newDocumentRecoveryTaskId,
            ): LeaveRequest {
                // State audit dibaca setelah request terkunci agar model stale atau keputusan paralel
                // tidak dapat menulis status sebelum yang berbeda dari keadaan transaksi.
                $lockedBefore = LeaveRequest::query()
                    ->whereKey($leaveRequest->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $statusSebelum = $lockedBefore->status;
                $stepSebelum = $lockedBefore->steps()
                    ->where('status', 'active')
                    ->where('approver_employee_id', $actor->id)
                    ->orderBy('step_order')
                    ->lockForUpdate()
                    ->first();
                $leaveRequest = $this->approvals->approve(
                    $lockedBefore,
                    $actor,
                    $expectedActiveStepId,
                    $komentar,
                    $requestUser,
                    $request,
                );

                if ($leaveRequest->status === 'disetujui') {
                    $proofResult = $this->proofs->execute($leaveRequest, $requestUser);
                    $newDocumentPath = $proofResult['new_document_path'];
                    $newDocumentRecoveryTaskId = $proofResult['recovery_task_id'];
                }

                // Notifikasi in-app tetap berada di transaksi; job email memakai afterCommit
                // sehingga rollback tidak pernah mengirim keputusan yang belum sah.
                // ID approval dan versi digunakan dispatcher untuk menolak intent yang stale
                // bila pengajuan berubah lagi sebelum job WhatsApp dieksekusi.
                $approvalId = $leaveRequest->getRelation('lastRecordedApproval')?->id;
                $this->notifyAfterApproval($leaveRequest, $approvalId);

                // Audit keputusan ditulis paling akhir agar kegagalannya membatalkan status,
                // reservasi, fakta, replay, bukti metadata, dan notifikasi sebagai satu unit.
                $event = $leaveRequest->status === 'disetujui' ? 'DECIDE' : 'VERIFY';
                $auditPayload = $this->decisionAuditPayload($statusSebelum, $leaveRequest, $stepSebelum, $actor, $event, $komentar);
                $auditPayload['new']['actor_role'] = $requestUser->role;

                AuditService::logAsOrFail(
                    $requestUser->id,
                    (string) $requestUser->name,
                    $event,
                    'LeaveRequest',
                    $leaveRequest->id,
                    $auditPayload['old'],
                    $auditPayload['new'],
                    $request,
                );

                return $leaveRequest;
            });
        } catch (Throwable $exception) {
            $primaryException = $exception;
            if ($exception instanceof LeaveProofGenerationException) {
                $newDocumentPath = $exception->cleanupPath;
                $primaryException = $exception->getPrevious() ?? $exception;
            }
            $this->proofs->deleteNewDocument($leaveRequest->id, $newDocumentPath);

            throw $primaryException;
        }

        if (is_string($newDocumentRecoveryTaskId) && is_string($newDocumentPath)) {
            // Pada request normal transaksi approval sudah committed. Bila masih ada transaksi luar,
            // adoption ikut commit atau rollback metadata sehingga intent aman tetap PREPARED saat batal.
            $this->proofs->adoptNewDocument(
                $newDocumentRecoveryTaskId,
                $leaveRequest->id,
                $newDocumentPath,
            );
        }

        return $approved;
    }

    /**
     * Memberi tahu pihak yang relevan setelah persetujuan.
     * Bila masih ada tahap menunggu, approver tahap berikutnya diberi tahu; bila sudah final,
     * pemohon diberi tahu bahwa cutinya disetujui.
     */
    private function notifyAfterApproval(LeaveRequest $leaveRequest, ?string $approvalId = null): void
    {
        if ($leaveRequest->status === 'disetujui') {
            $pemohon = $leaveRequest->employee;

            if ($pemohon !== null) {
                $this->notifications->createForEmployee(
                    $pemohon,
                    'cuti.disetujui',
                    'Pengajuan Cuti Disetujui',
                    'Pengajuan cuti Anda telah disetujui sepenuhnya.',
                    // Pemohon diarahkan ke detail pengajuannya; path relatif internal agar link aman lintas host.
                    [
                        'leave_request_id' => $leaveRequest->id,
                        'leave_approval_id' => $approvalId,
                        'url' => route('cuti.show', ['id' => $leaveRequest->id], false),
                    ],
                );
            }

            return;
        }

        $nextStep = $leaveRequest->steps()
            ->where('status', 'active')
            ->orderBy('step_order')
            ->first();

        $approver = $nextStep?->approver_employee_id === null
            ? null
            : Employee::find($nextStep->approver_employee_id);

        if ($approver === null) {
            return;
        }

        $this->notifications->createForEmployee(
            $approver,
            'cuti.menunggu_persetujuan',
            'Pengajuan Cuti Menunggu Persetujuan',
            'Terdapat pengajuan cuti yang menunggu persetujuan Anda.',
            // Approver tahap berikutnya diarahkan ke antrean approval; path relatif internal agar link aman lintas host.
            [
                'leave_request_id' => $leaveRequest->id,
                'leave_request_step_id' => $nextStep->id,
                'leave_request_version' => $leaveRequest->updated_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'url' => route('cuti.approval', [], false),
            ],
        );
    }
}
