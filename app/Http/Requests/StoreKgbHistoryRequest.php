<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreKgbHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mutasi riwayat KGB hanya boleh dilakukan oleh pengelola data kepegawaian.
        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    public function rules(): array
    {
        return [
            'tmt_kgb' => ['required', 'date'],
            'gaji_pokok' => ['required', 'numeric', 'min:0'],
            'no_sk' => ['required', 'string', 'max:100'],
            'tanggal_sk' => ['required', 'date'],
            'file_sk' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'tmt_kgb' => 'TMT KGB',
            'gaji_pokok' => 'Gaji Pokok',
            'no_sk' => 'Nomor SK',
            'tanggal_sk' => 'Tanggal SK',
            'file_sk' => 'File SK',
        ];
    }
}
