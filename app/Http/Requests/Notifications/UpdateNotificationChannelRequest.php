<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Kode channel immutable; hanya nama yang boleh diubah oleh Super Admin.
 */
class UpdateNotificationChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => ['prohibited'],
            'is_enabled' => ['prohibited'],
            'config' => ['prohibited'],
        ];
    }
}
