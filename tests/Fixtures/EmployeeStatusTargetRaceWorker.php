<?php

namespace Tests\Fixtures;

use App\Actions\Employees\ChangeEmployeeStatusAction;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class EmployeeStatusTargetRaceWorker
{
    /** @param array<string, string> $input */
    public function run(array $input): void
    {
        DB::statement("SET lock_timeout TO '15s'");
        file_put_contents($input['ready'], (string) DB::scalar('select pg_backend_pid()'), LOCK_EX);

        try {
            $actor = User::query()->findOrFail($input['actor_id']);
            $employee = Employee::query()->findOrFail($input['employee_id']);
            Auth::login($actor);

            $request = Request::create('/uji-race-target-status', 'POST', [], [], [], [
                'REMOTE_ADDR' => '10.27.0.7',
                'HTTP_USER_AGENT' => 'SIMPEG-Target-Race/1.0',
            ]);
            $request->setUserResolver(static fn (): User => $actor);
            $file = new UploadedFile(
                $input['file'],
                'sk-target-race.pdf',
                'application/pdf',
                null,
                true,
            );

            app(ChangeEmployeeStatusAction::class)->execute($employee, [
                'status_pegawai_id' => $input['status_id'],
                'tanggal' => $input['tanggal'],
                'keterangan' => 'Uji klasifikasi target status terkunci.',
            ], $request, $file);

            $result = ['ok' => true];
        } catch (Throwable $exception) {
            $result = [
                'ok' => false,
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'errors' => $exception instanceof ValidationException ? $exception->errors() : null,
            ];
        } finally {
            Auth::logout();
        }

        file_put_contents($input['result'], json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
    (new EmployeeStatusTargetRaceWorker)->run($input);
}
