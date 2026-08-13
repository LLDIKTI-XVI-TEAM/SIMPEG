<?php

namespace App\Http\Requests\Referensi;

use App\Models\RefProgramStudi;
use Illuminate\Foundation\Http\FormRequest;

class StoreProgramStudiRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('nama')) {
            $this->merge(['nama' => preg_replace('/\s+/u', ' ', trim((string) $this->input('nama')))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    public function rules(): array
    {
        return [
            'nama' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (RefProgramStudi::query()->whereRaw('LOWER(nama) = ?', [mb_strtolower((string) $value)])->exists()) {
                        $fail('Nama program studi sudah tersedia.');
                    }
                },
            ],
        ];
    }

    public function attributes(): array
    {
        return ['nama' => 'Nama program studi'];
    }
}
