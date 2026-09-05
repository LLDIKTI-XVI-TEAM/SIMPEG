<?php

namespace Tests\Fixtures;

use App\Actions\Cuti\DecideLeaveCancellationAction;
use App\Actions\Cuti\RolloverLeaveBalanceAction;
use App\Actions\Cuti\SubmitLeaveRequestAction;
use App\Models\Employee;
use App\Models\LeaveCancellationRequest;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class LeaveBalanceRolloverRaceWorker
{
    /** @param array<string, string> $input */
    public function run(array $input): void
    {
        DB::statement("SELECT set_config('application_name', ?, false)", [$input['application_name']]);
        config()->set('queue.default', 'database');
        Carbon::setTestNow($input['now']);
        file_put_contents($input['ready'], 'ready');

        while (! is_file($input['barrier'])) {
            usleep(10_000);
        }

        /** @var array<string, mixed> $result */
        $result = [
            'ok' => false,
            'operation' => $input['operation'],
            'class' => RuntimeException::class,
            'message' => 'Worker rollover berhenti sebelum menghasilkan hasil.',
            'errors' => null,
        ];

        try {
            if ($input['operation'] === 'rollover') {
                $summary = app(RolloverLeaveBalanceAction::class)->execute((int) $input['source_year']);
                $result = ['ok' => true, 'operation' => 'rollover', 'summary' => $summary];
            } elseif ($input['operation'] === 'reject_cancellation') {
                $actor = User::query()->findOrFail($input['actor_user_id']);
                $cancellation = LeaveCancellationRequest::query()->findOrFail($input['cancellation_id']);
                $request = Request::create('/cuti/pembatalan/'.$cancellation->id.'/keputusan', 'PATCH');
                $request->setUserResolver(fn (): User => $actor);
                file_put_contents($input['result'].'.started', 'started');
                $decided = app(DecideLeaveCancellationAction::class)->execute(
                    $cancellation,
                    $actor,
                    'DITOLAK',
                    $request,
                );
                $result = [
                    'ok' => true,
                    'operation' => 'reject_cancellation',
                    'cancellation_id' => $decided->id,
                    'status' => $decided->status,
                ];
            } elseif ($input['operation'] === 'status') {
                DB::transaction(function () use ($input): void {
                    // Writer lifecycle mengunci pegawai sebelum mengganti klasifikasi status.
                    $employee = Employee::query()
                        ->whereKey($input['target_employee_id'])
                        ->lockForUpdate()
                        ->firstOrFail();
                    $employee->forceFill(['status_pegawai_id' => $input['status_id']])->saveOrFail();
                });
                $result = ['ok' => true, 'operation' => 'status'];
            } else {
                $employee = Employee::query()->findOrFail($input['employee_id']);
                $actor = User::query()->findOrFail($input['actor_user_id']);
                $request = Request::create('/cuti', 'POST');
                $request->setUserResolver(fn (): User => $actor);
                $leaveRequest = app(SubmitLeaveRequestAction::class)->execute($employee, [
                    'jenis_cuti_id' => $input['leave_type_id'],
                    'tanggal_mulai' => '2026-09-07',
                    'tanggal_selesai' => '2026-09-09',
                    'alasan' => 'Pengajuan yang bersaing dengan rollover.',
                    'alamat_selama_cuti' => 'Alamat pengujian rollover.',
                    'nomor_telepon' => '081234567890',
                ], $request);
                $result = [
                    'ok' => true,
                    'operation' => 'submit',
                    'request_id' => $leaveRequest->id,
                    'status' => $leaveRequest->status,
                ];
            }
        } catch (Throwable $exception) {
            $result = [
                'ok' => false,
                'operation' => $input['operation'],
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'errors' => $exception instanceof ValidationException ? $exception->errors() : null,
            ];
        } finally {
            Carbon::setTestNow();
            file_put_contents($input['result'], json_encode($result, JSON_THROW_ON_ERROR));
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
    (new LeaveBalanceRolloverRaceWorker)->run($input);
}
