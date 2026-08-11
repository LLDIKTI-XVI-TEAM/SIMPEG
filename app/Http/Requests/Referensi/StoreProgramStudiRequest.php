<?php

namespace App\Http\Requests\Referensi;

use Illuminate\Foundation\Http\FormRequest;

class StoreProgramStudiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:255', 'unique:ref_program_studi,nama'],
        ];
    }

    public function attributes(): array
    {
        return ['nama' => 'Nama program studi'];
    }
}
