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

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
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
