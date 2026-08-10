<?php

use App\Actions\Cuti\ApplyGlobalPybmcAction;
use App\Actions\Cuti\SaveEmployeeApprovalChainAction;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($input['booted'], 'booted');

try {
    file_put_contents($input['stage'], 'before-actor-lookup');
    $actor = User::query()->findOrFail($input['actor_id']);
    file_put_contents($input['stage'], 'after-actor-lookup');

    if ($input['action'] === 'save') {
        file_put_contents($input['stage'], 'before-save-fixture-lookup');
        $employee = Employee::query()->findOrFail($input['employee_id']);
        $kepalaBagian = Employee::query()->findOrFail($input['kepala_bagian_id']);
        $pybmc = Employee::query()->findOrFail($input['pybmc_id']);
        file_put_contents($input['stage'], 'before-save-action');
        file_put_contents($input['ready'], 'ready');

        $chain = app(SaveEmployeeApprovalChainAction::class)->execute(
            $employee,
            [
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => false],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id, 'is_final' => true],
            ],
            $actor,
            'Uji serialisasi SaveAction.',
        );

        file_put_contents($input['result'], json_encode([
            'ok' => true,
            'chain_id' => $chain->id,
        ], JSON_THROW_ON_ERROR));
        file_put_contents($input['stage'], 'after-save-action');

        return;
    }

    file_put_contents($input['stage'], 'before-global-fixture-lookup');
    $approver = Employee::query()->findOrFail($input['approver_id']);
    file_put_contents($input['stage'], 'before-global-action');
    file_put_contents($input['ready'], 'ready');
    $config = app(ApplyGlobalPybmcAction::class)->execute(
        $approver,
        $actor,
        'Uji serialisasi ApplyGlobal.',
    );
    file_put_contents($input['result'], json_encode([
        'ok' => true,
        'config_id' => $config->id,
    ], JSON_THROW_ON_ERROR));
    file_put_contents($input['stage'], 'after-global-action');
} catch (Throwable $exception) {
    file_put_contents($input['stage'], 'failed');
    file_put_contents($input['result'], json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
