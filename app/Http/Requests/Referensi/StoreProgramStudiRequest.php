<?php

namespace App\Http\Requests\Referensi;

use App\Models\RefProgramStudi;
use App\Support\ProgramStudi\ProgramStudiNameNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class StoreProgramStudiRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $name = $this->input('nama');

        if (is_string($name)) {
            $this->merge(['nama' => ProgramStudiNameNormalizer::normalize($name)]);
        }
    }

    public function authorize(): bool
    {
        // Permission menjadi sumber otorisasi agar backend tetap fail-closed saat role berubah.
        return (bool) $this->user()?->hasPermission('reference_tables.manage');
    }

    public function rules(): array
    {
        return [
            'nama' => [
                'bail',
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
