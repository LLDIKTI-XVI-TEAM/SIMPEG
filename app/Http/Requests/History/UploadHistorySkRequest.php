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
            $user->hasPermission('dokumen_sk.update') ||
            $user->getEffectiveRole() === 'super_admin'
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
