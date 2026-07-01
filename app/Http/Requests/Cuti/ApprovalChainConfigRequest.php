<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi perubahan konfigurasi rantai approval cuti (penentuan approver stage 2 dan stage 3).
 * Otorisasi ditegakkan ganda di backend: middleware route (role:super_admin + permission:cuti.configure)
 * dan authorize() di sini, supaya keamanan tidak hanya bergantung pada penyembunyian menu/tombol di UI.
 */
class ApprovalChainConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Konfigurasi rantai approval adalah pengaturan tingkat sistem, jadi sengaja dibatasi
        // hanya untuk pemegang permission cuti.configure (di Fase 1 hanya super_admin).
        return (bool) $this->user()?->hasPermission('cuti.configure');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Approver stage 2 dan stage 3 harus merujuk akun pengguna yang benar-benar ada
            // agar pengajuan cuti tidak pernah diarahkan ke approver yang tidak valid.
            'stage2_approver_id' => ['required', 'exists:users,id'],
            // Boleh sama dengan stage 2; duplikasi approver ditangani oleh skip-logic di engine approval,
            // sehingga di sini sengaja tidak dipaksa berbeda.
            'stage3_approver_id' => ['required', 'exists:users,id'],
            // Alasan wajib agar setiap perubahan konfigurasi punya jejak audit yang bisa dipertanggungjawabkan.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stage2_approver_id.required' => 'Approver Stage 2 wajib dipilih.',
            'stage2_approver_id.exists' => 'Approver Stage 2 tidak valid.',
            'stage3_approver_id.required' => 'Approver Stage 3 wajib dipilih.',
            'stage3_approver_id.exists' => 'Approver Stage 3 tidak valid.',
            'reason.required' => 'Alasan perubahan wajib diisi.',
            'reason.min' => 'Alasan perubahan minimal berisi 5 karakter.',
            'reason.max' => 'Alasan perubahan maksimal 500 karakter.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'stage2_approver_id' => 'approver stage 2',
            'stage3_approver_id' => 'approver stage 3',
            'reason' => 'alasan perubahan',
        ];
    }
}
