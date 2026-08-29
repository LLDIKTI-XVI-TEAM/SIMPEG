<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode(base64_decode((string) ($argv[1] ?? ''), true), true, flags: JSON_THROW_ON_ERROR);

if (! is_array($payload)) {
    throw new UnexpectedValueException('Payload worker rollback source EWS wajib berupa objek JSON.');
}

$paused = false;
$pid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;

DB::listen(static function (QueryExecuted $query) use ($payload, $pid, &$paused): void {
    if ($paused) {
        return;
    }

    $sql = mb_strtolower($query->sql);
    $stage = null;
    if (str_contains($sql, 'lock table ews_alerts, employee_status_transitions')) {
        $stage = 'lock';
    } elseif (str_contains($sql, 'select exists')
        && str_contains($sql, 'employee_status_transitions')) {
        $stage = 'preflight';
    }

    if ($stage === null) {
        return;
    }

    $paused = true;
    File::put((string) $payload['ready'], json_encode([
        'pid' => $pid,
        'stage' => $stage,
    ], JSON_THROW_ON_ERROR));

    $release = (string) $payload['release'];
    $deadline = microtime(true) + 30;
    while (! File::exists($release) && microtime(true) < $deadline) {
        usleep(10_000);
    }

    if (! File::exists($release)) {
        throw new RuntimeException('Barrier rollback source EWS tidak dilepas.');
    }
});

try {
    DB::statement("SET lock_timeout TO '15s'");
    DB::transaction(function (): void {
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_08_28_000002_add_ews_source_to_employee_status_transitions.php';
        if (! $migration instanceof Migration) {
            throw new RuntimeException('Fixture rollback source EWS tidak mengembalikan migration.');
        }

        (new ReflectionMethod($migration, 'down'))->invoke($migration);
    });
    File::put((string) $payload['result'], json_encode(['ok' => true], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    File::put((string) $payload['result'], json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'code' => (string) $exception->getCode(),
    ], JSON_THROW_ON_ERROR));
}
