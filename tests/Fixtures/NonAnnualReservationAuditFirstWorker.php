<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode(base64_decode((string) ($argv[1] ?? ''), true), true, flags: JSON_THROW_ON_ERROR);

if (! is_array($payload)) {
    throw new UnexpectedValueException('Payload worker audit-first wajib berupa objek JSON.');
}

$mode = (string) ($payload['mode'] ?? '');
$result = (string) ($payload['result'] ?? '');

try {
    DB::statement("SET lock_timeout TO '5s'");
    DB::statement("SET deadlock_timeout TO '100ms'");
    DB::beginTransaction();
    DB::table('audit_logs')->insert([
        'id' => (string) $payload['audit_id'],
        'user_id' => null,
        'user_name' => 'SIMPEG Test Audit-First Worker',
        'event' => 'CREATE',
        'auditable_type' => 'LeaveRequestCase',
        'auditable_id' => (string) $payload['audit_entity_id'],
        'old_values' => null,
        'new_values' => json_encode(['source' => 'audit_first_concurrency_fixture'], JSON_THROW_ON_ERROR),
        'ip_address' => null,
        'user_agent' => null,
        'created_at' => now(),
    ]);
    File::put((string) $payload['ready'], json_encode([
        'pid' => (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
    ], JSON_THROW_ON_ERROR));
    $deadline = microtime(true) + 30;

    if ($mode === 'interleave') {
        $attempt = (string) $payload['attempt'];

        do {
            clearstatcache(true, $attempt);
            $attemptCount = 0;

            if (File::exists($attempt)) {
                $state = json_decode(File::get($attempt), true, flags: JSON_THROW_ON_ERROR);
                $attemptCount = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
            }

            if ($attemptCount >= 1) {
                break;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        if ($attemptCount < 1) {
            throw new RuntimeException('Worker audit-first tidak melihat percobaan lock migration.');
        }

        DB::table('leave_requests')->insert([
            'id' => (string) $payload['leave_request_id'],
            'employee_id' => (string) $payload['employee_id'],
            'jenis_cuti_id' => (string) $payload['leave_type_id'],
            'tanggal_mulai' => '2026-10-05',
            'tanggal_selesai' => '2026-10-05',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Fixture submit audit-first saat rekonsiliasi reservasi.',
            'status' => 'menunggu_approval',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } elseif ($mode === 'hold') {
        $release = (string) $payload['release'];

        while (! File::exists($release) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        if (! File::exists($release)) {
            throw new RuntimeException('Barrier pelepasan worker audit-first tidak diterima.');
        }
    } else {
        throw new InvalidArgumentException('Mode worker audit-first tidak dikenali.');
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
