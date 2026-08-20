<?php

namespace App\Http\Requests;

use App\Support\Documents\SkCompleteness;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSkRequirementMatrixRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->role === 'super_admin';
    }

    public function rules(): array
    {
        return [
            // matrix[jenis_pegawai_id][] = sk_key yang dicentang (wajib).
            'matrix' => ['required', 'array'],
            'matrix.*' => ['array'],
            'matrix.*.*' => ['string', Rule::in(SkCompleteness::poolKeys())],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'matrix' => 'matriks SK wajib',
            'reason' => 'alasan perubahan',
        ];
    }
}
