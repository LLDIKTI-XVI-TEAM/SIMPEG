<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode(base64_decode((string) ($argv[1] ?? ''), true), true, flags: JSON_THROW_ON_ERROR);

if (! is_array($payload)) {
    throw new UnexpectedValueException('Payload worker migration reservasi wajib berupa objek JSON.');
}

$attemptPath = (string) ($payload['attempt'] ?? '');
$resultPath = (string) ($payload['result'] ?? '');
$pid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
$attemptCount = 0;

DB::connection()->beforeExecuting(static function (string $query) use ($attemptPath, $pid, &$attemptCount): void {
    if (! str_contains(mb_strtolower($query), 'lock table audit_logs in share row exclusive mode')) {
        return;
    }

    $attemptCount++;
    File::replace($attemptPath, json_encode([
        'pid' => $pid,
        'count' => $attemptCount,
    ], JSON_THROW_ON_ERROR));
});

File::put((string) $payload['ready'], json_encode(['pid' => $pid], JSON_THROW_ON_ERROR));

try {
    DB::statement("SET lock_timeout TO '5s'");
    DB::statement("SET deadlock_timeout TO '100ms'");
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_08_23_000004_reconcile_nonannual_active_reservations.php';

    if (! $migration instanceof Migration) {
        throw new RuntimeException('Fixture migration reservasi tidak mengembalikan instance Migration.');
    }

    (new ReflectionMethod($migration, 'up'))->invoke($migration);
    File::put($resultPath, json_encode([
        'ok' => true,
        'attempts' => $attemptCount,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    File::put($resultPath, json_encode([
        'ok' => false,
        'attempts' => $attemptCount,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'code' => (string) $exception->getCode(),
    ], JSON_THROW_ON_ERROR));
}
