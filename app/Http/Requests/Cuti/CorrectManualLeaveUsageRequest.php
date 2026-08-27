<?php

namespace App\Http\Requests\Cuti;

use App\Models\User;
use App\Services\Cuti\LeaveUsageAuthorizationService;

final class CorrectManualLeaveUsageRequest extends StoreManualLeaveUsageRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        app(LeaveUsageAuthorizationService::class)->assertCanManageManual($user);

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'correction_reason' => ['required', 'string', 'max:2000'],
        ]);
    }
}
