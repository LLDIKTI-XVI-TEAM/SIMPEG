<?php

namespace App\Http\Requests\History;

use App\Support\SkFilePathRules;
use Illuminate\Foundation\Http\FormRequest;

class StorePositionHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mutasi riwayat jabatan hanya boleh dilakukan oleh pengelola data kepegawaian.
        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    public function rules(): array
    {
        return [
            'nama_jabatan' => ['required', 'string', 'max:255'],
            'jenis_jabatan_id' => ['required', 'uuid', 'exists:ref_jenis_jabatan,id'],
            'eselon_id' => ['nullable', 'uuid', 'exists:ref_eselon,id'],
            'unit_kerja_id' => ['required', 'uuid', 'exists:ref_unit_kerja,id'],
            'tmt_jabatan' => ['required', 'date'],
            'no_sk' => ['required', 'string', 'max:100'],
            'tanggal_sk' => ['required', 'date'],
            'file_sk' => SkFilePathRules::nullableUploadOrControlledPath(),
        ];
    }

    public function attributes(): array
    {
        return [
            'nama_jabatan' => 'Nama Jabatan',
            'jenis_jabatan_id' => 'Jenis Jabatan',
            'eselon_id' => 'Eselon',
            'unit_kerja_id' => 'Unit Kerja',
            'tmt_jabatan' => 'TMT Jabatan',
            'no_sk' => 'Nomor SK',
            'tanggal_sk' => 'Tanggal SK',
            'file_sk' => 'File SK',
        ];
    }
}
