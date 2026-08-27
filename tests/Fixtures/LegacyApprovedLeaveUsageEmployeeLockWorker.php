<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode(base64_decode((string) ($argv[1] ?? ''), true), true, flags: JSON_THROW_ON_ERROR);

if (! is_array($payload)) {
    throw new UnexpectedValueException('Payload worker lock pegawai wajib berupa objek JSON.');
}

$mode = (string) ($payload['mode'] ?? '');
$employeeId = (string) ($payload['employee_id'] ?? '');
$result = (string) ($payload['result'] ?? '');

try {
    if ($mode === 'hold') {
        DB::beginTransaction();
        DB::select('SELECT id FROM employees WHERE id = ? FOR UPDATE', [$employeeId]);
        File::put((string) $payload['ready'], json_encode([
            'pid' => (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
        ], JSON_THROW_ON_ERROR));
        $deadline = microtime(true) + 30;

        while (! File::exists((string) $payload['release']) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        if (! File::exists((string) $payload['release'])) {
            throw new RuntimeException('Barrier release lock pegawai tidak diterima.');
        }

        DB::commit();
        File::put($result, json_encode(['ok' => true], JSON_THROW_ON_ERROR));

        return;
    }

    if ($mode !== 'probe') {
        throw new InvalidArgumentException('Mode worker lock pegawai tidak dikenali.');
    }

    DB::statement("SET lock_timeout TO '750ms'");
    DB::transaction(fn () => DB::select('SELECT id FROM employees WHERE id = ? FOR UPDATE', [$employeeId]));
    File::put($result, json_encode(['ok' => true], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    File::put($result, json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'code' => (string) $exception->getCode(),
    ], JSON_THROW_ON_ERROR));
}
