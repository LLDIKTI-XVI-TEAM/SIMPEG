<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode(base64_decode((string) ($argv[1] ?? ''), true), true, flags: JSON_THROW_ON_ERROR);

if (! is_array($payload)) {
    throw new UnexpectedValueException('Payload worker interleaving wajib berupa objek JSON.');
}

$mode = (string) ($payload['mode'] ?? '');
$result = (string) ($payload['result'] ?? '');

try {
    DB::statement("SET lock_timeout TO '5s'");
    DB::statement("SET deadlock_timeout TO '100ms'");
    DB::beginTransaction();

    if ($mode === 'submit') {
        DB::select('SELECT id FROM employees WHERE id = ? FOR UPDATE', [(string) $payload['employee_id']]);
    } elseif ($mode === 'approve') {
        DB::select('SELECT id FROM leave_requests WHERE id = ? FOR UPDATE', [(string) $payload['leave_request_id']]);
    } else {
        throw new InvalidArgumentException('Mode worker interleaving tidak dikenali.');
    }

    File::put((string) $payload['ready'], json_encode([
        'pid' => (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
    ], JSON_THROW_ON_ERROR));
    $deadline = microtime(true) + 30;

    while (! File::exists((string) $payload['continue']) && microtime(true) < $deadline) {
        usleep(10_000);
    }

    if (! File::exists((string) $payload['continue'])) {
        throw new RuntimeException('Barrier lanjutan interleaving tidak diterima.');
    }

    if ($mode === 'submit') {
        DB::table('leave_requests')->insert([
            'id' => (string) $payload['leave_request_id'],
            'employee_id' => (string) $payload['employee_id'],
            'jenis_cuti_id' => (string) $payload['leave_type_id'],
            'tanggal_mulai' => '2026-10-05',
            'tanggal_selesai' => '2026-10-05',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Fixture submit employee-first saat cutover.',
            'status' => 'menunggu_approval',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } else {
        DB::select('SELECT id FROM employees WHERE id = ? FOR UPDATE', [(string) $payload['employee_id']]);
        DB::table('leave_request_steps')
            ->where('leave_request_id', (string) $payload['leave_request_id'])
            ->where('is_final', true)
            ->update([
                'status' => 'approved',
                'acted_at' => now(),
                'updated_at' => now(),
            ]);
        DB::table('leave_requests')
            ->where('id', (string) $payload['leave_request_id'])
            ->update(['status' => 'disetujui', 'updated_at' => now()]);
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
