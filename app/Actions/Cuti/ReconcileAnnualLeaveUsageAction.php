<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\User;
use App\Services\Cuti\AnnualLeaveBusinessClock;
use App\Services\Cuti\LeaveUsageAuthorizationService;
use App\Services\Cuti\LeaveUsageReconciliationService;
use App\Services\Cuti\LeaveUsageTextNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class ReconcileAnnualLeaveUsageAction
{
    public function __construct(
        private readonly LeaveUsageAuthorizationService $authorization,
        private readonly LeaveUsageReconciliationService $reconciliations,
        private readonly LeaveUsageTextNormalizer $text,
        private readonly AnnualLeaveBusinessClock $businessClock,
    ) {}

    /**
     * Membuat snapshot tiga tahun setelah exact Admin guard dan lookup UUID.
     * Cutoff selalu berasal dari waktu server agar client tidak dapat memundurkan snapshot.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(
        string $employee,
        array $data,
        User $actor,
        ?Request $request = null,
    ): LeaveUsageReconciliationSet {
        $this->authorization->assertCanReconcile($actor);
        $employee = $this->resolveEmployee($employee);
        $validated = $this->validated($data);
        $note = $this->text->required(
            (string) $validated['administrative_note'],
            'administrative_note',
            'Keterangan atau sumber data wajib diisi.',
        );
        $balanceYear = (int) $validated['balance_year'];

        return $this->reconciliations->createAnnualReconciliationSet(
            $employee,
            $balanceYear,
            $this->usageByYear($balanceYear, $validated),
            $this->businessClock->now(),
            $note,
            $actor,
            $request,
        );
    }

    /** @param array<string, mixed> $data */
    private function validated(array $data): array
    {
        return Validator::make($data, [
            'balance_year' => ['required', 'integer', 'in:'.$this->businessClock->currentYear()],
            'usage_n2' => ['required', 'integer', 'min:0'],
            'usage_n1' => ['required', 'integer', 'min:0'],
            'usage_current' => ['required', 'integer', 'min:0'],
            'administrative_note' => ['required', 'string', 'max:2000'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, int>
     */
    private function usageByYear(int $balanceYear, array $validated): array
    {
        return [
            $balanceYear - 2 => (int) $validated['usage_n2'],
            $balanceYear - 1 => (int) $validated['usage_n1'],
            $balanceYear => (int) $validated['usage_current'],
        ];
    }

    private function resolveEmployee(string $employee): Employee
    {
        abort_unless(Str::isUuid($employee), 404);

        return Employee::query()->whereKey($employee)->firstOrFail();
    }
}
