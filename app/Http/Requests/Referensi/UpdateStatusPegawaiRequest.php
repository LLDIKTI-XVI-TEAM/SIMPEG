<?php

namespace App\Http\Requests\Referensi;

use App\Models\RefStatusPegawai;
use App\Services\Referensi\ReferenceUsageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStatusPegawaiRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Data referensi memengaruhi seluruh dropdown dan riwayat pegawai;
        // hanya super_admin yang boleh mengelolanya (lapisan kedua di atas
        // role middleware pada route).
        return $this->user()?->role === 'super_admin';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $status = $this->route('statusPegawai');
        $statusId = $status instanceof RefStatusPegawai ? $status->id : null;

        // is_default sengaja tidak divalidasi/diteruskan (lihat catatan di
        // StoreStatusPegawaiRequest).
        return [
            'kode' => ['required', 'string', 'max:50', Rule::unique('ref_status_pegawai', 'kode')->ignore($statusId)],
            'nama' => ['required', 'string', 'max:50', Rule::unique('ref_status_pegawai', 'nama')->ignore($statusId)],
            'kelompok' => ['required', 'string', 'max:50'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Menyamakan guard identitas dan klasifikasi dengan boundary Action. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $status = $this->route('statusPegawai');

            if (! $status instanceof RefStatusPegawai) {
                return;
            }

            $errors = app(ReferenceUsageService::class)
                ->statusMutationErrors($status, $this->all());

            foreach ($errors as $field => $message) {
                if (! $validator->errors()->has($field)) {
                    $validator->errors()->add($field, $message);
                }
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kelompok.required' => ReferenceUsageService::GROUP_INVALID_MESSAGE,
            'kelompok.string' => ReferenceUsageService::GROUP_INVALID_MESSAGE,
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'kode' => 'Kode status pegawai',
            'nama' => 'Nama status pegawai',
            'kelompok' => 'Kelompok status',
            'keterangan' => 'Keterangan',
        ];
    }
}
