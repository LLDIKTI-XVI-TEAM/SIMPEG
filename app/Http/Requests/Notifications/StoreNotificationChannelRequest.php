<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi boundary ini menolak status/config dari klien dan membatasi mutasi kepada Super Admin.
 */
class StoreNotificationChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/\A[a-z][a-z0-9_]*\z/',
                Rule::unique('ref_notification_channels', 'code'),
            ],
            'name' => ['required', 'string', 'max:100'],
            'is_enabled' => ['prohibited'],
            'config' => ['prohibited'],
        ];
    }
}
