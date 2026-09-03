<?php

use App\Models\Document;
use App\Models\EwsAlert;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

const BARRIER_TIMEOUT_SECONDS = 45;

$payload = json_decode(base64_decode((string) ($argv[1] ?? ''), true), true, flags: JSON_THROW_ON_ERROR);

if (! is_array($payload)) {
    throw new UnexpectedValueException('Payload worker follow-up pensiun wajib berupa objek JSON.');
}

Carbon::setTestNow(Carbon::parse((string) $payload['now']));
config()->set('filesystems.disks.'.Document::STORAGE_DISK.'.root', (string) $payload['storage_root']);
Storage::forgetDisk(Document::STORAGE_DISK);

try {
    DB::statement("SET deadlock_timeout TO '100ms'");
    DB::statement("SET lock_timeout TO '15s'");

    $actor = User::query()->findOrFail((string) $payload['actor_id']);
    $alert = EwsAlert::query()->findOrFail((string) $payload['alert_id']);
    Auth::login($actor);

    if (isset($payload['lock_ready'], $payload['release'])) {
        $lockReported = false;
        DB::listen(function (QueryExecuted $query) use ($payload, &$lockReported): void {
            $sql = strtolower($query->sql);
            $isSelectedAlertLock = ! $lockReported
                && str_contains($sql, 'from "ews_alerts"')
                && str_contains($sql, 'for update')
                && in_array(
                    (string) $payload['alert_id'],
                    array_map(static fn (mixed $binding): string => (string) $binding, $query->bindings),
                    true,
                );

            if (! $isSelectedAlertLock) {
                return;
            }

            $lockReported = true;
            File::put((string) $payload['lock_ready'], 'locked');
            $deadline = microtime(true) + BARRIER_TIMEOUT_SECONDS;
            while (! File::exists((string) $payload['release']) && microtime(true) < $deadline) {
                usleep(10_000);
            }

            if (! File::exists((string) $payload['release'])) {
                throw new RuntimeException('Barrier lock alert follow-up tidak dilepas.');
            }
        });
    }

    if (isset($payload['read_ready'], $payload['read_release'])) {
        $readReported = false;
        DB::listen(function (QueryExecuted $query) use ($payload, &$readReported): void {
            $sql = strtolower($query->sql);
            $isSelectedAlertRead = ! $readReported
                && str_contains($sql, 'from "ews_alerts"')
                && ! str_contains($sql, 'for update')
                && in_array(
                    (string) $payload['alert_id'],
                    array_map(static fn (mixed $binding): string => (string) $binding, $query->bindings),
                    true,
                );

            if (! $isSelectedAlertRead) {
                return;
            }

            $readReported = true;
            File::put((string) $payload['read_ready'], 'read');
            $deadline = microtime(true) + BARRIER_TIMEOUT_SECONDS;
            while (! File::exists((string) $payload['read_release']) && microtime(true) < $deadline) {
                usleep(10_000);
            }

            if (! File::exists((string) $payload['read_release'])) {
                throw new RuntimeException('Barrier pembacaan awal alert follow-up tidak dilepas.');
            }
        });
    }

    File::put((string) $payload['ready'], json_encode([
        'pid' => (int) DB::scalar('SELECT pg_backend_pid()'),
    ], JSON_THROW_ON_ERROR));

    $request = Request::create('/ews/'.$alert->id.'/followup', 'POST', [
        'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
        'handled_note' => 'Approval future bersamaan dengan scheduler EWS.',
        'no_sk' => 'SK-PENSIUN-RACE-LOCK',
        'tanggal_sk' => (string) $payload['effective_date'],
    ], [], [
        'file_sk' => UploadedFile::fake()->createWithContent(
            'sk-pensiun-race-lock.pdf',
            'SK pensiun race lock order',
        ),
    ], [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_USER_AGENT' => 'SIMPEG-EWS-Followup-Race-Worker/1.0',
        'HTTP_ACCEPT' => 'application/json',
    ]);
    $kernel = $app->make(HttpKernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    $responsePayload = json_decode((string) $response->getContent(), true);
    File::put((string) $payload['result'], json_encode([
        'ok' => $response->getStatusCode() === 200,
        'status' => $response->getStatusCode(),
        'message' => is_array($responsePayload)
            ? ($responsePayload['message'] ?? null)
            : substr((string) $response->getContent(), 0, 2_000),
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    File::put((string) $payload['result'], json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'code' => (string) $exception->getCode(),
    ], JSON_THROW_ON_ERROR));
} finally {
    Auth::logout();
}
