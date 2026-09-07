<?php

namespace App\Http\Requests\History;

use App\Support\SkFilePathRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreRankHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Bypass otorisasi di environment lokal saat flag disable auth aktif.
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        // Mutasi riwayat pangkat hanya boleh dilakukan oleh pengelola data kepegawaian.
        $user = $this->user();
        if (! $user) {
            return false;
        }

        return $user->hasPermission('employee_histories.create');
    }

    public function rules(): array
    {
        return [
            'golongan_id' => ['required', 'uuid', 'exists:ref_golongan,id'],
            'tmt_pangkat' => ['required', 'date'],
            'no_sk' => ['required', 'string', 'max:100'],
            'tanggal_sk' => ['required', 'date'],
            'file_sk' => SkFilePathRules::nullableUploadOrControlledPath(),
        ];
    }

    public function messages(): array
    {
        return [
            'golongan_id.required' => 'Golongan wajib dipilih.',
            'golongan_id.uuid' => 'Format ID Golongan tidak valid.',
            'golongan_id.exists' => 'Golongan yang dipilih tidak valid.',
            'tmt_pangkat.required' => 'TMT Pangkat wajib diisi.',
            'tmt_pangkat.date' => 'Format TMT Pangkat tidak valid.',
            'no_sk.required' => 'Nomor SK wajib diisi.',
            'tanggal_sk.required' => 'Tanggal SK wajib diisi.',
            'tanggal_sk.date' => 'Format Tanggal SK tidak valid.',
        ];
    }

    public function attributes(): array
    {
        return [
            'golongan_id' => 'Golongan',
            'tmt_pangkat' => 'TMT Pangkat',
            'no_sk' => 'Nomor SK',
            'tanggal_sk' => 'Tanggal SK',
            'file_sk' => 'File SK',
        ];
    }
}
