<?php

namespace App\Http\Requests\Employee;

use App\Services\Employees\EmployeeStatusLifecycleService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Kontrak perubahan status resmi saat pegawai dinonaktifkan (US-2.9 AC-1/AC-2/AC-5/AC-7).
 *
 * Nonaktif adalah mutasi status kepegawaian biasa: tanggal efektif dan alasan wajib,
 * riwayat status serta audit dibuat oleh DeactivateEmployeeAction dalam satu transaksi.
 */
class DeactivateEmployeeRequest extends FormRequest
{
    /** Pesan baku bila super admin tidak mengisi pesan nonaktif. */
    public const DEFAULT_NOTE = EmployeeStatusLifecycleService::DEFAULT_DEACTIVATION_NOTE;

    /**
     * Hanya pengelola pegawai yang memiliki permission eksplisit boleh menonaktifkan data.
     * hasPermission() berbasis role efektif, sehingga simulasi role tidak dibypass oleh role asli.
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

        // K-STATUS-04: otorisasi sepenuhnya berdasarkan permission dari role efektif.
        // hasPermission() sudah memperhitungkan temporary_role sehingga simulasi
        // super_admin ke role lain tidak dibypass oleh role asli.
        return $user !== null
            && $user->hasPermission('employees.deactivate');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'status_note' => ['nullable', 'string', 'max:2000'],
            'tanggal_efektif' => ['required', 'date'],
            'alasan' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tanggal_efektif.required' => 'Tanggal efektif nonaktif wajib diisi.',
            'tanggal_efektif.date' => 'Tanggal efektif nonaktif tidak valid.',
            'alasan.required' => 'Alasan penonaktifan wajib diisi.',
            'alasan.min' => 'Alasan penonaktifan minimal 3 karakter.',
            'alasan.max' => 'Alasan penonaktifan maksimal 2000 karakter.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'tanggal_efektif' => 'Tanggal Efektif',
            'alasan' => 'Alasan Penonaktifan',
        ];
    }
}
