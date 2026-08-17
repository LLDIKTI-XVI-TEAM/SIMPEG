<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SwitchRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Hanya user dengan permission users.switch_role secara efektif yang boleh switch role.
     * Tidak ada bypass raw role asli; simulasi harus mengikuti permission efektif.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        return $user->hasPermission('users.switch_role');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'target_role' => ['required', 'string', 'in:admin_kepegawaian,pimpinan,kepala_bagian,pegawai'],
            'temporary_permission' => ['nullable', 'string'],
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
            $user = $this->user();
            $targetRole = $this->input('target_role');

            if ($user && $targetRole && ! $user->canSwitchToRole($targetRole)) {
                $validator->errors()->add(
                    'target_role',
                    "Tidak dapat switch ke role {$targetRole}. Role target harus lebih rendah dari role asli Anda."
                );
            }
        });
    }
}
