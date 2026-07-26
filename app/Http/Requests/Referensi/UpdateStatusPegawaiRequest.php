<?php

namespace App\Http\Requests\Referensi;

use App\Models\RefStatusPegawai;
use App\Services\Referensi\ReferenceTableCatalog;
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

    /**
     * Kode dan nama baris status sistem dikunci: scheduler EWS memfilter
     * pegawai berdasarkan nama status aktif dan followup pensiun mencari
     * kode PENSIUN, sehingga mengubah identitas baris ini memutus alur
     * tersebut diam-diam. Field lain (kelompok, keterangan) tetap bebas.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $status = $this->route('statusPegawai');

            if (! $status instanceof RefStatusPegawai) {
                return;
            }

            if (ReferenceTableCatalog::protectionReason($status) === null) {
                return;
            }

            if ($this->input('kode') !== $status->kode) {
                $validator->errors()->add('kode', 'Kode status sistem tidak dapat diubah karena dipakai logika aplikasi.');
            }

            if ($this->input('nama') !== $status->nama) {
                $validator->errors()->add('nama', 'Nama status sistem tidak dapat diubah karena dipakai logika aplikasi.');
            }
        });
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
