<?php

namespace App\Http\Requests\Auth;

use App\Models\Role;
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

            // temporary_permission hanya boleh memuat permission yang benar-benar dimiliki role target.
            // Daftar dibentuk/divalidasi server-side sebagai subset izin, bukan dipercaya mentah dari payload.
            $temporaryPermission = $this->input('temporary_permission');
            if (is_string($targetRole) && is_string($temporaryPermission) && $temporaryPermission !== '') {
                $allowed = Role::query()
                    ->where('name', $targetRole)
                    ->with('permissions')
                    ->first()
                    ?->permissions
                    ->pluck('name')
                    ->all() ?? [];

                foreach ($this->parsePermissionList($temporaryPermission) as $permission) {
                    if (! in_array($permission, $allowed, true)) {
                        $validator->errors()->add(
                            'temporary_permission',
                            "Permission '{$permission}' tidak dimiliki role target {$targetRole} sehingga tidak dapat dijadikan permission sementara."
                        );
                    }
                }
            }
        });
    }

    /**
     * Mengurai daftar permission dari JSON array atau CSV.
     *
     * @return list<string>
     */
    private function parsePermissionList(string $value): array
    {
        $decoded = json_decode($value, true);

        $items = is_array($decoded) ? $decoded : explode(',', $value);

        $permissions = [];

        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }

            $permission = trim($item);

            if ($permission !== '') {
                $permissions[] = $permission;
            }
        }

        return array_values(array_unique($permissions));
    }
}
