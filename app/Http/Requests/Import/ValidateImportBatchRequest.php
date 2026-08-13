<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

class ValidateImportBatchRequest extends FormRequest
{
    /** Hanya pengelola data pegawai yang boleh memvalidasi isi batch impor. */
    public function authorize(): bool
    {
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    /**
     * Baris edit harus menunjuk nomor baris sumber dan tidak boleh menyuntikkan bentuk payload lain.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'rows' => ['sometimes', 'array'],
            'rows.*.row' => ['required', 'integer', 'min:2'],
            'rows.*.data' => ['required', 'array'],
        ];
    }
}
