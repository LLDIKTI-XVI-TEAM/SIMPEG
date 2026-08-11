<?php

namespace App\Http\Requests\Referensi;

use App\Models\RefProgramStudi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProgramStudiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    public function rules(): array
    {
        $programStudi = $this->route('programStudi');
        $programStudiId = $programStudi instanceof RefProgramStudi ? $programStudi->id : null;

        return [
            'nama' => ['required', 'string', 'max:255', Rule::unique('ref_program_studi', 'nama')->ignore($programStudiId)],
        ];
    }

    public function attributes(): array
    {
        return ['nama' => 'Nama program studi'];
    }
}
