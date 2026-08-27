<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode(base64_decode((string) ($argv[1] ?? ''), true), true, flags: JSON_THROW_ON_ERROR);

if (! is_array($payload)) {
    throw new UnexpectedValueException('Payload worker writer invariant tahunan wajib berupa objek JSON.');
}

$mode = (string) ($payload['mode'] ?? '');
$result = (string) ($payload['result'] ?? '');

try {
    DB::statement("SET lock_timeout TO '5s'");
    DB::statement("SET deadlock_timeout TO '100ms'");
    DB::beginTransaction();
    DB::statement('LOCK TABLE ref_jenis_cuti IN ROW EXCLUSIVE MODE');
    File::put((string) $payload['ready'], json_encode([
        'pid' => (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
    ], JSON_THROW_ON_ERROR));
    $deadline = microtime(true) + 20;

    if ($mode === 'interleave') {
        $proceed = (string) $payload['proceed'];
        while (! File::exists($proceed) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        if (! File::exists($proceed)) {
            throw new RuntimeException('Barrier update writer invariant tahunan tidak diterima.');
        }

        $updated = DB::table('ref_jenis_cuti')
            ->where('id', (string) $payload['row_id'])
            ->update(['nama' => (string) $payload['updated_name']]);
        if ($updated !== 1) {
            throw new RuntimeException('Writer invariant tahunan gagal memperbarui row fixture.');
        }
    } elseif ($mode === 'hold') {
        $release = (string) $payload['release'];
        while (! File::exists($release) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        if (! File::exists($release)) {
            throw new RuntimeException('Barrier pelepasan writer invariant tahunan tidak diterima.');
        }
    } else {
        throw new InvalidArgumentException('Mode worker writer invariant tahunan tidak dikenali.');
    }

    DB::commit();
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
