<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

class ImportEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi gagal-tertutup: tanpa user terautentikasi, tolak (tidak ada bypass dev/test).
        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx,xls'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Format yang didukung adalah CSV, XLSX, atau XLS.',
        ];
    }
}
