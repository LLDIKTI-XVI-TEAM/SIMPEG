<?php

namespace Tests\Fixtures;

use App\Actions\Cuti\ApproveLeaveAction;
use App\Actions\Cuti\RecordDutyPostponementAction;
use App\Models\Employee;
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

            if ($operation === 'duty_postponement') {
                $terminal = app(RecordDutyPostponementAction::class)->execute(
                    $leaveRequest,
                    $approver,
                    $actor,
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
                    'Persetujuan final race.',
                    $request,
                );
                $proof = LeaveProof::query()->where('leave_request_id', $approved->id)->sole();
                $fact = LeaveUsageRecord::query()->where('leave_request_id', $approved->id)->sole();
                $result = [
                    'ok' => true,
                    'operation' => $operation,
                    'request_id' => $approved->id,
                    'status' => $approved->status,
                    'proof_id' => $proof->id,
                    'proof_path' => $proof->document_path,
                    'fact_id' => $fact->id,
                ];
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
