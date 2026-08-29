<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$payload = json_decode(base64_decode((string) ($argv[1] ?? ''), true), true, flags: JSON_THROW_ON_ERROR);

if (! is_array($payload)) {
    throw new UnexpectedValueException('Payload worker race baca notifikasi EWS wajib berupa objek JSON.');
}

Carbon::setTestNow(Carbon::parse((string) $payload['now']));

try {
    DB::statement("SET deadlock_timeout TO '100ms'");
    DB::statement("SET lock_timeout TO '15s'");

    $actor = User::query()->findOrFail((string) $payload['actor_id']);
    Auth::login($actor);

    $notificationLockReported = false;
    DB::listen(function (QueryExecuted $query) use ($payload, &$notificationLockReported): void {
        $sql = strtolower($query->sql);
        if ($notificationLockReported
            || ! str_starts_with($sql, 'update "notifications"')
            || ! in_array(
                (string) $payload['notification_id'],
                array_map(static fn (mixed $binding): string => (string) $binding, $query->bindings),
                true,
            )) {
            return;
        }

        // Pada implementasi lama, titik ini memegang lock notifikasi di dalam
        // transaksi middleware sebelum mencoba acknowledgement alert.
        $notificationLockReported = true;
        File::put((string) $payload['notification_locked'], 'locked');

        $deadline = microtime(true) + 30;
        while (! File::exists((string) $payload['notification_release']) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        if (! File::exists((string) $payload['notification_release'])) {
            throw new RuntimeException('Barrier lock notifikasi EWS tidak dilepas.');
        }
    });

    File::put((string) $payload['ready'], json_encode([
        'pid' => (int) DB::scalar('SELECT pg_backend_pid()'),
    ], JSON_THROW_ON_ERROR));

    $request = Request::create(
        '/api/v1/notifikasi/'.(string) $payload['notification_id'].'/tandai-dibaca',
        'PATCH',
        server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'SIMPEG-EWS-Notification-Race-Worker/1.0',
            'HTTP_ACCEPT' => 'application/json',
        ],
    );
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
