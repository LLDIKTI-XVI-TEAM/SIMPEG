<?php

use App\Actions\Employees\AssignSupervisorAction;
use App\Models\Employee;
use App\Services\Cuti\ApprovalChainInvariantService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/**
 * Memaksa implementasi lama berhenti setelah hanya mengunci approver lawan.
 * Implementasi baru melewatkan barrier ini karena target ikut dikunci dalam batch yang sama.
 */
final class CoordinatedApprovalChainInvariantService extends ApprovalChainInvariantService
{
    /** @param array<string, mixed> $input */
    public function __construct(private readonly array $input) {}

    /**
     * @param  list<mixed>  $approverIds
     * @param  list<mixed>  $additionalEmployeeLockIds
     */
    public function validateApproverIds(array $approverIds, array $additionalEmployeeLockIds = []): void
    {
        parent::validateApproverIds($approverIds, $additionalEmployeeLockIds);

        if ($additionalEmployeeLockIds !== []) {
            return;
        }

        file_put_contents($this->input['validated'], 'validated');

        while (! is_file($this->input['peer_validated'])) {
            usleep(10_000);
        }
    }
}

$input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);

while (! is_file($input['barrier'])) {
    usleep(10_000);
}

try {
    app()->instance(ApprovalChainInvariantService::class, new CoordinatedApprovalChainInvariantService($input));

    $employee = Employee::query()->findOrFail($input['employee_id']);
    app(AssignSupervisorAction::class)->execute(
        $employee,
        $input['supervisor_id'],
        '2099-01-01',
    );

    file_put_contents($input['result'], json_encode(['ok' => true], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($input['result'], json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
