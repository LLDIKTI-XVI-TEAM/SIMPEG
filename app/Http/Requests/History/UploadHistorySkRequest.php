<?php

namespace App\Http\Requests\History;

use Illuminate\Foundation\Http\FormRequest;

class UploadHistorySkRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $user = $this->user();

        return $user !== null && (
            $user->hasPermission('employee_histories.update') ||
            $user->hasPermission('employee_histories.create') ||
            in_array($user->role, ['super_admin', 'admin_kepegawaian'], true)
        );
    }

    public function rules(): array
    {
        return [
            'file_sk' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function attributes(): array
    {
        return [
            'file_sk' => 'Berkas SK',
        ];
    }
}
