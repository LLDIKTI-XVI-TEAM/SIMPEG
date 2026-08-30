<?php

use App\Actions\Cuti\ApplyGlobalPybmcAction;
use App\Actions\Cuti\SaveEmployeeApprovalChainAction;
use App\Actions\Cuti\SubmitLeaveRequestAction;
use App\Actions\Employees\AssignSupervisorAction;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($input['booted'], 'booted');

try {
    file_put_contents($input['stage'], 'before-actor-lookup');
    $actor = User::query()->findOrFail($input['actor_id']);
    file_put_contents($input['stage'], 'after-actor-lookup');

    if ($input['action'] === 'submit') {
        file_put_contents($input['stage'], 'before-submit-fixture-lookup');
        $employee = Employee::query()->findOrFail($input['employee_id']);
        file_put_contents($input['stage'], 'before-submit-action');
        file_put_contents($input['ready'], 'ready');
        $request = Request::create('/cuti', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        $leaveRequest = app(SubmitLeaveRequestAction::class)->execute(
            $employee,
            [
                'jenis_cuti_id' => $input['leave_type_id'],
                'tanggal_mulai' => $input['start_date'],
                'tanggal_selesai' => $input['end_date'],
                'alasan' => 'Uji urutan lock submit dan konfigurasi.',
                'alamat_selama_cuti' => 'Alamat uji concurrency',
                'nomor_telepon' => '081234567890',
            ],
            $request,
        );

        file_put_contents($input['result'], json_encode([
            'ok' => true,
            'leave_request_id' => $leaveRequest->id,
        ], JSON_THROW_ON_ERROR));
        file_put_contents($input['stage'], 'after-submit-action');

        return;
    }

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

    if ($input['action'] === 'assignment') {
        file_put_contents($input['stage'], 'before-assignment-fixture-lookup');
        $employee = Employee::query()->findOrFail($input['employee_id']);
        $kepalaBagian = Employee::query()->findOrFail($input['kepala_bagian_id']);
        file_put_contents($input['stage'], 'before-assignment-action');
        file_put_contents($input['ready'], 'ready');
        Auth::login($actor);

        $updatedEmployee = app(AssignSupervisorAction::class)->execute(
            $employee,
            $kepalaBagian->id,
            $input['effective_date'],
        );

        file_put_contents($input['result'], json_encode([
            'ok' => true,
            'employee_id' => $updatedEmployee->id,
        ], JSON_THROW_ON_ERROR));
        file_put_contents($input['stage'], 'after-assignment-action');

        return;
    }

    if ($input['action'] === 'global') {
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

        return;
    }

    throw new RuntimeException('Mode worker konfigurasi rantai tidak dikenal.');
} catch (Throwable $exception) {
    file_put_contents($input['stage'], 'failed');
    file_put_contents($input['result'], json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
