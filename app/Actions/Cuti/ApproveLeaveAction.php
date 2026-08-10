<?php

namespace App\Actions\Cuti;

use App\Actions\Cuti\Concerns\BuildsLeaveDecisionAuditPayload;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Services\LeaveApprovalService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mengoordinasikan tindakan menyetujui pengajuan cuti.
 * Logika transisi snapshot, skip duplikat, dan pemotongan saldo berada di LeaveApprovalService.
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
     * Menyetujui pengajuan cuti atas nama approver yang bertindak.
     */
    public function execute(LeaveRequest $leaveRequest, Employee $actor, ?string $komentar, Request $request): LeaveRequest
    {
        $statusSebelum = $leaveRequest->status;
        $stepSebelum = $leaveRequest->steps()
            ->where('status', 'active')
            ->where('approver_employee_id', $actor->id)
            ->first();

        // Aktor manusia dipisahkan dari approver Employee: user menjadi jejak akun penerbit bukti final,
        // sedangkan otorisasi step tetap berbasis employee. Request::user() dapat mengembalikan
        // Authenticatable|null, jadi dipersempit lewat instanceof alih-alih cast tak aman.
        $requestUser = $request->user();
        $actingUser = $requestUser instanceof User ? $requestUser : null;

        // Persetujuan dan jejaknya disatukan dalam satu transaksi supaya pengajuan tidak pernah
        // berpindah tahap tanpa baris audit. Penerbitan bukti dan notifikasi tetap di luar transaksi
        // agar kegagalannya tidak membatalkan persetujuan yang sah.
        $leaveRequest = DB::transaction(function () use ($leaveRequest, $actor, $komentar, $request, $actingUser, $statusSebelum, $stepSebelum): LeaveRequest {
            $leaveRequest = $this->approvals->approve($leaveRequest, $actor, $komentar, $actingUser);

            // Persetujuan tahap menengah hanya meneruskan berkas, sedangkan tahap akhir menutup pengajuan.
            // Keduanya dipisahkan agar penyaringan audit dapat membedakan verifikasi dari keputusan resmi.
            $event = $leaveRequest->status === 'disetujui' ? 'DECIDE' : 'VERIFY';
            $auditPayload = $this->decisionAuditPayload($statusSebelum, $leaveRequest, $stepSebelum, $actor, $event, $komentar);

            AuditService::logOrFail(
                $event,
                'LeaveRequest',
                $leaveRequest->id,
                $auditPayload['old'],
                $auditPayload['new'],
                $request,
            );

            return $leaveRequest;
        });

        $this->notifyAfterApproval($leaveRequest);

        if ($leaveRequest->status === 'disetujui') {
            $this->proofs->execute($leaveRequest, $request->user() instanceof User ? $request->user() : null);
        }

        return $leaveRequest;
    }

    /**
     * Memberi tahu pihak yang relevan setelah persetujuan.
     * Bila masih ada tahap menunggu, approver tahap berikutnya diberi tahu; bila sudah final,
     * pemohon diberi tahu bahwa cutinya disetujui.
     */
    private function notifyAfterApproval(LeaveRequest $leaveRequest): void
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
                    ['leave_request_id' => $leaveRequest->id, 'url' => route('cuti.show', ['id' => $leaveRequest->id], false)],
                );
            }

            return;
        }

        $stage = $this->approvals->pendingStage($leaveRequest);

        if ($stage === null) {
            return;
        }

        $approverId = $this->approvals->approverEmployeeIdForStage($leaveRequest, $stage);

        if ($approverId === null) {
            return;
        }

        $approver = Employee::find($approverId);

        if ($approver === null) {
            return;
        }

        $this->notifications->createForEmployee(
            $approver,
            'cuti.menunggu_persetujuan',
            'Pengajuan Cuti Menunggu Persetujuan',
            'Terdapat pengajuan cuti yang menunggu persetujuan Anda.',
            // Approver tahap berikutnya diarahkan ke antrean approval; path relatif internal agar link aman lintas host.
            ['leave_request_id' => $leaveRequest->id, 'url' => route('cuti.approval', [], false)],
        );
    }
}
