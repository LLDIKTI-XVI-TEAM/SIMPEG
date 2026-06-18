<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Format awal yang didukung adalah CSV. Silakan export file Excel ke CSV terlebih dahulu.',
        ];
    }
}
