<?php

namespace App\Http\Requests\Cuti;

use App\Models\User;
use App\Services\Cuti\AnnualLeaveBusinessClock;
use App\Services\Cuti\LeaveUsageAuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

class ReconcileAnnualLeaveUsageRequest extends FormRequest
{
    /** Otorisasi request mengulang exact-role guard agar route dan Action tidak menjadi satu-satunya pertahanan. */
    public function authorize(): bool
    {
        $actor = $this->user();

        if (! $actor instanceof User) {
            return false;
        }

        app(LeaveUsageAuthorizationService::class)->assertCanReconcile($actor);

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $currentYear = app(AnnualLeaveBusinessClock::class)->currentYear();

        return [
            'balance_year' => ['required', 'integer', 'in:'.$currentYear],
            'usage_n2' => ['required', 'integer', 'min:0'],
            'usage_n1' => ['required', 'integer', 'min:0'],
            'usage_current' => ['required', 'integer', 'min:0'],
            'administrative_note' => ['required', 'string', 'max:2000'],
        ];
    }
}
