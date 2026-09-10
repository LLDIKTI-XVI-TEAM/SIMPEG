<?php

namespace App\Http\Requests\Referensi;

use App\Models\RefEselon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEselonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('reference_tables.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $eselon = $this->route('eselon');
        $eselonId = $eselon instanceof RefEselon ? $eselon->id : null;

        return [
            'kode' => ['required', 'string', 'max:10', Rule::unique('ref_eselon', 'kode')->ignore($eselonId)],
            'nama' => ['required', 'string', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'kode' => 'Kode eselon',
            'nama' => 'Nama eselon',
        ];
    }
}
