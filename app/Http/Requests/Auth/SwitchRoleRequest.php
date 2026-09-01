<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SwitchRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Invariant keamanan: Switch Role adalah exception yang hanya tersedia bagi
     * Super Admin asli dengan permission users.switch_role. Permission fitur
     * setelah simulasi tetap berasal dari effective role target.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        return $user->role === 'super_admin'
            && $user->hasOriginalRolePermission('users.switch_role');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * target_role dibatasi fail-closed ke role tujuan yang diizinkan.
     * temporary_permission hanyalah metadata simulasi (opsional) dan tidak pernah
     * menjadi sumber otorisasi; batas panjang sekadar pengaman penyimpanan.
     */
    public function rules(): array
    {
        return [
            'target_role' => ['required', 'string', 'in:admin_kepegawaian,pimpinan,kepala_bagian,pegawai'],
            'temporary_permission' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'target_role.required' => 'Role target wajib dipilih.',
            'target_role.in' => 'Role target tidak valid. Pilihan: admin_kepegawaian, pimpinan, kepala_bagian, pegawai.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->has('target_role')) {
                return;
            }

            $user = $this->user();
            $targetRole = $this->input('target_role');

            if ($user && is_string($targetRole) && ! $user->canSwitchToRole($targetRole)) {
                $validator->errors()->add(
                    'target_role',
                    "Tidak dapat switch ke role {$targetRole}. Role target harus lebih rendah dari role asli Anda."
                );
            }
        });
    }
}
