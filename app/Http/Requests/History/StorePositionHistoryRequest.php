<?php

namespace App\Http\Requests\History;

use App\Support\SkFilePathRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePositionHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Bypass otorisasi di environment lokal saat flag disable auth aktif.
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        // Mutasi riwayat jabatan hanya boleh dilakukan oleh pengelola data kepegawaian.
        $user = $this->user();
        if (! $user) {
            return false;
        }

        return in_array($user->role, ['super_admin', 'admin_kepegawaian'], true)
            || in_array($user->getEffectiveRole(), ['super_admin', 'admin_kepegawaian'], true)
            || $user->hasPermission('employee_histories.create');
    }

    public function rules(): array
    {
        return [
            // Jabatan yang sudah dinonaktifkan tidak boleh dipakai pada riwayat baru,
            // sementara riwayat lama yang menunjuknya tetap tampil utuh. Penolakan
            // ditegakkan di sini karena penyaringan dropdown di tampilan tidak mengikat.
            'jabatan_id' => ['required', 'uuid', Rule::exists('ref_jabatan', 'id')->where('is_active', true)],
            'nama_jabatan' => ['nullable', 'string', 'max:255'],
            'jenis_jabatan_id' => ['nullable', 'uuid', 'exists:ref_jenis_jabatan,id'],
            'eselon_id' => ['nullable', 'uuid', 'exists:ref_eselon,id'],
            'unit_kerja_id' => ['required', 'uuid', 'exists:ref_unit_kerja,id'],
            'kelas_jabatan' => ['nullable', 'string', 'max:10'],
            'tmt_jabatan' => ['required', 'date'],
            'no_sk' => ['required', 'string', 'max:100'],
            'tanggal_sk' => ['required', 'date'],
            'file_sk' => SkFilePathRules::nullableUploadOrControlledPath(),
        ];
    }

    public function messages(): array
    {
        return [
            'jabatan_id.required' => 'Jabatan wajib dipilih.',
            'jabatan_id.uuid' => 'Format ID Jabatan tidak valid.',
            'jabatan_id.exists' => 'Jabatan yang dipilih tidak valid atau sudah tidak aktif.',
            'unit_kerja_id.required' => 'Unit Kerja wajib dipilih.',
            'unit_kerja_id.uuid' => 'Format ID Unit Kerja tidak valid.',
            'unit_kerja_id.exists' => 'Unit Kerja yang dipilih tidak valid.',
            'tmt_jabatan.required' => 'TMT Jabatan wajib diisi.',
            'tmt_jabatan.date' => 'Format TMT Jabatan tidak valid.',
            'no_sk.required' => 'Nomor SK wajib diisi.',
            'tanggal_sk.required' => 'Tanggal SK wajib diisi.',
            'tanggal_sk.date' => 'Format Tanggal SK tidak valid.',
        ];
    }

    public function attributes(): array
    {
        return [
            'jabatan_id' => 'Jabatan',
            'nama_jabatan' => 'Nama Jabatan',
            'jenis_jabatan_id' => 'Jenis Jabatan',
            'eselon_id' => 'Eselon',
            'unit_kerja_id' => 'Unit Kerja',
            'kelas_jabatan' => 'Kelas Jabatan',
            'tmt_jabatan' => 'TMT Jabatan',
            'no_sk' => 'Nomor SK',
            'tanggal_sk' => 'Tanggal SK',
            'file_sk' => 'File SK',
        ];
    }
}
