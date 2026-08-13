<?php

namespace App\Http\Requests\Referensi;

use App\Models\RefProgramStudi;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProgramStudiRequest extends FormRequest
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
        $programStudi = $this->route('programStudi');
        $programStudiId = $programStudi instanceof RefProgramStudi ? $programStudi->id : null;

        return [
            'nama' => [
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
