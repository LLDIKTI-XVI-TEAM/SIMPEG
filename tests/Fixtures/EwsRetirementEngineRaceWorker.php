<?php

use App\Models\Employee;
use App\Services\EwsEngineService;
use App\Services\NotificationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode(base64_decode((string) ($argv[1] ?? ''), true), true, flags: JSON_THROW_ON_ERROR);

if (! is_array($payload)) {
    throw new UnexpectedValueException('Payload worker race engine pensiun wajib berupa objek JSON.');
}

Carbon::setTestNow(Carbon::parse((string) $payload['now']));

if (in_array(($payload['barrier_stage'] ?? 'before_create'), ['after_first_lock', 'after_alert_miss'], true)) {
    $backendPid = (int) DB::scalar('SELECT pg_backend_pid()');
    $lockReported = false;

    DB::listen(function (QueryExecuted $query) use ($payload, $backendPid, &$lockReported): void {
        if ($lockReported) {
            return;
        }

        $sql = strtolower($query->sql);
        $stage = (string) $payload['barrier_stage'];
        $isTargetEmployee = in_array(
            (string) $payload['employee_id'],
            array_map(static fn (mixed $binding): string => (string) $binding, $query->bindings),
            true,
        );
        $isRelevantLock = $stage === 'after_first_lock'
            && str_contains($sql, 'for update')
            && (str_contains($sql, 'from "employees"') || str_contains($sql, 'from "ews_alerts"'));
        $isAlertLookup = $stage === 'after_alert_miss'
            && str_contains($sql, 'select')
            && str_contains($sql, 'from "ews_alerts"');

        if (! $isTargetEmployee || (! $isRelevantLock && ! $isAlertLookup)) {
            return;
        }

        $lockReported = true;
        $lockedResource = str_contains($sql, 'from "ews_alerts"') ? 'alert' : 'employee';
        File::put((string) $payload['ready'], json_encode([
            'pid' => $backendPid,
            'stage' => $lockedResource,
        ], JSON_THROW_ON_ERROR));

        $release = (string) $payload['release'];
        $deadline = microtime(true) + 30;
        while (! File::exists($release) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        if (! File::exists($release)) {
            throw new RuntimeException('Barrier lock pertama engine pensiun tidak dilepas.');
        }
    });
}

$service = new class(app(NotificationService::class), $payload) extends EwsEngineService
{
    private bool $barrierReached = false;

    /** @param array<string, mixed> $payload */
    public function __construct(NotificationService $notifications, private readonly array $payload)
    {
        parent::__construct($notifications);
    }

    protected function createAlertIfNotExist(
        Employee $employee,
        string $type,
        string $targetDate,
        int $days,
        string $titleLabel,
        ?bool $isEligible = null,
        ?int $satyalancanaYears = null,
        bool $sendNotification = true,
    ): bool {
        if (($this->payload['barrier_stage'] ?? 'before_create') === 'before_create'
            && ! $this->barrierReached
            && $employee->id === (string) $this->payload['employee_id']
            && $type === 'PENSIUN') {
            $this->barrierReached = true;
            File::put((string) $this->payload['ready'], json_encode([
                'pid' => (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
            ], JSON_THROW_ON_ERROR));

            $release = (string) $this->payload['release'];
            $deadline = microtime(true) + 30;
            while (! File::exists($release) && microtime(true) < $deadline) {
                usleep(10_000);
            }

            if (! File::exists($release)) {
                throw new RuntimeException('Barrier race engine pensiun tidak dilepas.');
            }
        }

        return parent::createAlertIfNotExist(
            $employee,
            $type,
            $targetDate,
            $days,
            $titleLabel,
            $isEligible,
            $satyalancanaYears,
            $sendNotification,
        );
    }
};

try {
    DB::statement("SET lock_timeout TO '15s'");
    $service->run();
    File::put((string) $payload['result'], json_encode(['ok' => true], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    File::put((string) $payload['result'], json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'code' => (string) $exception->getCode(),
    ], JSON_THROW_ON_ERROR));
}
