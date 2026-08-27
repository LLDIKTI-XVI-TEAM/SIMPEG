<?php

namespace App\Http\Requests\Cuti;

use App\Models\User;
use App\Services\Cuti\LeaveUsageAuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

final class CancelManualLeaveUsageRequest extends FormRequest
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
        return [
            'correction_reason' => ['required', 'string', 'max:2000'],
            'approval_document_number' => ['prohibited'],
            'approval_steps' => ['prohibited'],
            'dokumen' => ['prohibited'],
        ];
    }
}
