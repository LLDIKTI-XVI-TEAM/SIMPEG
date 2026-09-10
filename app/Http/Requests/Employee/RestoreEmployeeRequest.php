<?php

namespace App\Http\Requests\Employee;

use App\Models\User;
use App\Services\Employees\EmployeeLifecycleAuthorization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Kontrak perubahan status resmi saat pegawai nonaktif diaktifkan kembali (US-2.10 AC-4).
 *
 * Pemulihan adalah perubahan status administrasi resmi: role efektif apa pun yang
 * memiliki permission employees.restore boleh menjalankannya (dikelola lewat RBAC
 * matrix), dengan tanggal efektif dan alasan wajib; riwayat status serta audit
 * ditulis oleh RestoreEmployeeAction dalam satu transaksi.
 */
class RestoreEmployeeRequest extends FormRequest
{
    /**
     * Pemulihan pegawai memerlukan permission employees.restore pada role efektif.
     * hasPermission() sudah memperhitungkan temporary_role sehingga simulasi role
     * tidak dibypass oleh role asli.
     */
    public function authorize(): bool
    {
        // Menjaga kontrak bypass API lokal yang sudah dipakai route pegawai. Flag ini hanya
        // berlaku di environment local sehingga gate role/permission produksi tetap utuh.
        if (app()->environment('local')
            && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $user = $this->user();

        // K-STATUS-04: role efektif dan permission harus lolos bersama agar drift
        // role_permissions tidak memperluas kewenangan reaktivasi secara diam-diam.
        return $user instanceof User
            && app(EmployeeLifecycleAuthorization::class)->canRestore($user);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'tanggal_efektif' => ['required', 'date'],
            'alasan' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tanggal_efektif.required' => 'Tanggal efektif pengaktifan kembali wajib diisi.',
            'tanggal_efektif.date' => 'Tanggal efektif pengaktifan kembali tidak valid.',
            'alasan.required' => 'Alasan pengaktifan kembali wajib diisi.',
            'alasan.min' => 'Alasan pengaktifan kembali minimal 3 karakter.',
            'alasan.max' => 'Alasan pengaktifan kembali maksimal 2000 karakter.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'tanggal_efektif' => 'Tanggal Efektif',
            'alasan' => 'Alasan Pengaktifan Kembali',
        ];
    }
}
