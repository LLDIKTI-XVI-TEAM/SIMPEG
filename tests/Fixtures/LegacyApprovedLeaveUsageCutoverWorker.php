<?php

use App\Support\Cuti\BackfillLegacyApprovedLeaveUsage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode(base64_decode((string) ($argv[1] ?? ''), true), true, flags: JSON_THROW_ON_ERROR);

if (! is_array($payload)) {
    throw new UnexpectedValueException('Payload worker cutover wajib berupa objek JSON.');
}

$ready = (string) ($payload['ready'] ?? '');
$result = (string) ($payload['result'] ?? '');
$attempt = (string) ($payload['attempt'] ?? '');
$observedQuery = mb_strtolower((string) ($payload['observe_query'] ?? ''));
$pid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;

if ($attempt !== '' && $observedQuery !== '') {
    $attemptCount = 0;
    DB::connection()->beforeExecuting(static function (string $query) use (
        $attempt,
        &$attemptCount,
        $observedQuery,
        $pid,
    ): void {
        if (! str_contains(mb_strtolower($query), $observedQuery)) {
            return;
        }

        $attemptCount++;
        File::replace($attempt, json_encode([
            'pid' => $pid,
            'count' => $attemptCount,
            'query' => $observedQuery,
        ], JSON_THROW_ON_ERROR));
    });
}

File::put($ready, json_encode(['pid' => $pid], JSON_THROW_ON_ERROR));

try {
    $summary = app(BackfillLegacyApprovedLeaveUsage::class)->execute();
    File::put($result, json_encode(['ok' => true, 'summary' => $summary], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    File::put($result, json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'code' => (string) $exception->getCode(),
    ], JSON_THROW_ON_ERROR));
}
