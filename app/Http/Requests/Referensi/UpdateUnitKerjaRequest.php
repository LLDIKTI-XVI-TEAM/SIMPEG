<?php

namespace App\Http\Requests\Referensi;

use Illuminate\Validation\Rule;

class UpdateUnitKerjaRequest extends StoreUnitKerjaRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $unitId = $this->boundUnit()?->id;

        return [
            ...parent::rules(),
            'nama' => ['required', 'string', 'max:100', Rule::unique('ref_unit_kerja', 'nama')->ignore($unitId)],
        ];
    }
}
