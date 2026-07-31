<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi input saldo awal cuti tahunan.
 * Otorisasi ditegakkan ganda: route memakai role+permission, request tetap mengecek permission agar form tidak jadi satu-satunya pagar.
 */
class OpeningLeaveBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('cuti.balance.adjust');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'tahun' => ['required', 'integer', 'digits:4', 'min:2000', 'max:2100'],
            // Bucket saldo awal dipisah agar asal hak N-2/N-1/tahun berjalan tetap terbaca di audit.
            'sisa_n2' => ['required', 'integer', 'min:0', 'max:24'],
            'sisa_n1' => ['required', 'integer', 'min:0', 'max:24'],
            'sisa_tahun_berjalan' => ['required', 'integer', 'min:0', 'max:24'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'status' => ['nullable', 'in:perlu_tindakan,sudah_terdaftar,semua_pegawai'],
            'search' => ['nullable', 'string', 'max:150'],
            'tab' => ['nullable', 'in:pendaftaran,koreksi'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tahun.required' => 'Tahun saldo wajib diisi.',
            'tahun.digits' => 'Tahun saldo harus 4 digit.',
            'sisa_n2.required' => 'Saldo N-2 wajib diisi.',
            'sisa_n1.required' => 'Saldo N-1 wajib diisi.',
            'sisa_tahun_berjalan.required' => 'Saldo tahun berjalan wajib diisi.',
            'reason.required' => 'Alasan input saldo awal wajib diisi.',
            'reason.min' => 'Alasan input saldo awal minimal berisi 5 karakter.',
        ];
    }
}
