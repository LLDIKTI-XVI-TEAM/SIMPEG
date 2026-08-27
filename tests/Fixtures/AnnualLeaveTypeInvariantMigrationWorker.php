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
    throw new UnexpectedValueException('Payload worker migration invariant tahunan wajib berupa objek JSON.');
}

$attemptPath = (string) ($payload['attempt'] ?? '');
$rowLockedPath = (string) ($payload['row_locked'] ?? '');
$resultPath = (string) ($payload['result'] ?? '');
$pid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
$attemptCount = 0;

DB::connection()->beforeExecuting(static function (string $query) use ($attemptPath, $pid, &$attemptCount): void {
    if (! str_contains(mb_strtolower($query), 'lock table ref_jenis_cuti in access exclusive mode nowait')) {
        return;
    }

    $attemptCount++;
    File::replace($attemptPath, json_encode([
        'pid' => $pid,
        'count' => $attemptCount,
    ], JSON_THROW_ON_ERROR));
});
DB::listen(static function (QueryExecuted $query) use ($rowLockedPath, $pid): void {
    $sql = mb_strtolower($query->sql);
    if (! str_contains($sql, 'from "ref_jenis_cuti"')
        || ! str_contains($sql, 'mengurangi_saldo_tahunan is distinct from')
        || ! str_contains($sql, 'for update')) {
        return;
    }

    File::replace($rowLockedPath, json_encode(['pid' => $pid], JSON_THROW_ON_ERROR));
});

File::put((string) $payload['ready'], json_encode(['pid' => $pid], JSON_THROW_ON_ERROR));

try {
    DB::statement("SET lock_timeout TO '3s'");
    DB::statement("SET deadlock_timeout TO '100ms'");
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_08_23_000001_enforce_annual_leave_type_balance_flag.php';

    if (! $migration instanceof Migration) {
        throw new RuntimeException('Fixture invariant tahunan tidak mengembalikan instance Migration.');
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
