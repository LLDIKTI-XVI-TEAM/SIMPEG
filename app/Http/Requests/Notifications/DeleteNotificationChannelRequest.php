<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Delete channel tetap memakai gerbang request fail-closed walaupun tidak memiliki payload.
 */
class DeleteNotificationChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    public function rules(): array
    {
        return [];
    }
}
