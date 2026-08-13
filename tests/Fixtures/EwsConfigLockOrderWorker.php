<?php

use App\Actions\Ews\UpdateEwsConfigAction;
use App\Models\EwsConfig;
use App\Models\User;
use App\Services\Ews\EwsConfigCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($input['booted'], 'booted');

try {
    $actor = User::query()->findOrFail($input['actor_id']);
    $payload = [];

    foreach (array_keys(EwsConfigCatalog::LABELS) as $key) {
        $payload[$key] = EwsConfig::getVal($key, EwsConfigCatalog::DEFAULTS[$key]);
    }

    $payload['pangkat_required_years'] = '5';
    $payload['reason'] = 'Uji urutan lock konfigurasi EWS.';

    $request = Request::create('/admin/ews/config', 'POST', $payload);
    $request->setUserResolver(static fn (): User => $actor);

    $backendPid = (int) DB::scalar('select pg_backend_pid()');
    file_put_contents($input['ready'], (string) $backendPid);
    file_put_contents($input['stage'], 'before-action');

    app(UpdateEwsConfigAction::class)->execute($request);

    file_put_contents($input['stage'], 'after-action');
    file_put_contents($input['result'], json_encode([
        'ok' => true,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($input['stage'], 'failed');
    file_put_contents($input['result'], json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
