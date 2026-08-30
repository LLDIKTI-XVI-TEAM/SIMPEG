<?php

namespace App\Http\Requests\SkRequirement;

use App\Models\RefJenisPegawai;
use App\Support\Documents\SkCompleteness;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSkRequirementMatrixRequest extends FormRequest
{
    /** Pengelolaan matriks permission-driven: cukup permission pada role efektif. */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->hasPermission('sk_requirements.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'matrix' => ['required', 'array'],
            'matrix.*' => ['array', 'max:'.count(SkCompleteness::poolKeys())],
            'matrix.*.*' => ['string', Rule::in(SkCompleteness::poolKeys())],
        ];
    }

    /**
     * Key associative matrix adalah UUID jenis pegawai. Bentuk dan key harus
     * ditolak sebelum query agar input rusak tidak menjadi exception PostgreSQL.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var array<string, mixed> $matrix */
                $matrix = $this->input('matrix', []);

                if (! is_array($matrix) || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $typeIds = array_keys($matrix);

                if ($typeIds === []) {
                    return;
                }

                foreach ($typeIds as $typeId) {
                    if (! is_string($typeId) || ! Str::isUuid($typeId)) {
                        $validator->errors()->add('matrix', 'Identitas jenis pegawai tidak valid.');

                        return;
                    }
                }

                $types = RefJenisPegawai::query()
                    ->whereIn('id', $typeIds)
                    ->get(['id', 'nama'])
                    ->keyBy('id');

                foreach ($typeIds as $typeId) {
                    $type = $types->get($typeId);
                    if ($type === null) {
                        $validator->errors()->add('matrix', 'Terdapat jenis pegawai yang tidak dikenal.');

                        return;
                    }

                    $requiredKeys = $matrix[$typeId] ?? [];
                    if (is_array($requiredKeys) && count($requiredKeys) !== count(array_unique($requiredKeys))) {
                        $validator->errors()->add(
                            'matrix.'.$typeId,
                            'Kategori SK wajib dalam satu jenis pegawai tidak boleh duplikat.',
                        );
                    }

                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'matrix.required' => 'Matriks SK wajib harus dikirim.',
            'matrix.array' => 'Matriks SK wajib tidak valid.',
            'matrix.*.array' => 'Daftar SK wajib harus berupa array.',
            'matrix.*.*.in' => 'Terdapat kategori SK yang tidak dikenal.',
        ];
    }
}
