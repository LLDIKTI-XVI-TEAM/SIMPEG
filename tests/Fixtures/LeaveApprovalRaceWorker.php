<?php

namespace Tests\Fixtures;

use App\Actions\Cuti\ApproveLeaveAction;
use App\Actions\Cuti\DecideLeaveCancellationAction;
use App\Actions\Cuti\RecordDutyPostponementAction;
use App\Actions\Cuti\RequestLeaveCancellationAction;
use App\Actions\Cuti\ResubmitLeaveRequestAction;
use App\Models\Employee;
use App\Models\LeaveCancellationRequest;
use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class LeaveApprovalRaceWorker
{
    /** @param array<string, string> $input */
    public function run(array $input): void
    {
        config()->set('filesystems.disks.local.root', $input['storage_root']);
        Storage::forgetDisk('local');
        $leaveRequest = LeaveRequest::query()->findOrFail($input['leave_request_id']);
        $approver = Employee::query()->findOrFail($input['approver_employee_id']);
        $actor = User::query()->findOrFail($input['actor_user_id']);
        file_put_contents($input['ready'], 'ready');

        while (! is_file($input['barrier'])) {
            usleep(10_000);
        }

        $result = null;

        try {
            $request = Request::create('/cuti/approval', 'POST', [], [], [], [
                'REMOTE_ADDR' => '198.51.100.55',
                'HTTP_USER_AGENT' => 'SIMPEG-Approval-Race-Worker/1.0',
            ]);
            $request->setUserResolver(fn (): User => $actor);
            $operation = $input['operation'] ?? 'approve';

            if ($operation === 'request_cancellation') {
                $cancellation = app(RequestLeaveCancellationAction::class)->execute(
                    $leaveRequest,
                    $actor,
                    'Permohonan pembatalan pada race.',
                    $request,
                );
                $result = [
                    'ok' => true,
                    'operation' => $operation,
                    'request_id' => $leaveRequest->id,
                    'cancellation_id' => $cancellation->id,
                    'status' => $cancellation->status,
                ];
            } elseif (in_array($operation, ['approve_cancellation', 'reject_cancellation'], true)) {
                $cancellation = LeaveCancellationRequest::query()->findOrFail($input['cancellation_id']);
                $decided = app(DecideLeaveCancellationAction::class)->execute(
                    $cancellation,
                    $actor,
                    $operation === 'approve_cancellation' ? 'DISETUJUI' : 'DITOLAK',
                    $request,
                );
                $result = [
                    'ok' => true,
                    'operation' => $operation,
                    'request_id' => $leaveRequest->id,
                    'cancellation_id' => $decided->id,
                    'status' => $decided->status,
                ];
            } elseif ($operation === 'revise') {
                $revised = app(ResubmitLeaveRequestAction::class)->execute(
                    $leaveRequest,
                    [
                        'tanggal_mulai' => '2026-08-21',
                        'tanggal_selesai' => '2026-08-21',
                        'alasan' => 'Pengajuan diperbarui saat race.',
                        'alamat_selama_cuti' => 'Alamat hasil revisi race.',
                        'nomor_telepon' => '081234000099',
                        'revision_version' => (int) $input['revision_version'],
                    ],
                    $request,
                );
                $result = [
                    'ok' => true,
                    'operation' => $operation,
                    'request_id' => $revised->id,
                    'status' => $revised->status,
                    'revision_version' => $revised->revision_version,
                ];
            } elseif ($operation === 'duty_postponement') {
                $terminal = app(RecordDutyPostponementAction::class)->execute(
                    $leaveRequest,
                    $approver,
                    $actor,
                    $input['active_step_id'],
                    (int) $input['revision_version'],
                    'Penangguhan tugas dinas race.',
                );
                $result = [
                    'ok' => true,
                    'operation' => $operation,
                    'request_id' => $terminal->id,
                    'status' => $terminal->status,
                ];
            } else {
                $approved = app(ApproveLeaveAction::class)->execute(
                    $leaveRequest,
                    $approver,
                    $input['active_step_id'],
                    (int) $input['revision_version'],
                    'Persetujuan final race.',
                    $request,
                );
                $result = [
                    'ok' => true,
                    'operation' => $operation,
                    'request_id' => $approved->id,
                    'status' => $approved->status,
                ];

                if ($approved->status === 'disetujui') {
                    $proof = LeaveProof::query()->where('leave_request_id', $approved->id)->sole();
                    $fact = LeaveUsageRecord::query()->where('leave_request_id', $approved->id)->sole();
                    $result['proof_id'] = $proof->id;
                    $result['proof_path'] = $proof->document_path;
                    $result['fact_id'] = $fact->id;
                }
            }
        } catch (Throwable $exception) {
            $result = [
                'ok' => false,
                'operation' => $input['operation'] ?? 'approve',
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'errors' => $exception instanceof ValidationException ? $exception->errors() : null,
            ];
        } finally {
            if ($result === null) {
                throw new \LogicException('Worker race approval tidak menghasilkan keluaran.');
            }

            file_put_contents($input['result'], json_encode($result, JSON_THROW_ON_ERROR));
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
    (new LeaveApprovalRaceWorker)->run($input);
}
