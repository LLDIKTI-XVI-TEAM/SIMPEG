<?php

use App\Models\EmployeeStatusTransition;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode(base64_decode((string) ($argv[1] ?? ''), true), true, flags: JSON_THROW_ON_ERROR);

if (! is_array($payload)) {
    throw new UnexpectedValueException('Payload writer transisi pensiun EWS wajib berupa objek JSON.');
}

try {
    DB::statement("SET lock_timeout TO '15s'");
    File::put((string) $payload['ready'], json_encode([
        'pid' => (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
    ], JSON_THROW_ON_ERROR));

    DB::transaction(function () use ($payload): void {
        $transition = new EmployeeStatusTransition;
        $transition->forceFill([
            'employee_id' => (string) $payload['employee_id'],
            'status_pegawai_id' => (string) $payload['status_id'],
            'tanggal_efektif' => (string) $payload['tanggal_efektif'],
            'kind' => EmployeeStatusTransition::KIND_EWS_RETIREMENT,
            'source_ews_alert_id' => (string) $payload['source_ews_alert_id'],
            'actor_user_id_snapshot' => (string) Str::uuid(),
            'actor_name_snapshot' => 'Writer Race Rollback',
            'actor_original_role' => 'super_admin',
            'actor_effective_role' => 'super_admin',
            'authorization_permission' => 'employees.deactivate',
            'authorization_action' => EmployeeStatusTransition::KIND_DEACTIVATE,
            'actor_simulation' => false,
            'actor_ip_address' => '127.0.0.1',
            'actor_user_agent' => 'SIMPEG-Rollback-Race/1.0',
            'provenance_status' => EmployeeStatusTransition::PROVENANCE_CAPTURED,
            'is_applied' => false,
        ])->saveOrFail();
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
