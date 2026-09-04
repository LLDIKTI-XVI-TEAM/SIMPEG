<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi preview saldo milik Pegawai login. Employee tidak pernah diterima
 * dari query string agar pengguna tidak dapat membaca saldo pegawai lain.
 */
class PreviewLeaveBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor?->employee !== null
            && $actor->hasPermission('cuti.create');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'tanggal_mulai' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function attributes(): array
    {
        return [
            'tanggal_mulai' => 'tanggal mulai',
        ];
    }
}
