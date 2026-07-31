<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi koreksi manual saldo cuti.
 * Koreksi wajib punya alasan manusia karena ledger bersifat append-only dan menjadi bukti administratif.
 */
class AdjustLeaveBalanceRequest extends FormRequest
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
            'bucket' => ['required', 'in:n2,n1,current'],
            'amount' => ['required', 'integer', 'not_in:0', 'min:-24', 'max:24'],
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
            'bucket.required' => 'Bucket saldo wajib dipilih.',
            'bucket.in' => 'Bucket saldo tidak valid.',
            'amount.required' => 'Jumlah koreksi wajib diisi.',
            'amount.not_in' => 'Jumlah koreksi tidak boleh nol.',
            'reason.required' => 'Alasan koreksi wajib diisi.',
            'reason.min' => 'Alasan koreksi minimal berisi 5 karakter.',
        ];
    }
}
