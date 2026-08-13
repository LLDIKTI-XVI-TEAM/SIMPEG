<?php

namespace App\Http\Requests\Referensi;

use Illuminate\Foundation\Http\FormRequest;

class DeleteProgramStudiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    public function rules(): array
    {
        return [];
    }
}
