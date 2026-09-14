<?php

namespace App\Http\Requests\Referensi;

use Illuminate\Foundation\Http\FormRequest;

class StoreJenisJabatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('reference_tables.manage');
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            // Tabel ini tidak punya kolom kode dan tidak punya unique
            // constraint di database, padahal seeder dan fallback riwayat
            // mencocokkan jenis jabatan berdasarkan nama; validasi ini
            // menjadi penjaga tunggal keunikan nama.
            'nama' => 'required|string|max:100|unique:ref_jenis_jabatan,nama',
            // Batas 1-100 hanyalah pagar teknis pencegah salah ketik, bukan
            // aturan bisnis; nilai BUP final tetap ditentukan admin lewat
            // referensi ini.
            'maks_usia_pensiun' => 'required|integer|min:1|max:100',
            'catatan' => 'nullable|string|max:255',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'nama' => 'Nama jenis jabatan',
            'maks_usia_pensiun' => 'Maksimal usia pensiun',
            'catatan' => 'Catatan',
        ];
    }
}
