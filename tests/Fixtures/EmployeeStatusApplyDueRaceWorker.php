<?php

namespace Tests\Fixtures;

use App\Models\User;
use App\Services\Employees\EmployeeStatusTransitionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

final class EmployeeStatusApplyDueRaceWorker
{
    /** @param array<string, string> $input */
    public function run(array $input): void
    {
        DB::statement("SET lock_timeout TO '15s'");
        file_put_contents($input['ready'], (string) DB::scalar('select pg_backend_pid()'), LOCK_EX);

        try {
            $sentinel = User::query()->findOrFail($input['sentinel_actor_id']);
            Auth::login($sentinel);
            $beforeActorId = Auth::id();
            $applied = app(EmployeeStatusTransitionService::class)->applyDue($input['tanggal']);

            $result = [
                'ok' => true,
                'applied' => $applied,
                'before_actor_id' => $beforeActorId,
                'after_actor_id' => Auth::id(),
            ];
        } catch (Throwable $exception) {
            $result = [
                'ok' => false,
                'class' => $exception::class,
                'message' => $exception->getMessage(),
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
    (new EmployeeStatusApplyDueRaceWorker)->run($input);
}
