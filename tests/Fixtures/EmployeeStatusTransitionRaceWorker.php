<?php

namespace Tests\Fixtures;

use App\Actions\Employees\ChangeEmployeeStatusAction;
use App\Models\Employee;
use App\Models\RefStatusPegawai;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class EmployeeStatusTransitionRaceWorker
{
    /** @param array<string, string> $input */
    public function run(array $input): void
    {
        config()->set('filesystems.disks.employee_documents.root', $input['storage_root']);
        Storage::forgetDisk('employee_documents');
        DB::statement("SET deadlock_timeout TO '100ms'");
        DB::statement("SET lock_timeout TO '15s'");

        $actor = User::query()->findOrFail($input['actor_id']);
        $employee = Employee::query()->findOrFail($input['employee_id']);
        $status = RefStatusPegawai::query()->findOrFail($input['status_id']);
        Auth::login($actor);

        $backendPid = (int) DB::scalar('select pg_backend_pid()');
        file_put_contents($input['ready'], (string) $backendPid, LOCK_EX);

        while (! is_file($input['barrier'])) {
            usleep(10_000);
        }

        try {
            $request = Request::create('/pegawai/status', 'POST', [], [], [], [
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_USER_AGENT' => 'SIMPEG-Race-Worker/1.0',
            ]);
            $request->setUserResolver(static fn (): User => $actor);
            app(ChangeEmployeeStatusAction::class)->execute(
                $employee,
                [
                    'status_pegawai_id' => $status->id,
                    'tanggal' => $input['tanggal_efektif'],
                    'keterangan' => 'Race dua attachment status.',
                ],
                $request,
                UploadedFile::fake()->createWithContent(
                    'sk-status.pdf',
                    'isi attachment worker '.$input['worker'],
                ),
            );

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
    (new EmployeeStatusTransitionRaceWorker)->run($input);
}
