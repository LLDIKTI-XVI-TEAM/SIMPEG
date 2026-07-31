<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Desired state wajib eksplisit agar retry form tidak membalik status dua kali.
 */
class SetNotificationChannelEnabledRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    public function rules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
            'code' => ['prohibited'],
            'name' => ['prohibited'],
            'config' => ['prohibited'],
        ];
    }
}
