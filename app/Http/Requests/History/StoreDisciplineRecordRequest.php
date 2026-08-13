<?php

namespace App\Http\Requests\History;

use App\Models\Employee;
use App\Support\SkFilePathRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDisciplineRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Bypass otorisasi di environment lokal saat flag disable auth aktif.
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        // Mutasi hukuman disiplin hanya boleh dilakukan pengelola data kepegawaian.
        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    public function rules(): array
    {
        $employee = $this->route('employee');
        $employeeId = $employee instanceof Employee ? $employee->id : $employee;

        return [
            'jenis_hukuman' => ['required', Rule::in(['Ringan', 'Sedang', 'Berat'])],
            'deskripsi' => ['required', 'string', 'max:2000'],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_berakhir' => ['nullable', 'date', 'after_or_equal:tanggal_mulai'],
            'no_sk' => ['required', 'string', 'max:100'],
            'tanggal_sk' => ['required', 'date'],
            'file_sk' => SkFilePathRules::nullableUploadOrControlledPath(),
            // Arsip SK hanya boleh dipakai oleh riwayat pegawai pemiliknya agar berkas privat tidak berpindah scope.
            'dokumen_id' => [
                'nullable',
                'uuid',
                Rule::exists('documents', 'id')
                    ->where('employee_id', $employeeId)
                    ->where('jenis_dokumen', 'sk_hukuman_disiplin'),
            ],
        ];
    }
}
