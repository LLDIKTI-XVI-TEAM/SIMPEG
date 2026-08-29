<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Memvalidasi penetapan Kepala Bagian beserta tanggal mulai penugasannya.
 */
class AssignSupervisorRequest extends FormRequest
{
    /**
     * Membatasi mutasi pada dua role pengelola yang juga memiliki permission perubahan pegawai.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true)
            && $user->hasPermission('employees.update');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Penetapan Kepala Bagian memakai klasifikasi aktif kelompok referensi
            // (satu sumber dengan whereActiveStatus/isActive), bukan nama snapshot.
            'kepala_bagian_id' => ['nullable', 'uuid', Rule::exists('employees', 'id')->where(fn ($query) => $query->whereIn('id', Employee::query()->whereActiveStatus()->select('id')))],
            'supervisor_id' => ['nullable', 'uuid', Rule::exists('employees', 'id')->where(fn ($query) => $query->whereIn('id', Employee::query()->whereActiveStatus()->select('id')))],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            // Halaman asal non-default harus berasal dari whitelist agar redirect tidak bisa diarahkan ke URL bebas.
            'redirect_to' => ['nullable', 'string', 'in:cuti-config'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'kepala_bagian_id' => 'Kepala Bagian',
            'supervisor_id' => 'Kepala Bagian',
            'effective_date' => 'Tanggal Mulai Penugasan Kepala Bagian',
        ];
    }
}
