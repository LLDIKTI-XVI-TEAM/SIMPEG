<?php

namespace App\Http\Requests\Referensi;

use App\Models\RefProgramStudi;
use App\Support\ProgramStudi\ProgramStudiNameNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProgramStudiRequest extends FormRequest
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
        // Program Studi adalah data referensi Data Master; hanya Super Admin yang juga
        // memiliki permission reference_tables.manage boleh mengelolanya (lapisan kedua
        // di atas role middleware pada route), fail-closed terhadap pencabutan permission.
        return $this->user()?->role === 'super_admin'
            && $this->user()?->hasPermission('reference_tables.manage');
    }

    public function rules(): array
    {
        $programStudi = $this->route('programStudi');
        $programStudiId = $programStudi instanceof RefProgramStudi ? $programStudi->id : null;

        return [
            'nama' => [
                'bail',
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($programStudiId): void {
                    if (RefProgramStudi::query()
                        ->whereRaw('LOWER(nama) = ?', [mb_strtolower((string) $value)])
                        ->where('id', '!=', $programStudiId)
                        ->exists()) {
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
