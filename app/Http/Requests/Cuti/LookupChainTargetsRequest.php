<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class LookupChainTargetsRequest extends FormRequest
{
    /** Filter pencarian tidak memberi authority atas target di luar scope identitas. */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('cuti.configure') ?? false;
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'unit_kerja_id' => ['nullable', 'uuid'], 'page' => ['sometimes', 'integer', 'min:1']];
    }

    /** Spasi Unicode hanya dinormalisasi pada string sehingga input array tetap ditolak. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $this->input('q'))]);
        }
        if (is_string($this->input('unit_kerja_id'))) {
            $this->merge(['unit_kerja_id' => strtolower($this->input('unit_kerja_id'))]);
        }
    }

    /** Ukuran halaman selalu ditetapkan server, bukan parameter arbitrer pemanggil. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['q', 'unit_kerja_id', 'page']) !== []) {
                $validator->errors()->add('filters', 'Filter pencarian tidak dikenal.');
            }
        });
    }
}
