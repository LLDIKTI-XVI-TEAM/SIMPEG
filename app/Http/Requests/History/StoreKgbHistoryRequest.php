<?php

namespace App\Http\Requests\History;

use App\Support\SkFilePathRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreKgbHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Bypass otorisasi di environment lokal saat flag disable auth aktif.
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        // Mutasi riwayat KGB hanya boleh dilakukan oleh pengelola data kepegawaian.
        $user = $this->user();
        if (! $user) {
            return false;
        }

        return $user->hasPermission('employee_histories.create');
    }

    public function rules(): array
    {
        return [
            'tmt_kgb' => ['required', 'date'],
            'gaji_pokok' => ['required', 'numeric', 'min:0'],
            'no_sk' => ['required', 'string', 'max:100'],
            'tanggal_sk' => ['required', 'date'],
            'file_sk' => SkFilePathRules::nullableUploadOrControlledPath(),
        ];
    }

    public function messages(): array
    {
        return [
            'tmt_kgb.required' => 'TMT KGB wajib diisi.',
            'tmt_kgb.date' => 'Format TMT KGB tidak valid.',
            'gaji_pokok.required' => 'Gaji pokok wajib diisi.',
            'gaji_pokok.numeric' => 'Gaji pokok harus berupa angka.',
            'no_sk.required' => 'Nomor SK wajib diisi.',
            'tanggal_sk.required' => 'Tanggal SK wajib diisi.',
            'tanggal_sk.date' => 'Format Tanggal SK tidak valid.',
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
