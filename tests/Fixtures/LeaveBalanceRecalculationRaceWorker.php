<?php

namespace Tests\Fixtures;

use App\Models\Employee;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\User;
use App\Services\Cuti\LeaveUsageReconciliationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

final class LeaveBalanceRecalculationRaceWorker
{
    /** @param array<string, string> $input */
    public function run(array $input): void
    {
        $employee = Employee::query()->findOrFail($input['employee_id']);
        $actor = User::query()->findOrFail($input['actor_id']);
        file_put_contents($input['ready'], 'ready');

        while (! is_file($input['barrier'])) {
            usleep(10_000);
        }

        try {
            $service = app(LeaveUsageReconciliationService::class);
            $usage = (int) $input['usage'];

            if (($input['mode'] ?? 'create') === 'replace') {
                $current = LeaveUsageReconciliationSet::query()->findOrFail($input['current_set_id']);
                $set = $service->replaceAnnualReconciliationSet(
                    $current,
                    [2024 => 0, 2025 => 0, 2026 => $usage],
                    Carbon::parse('2026-08-18'),
                    'Concurrent replacement reconciliation.',
                    'Concurrent replacement correction.',
                    $actor,
                );
            } else {
                $set = $service->createAnnualReconciliationSet(
                    $employee,
                    2026,
                    [2024 => 0, 2025 => 0, 2026 => $usage],
                    Carbon::parse('2026-08-18'),
                    'Concurrent reconciliation.',
                    $actor,
                );
            }

            $result = ['ok' => true, 'set_id' => $set->id, 'usage' => $usage];
        } catch (Throwable $exception) {
            $result = [
                'ok' => false,
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'errors' => $exception instanceof ValidationException ? $exception->errors() : null,
            ];
        }

        file_put_contents($input['result'], json_encode($result, JSON_THROW_ON_ERROR));
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
    (new LeaveBalanceRecalculationRaceWorker)->run($input);
}
