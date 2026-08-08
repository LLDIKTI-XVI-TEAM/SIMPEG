<?php

namespace App\Http\Requests\Rbac;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveRolePermissionMatrixRequest extends FormRequest
{
    /**
     * Hanya Super Admin boleh mengubah kewenangan peran, karena perubahan ini berlaku seketika
     * bagi seluruh pengguna yang memegang peran tersebut.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    /**
     * Peramban tidak mengirim kunci peran ketika seluruh centangnya dilepas, sehingga matriks
     * boleh tidak ada sama sekali dan ketiadaan kunci berarti hak akses peran itu dikosongkan.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'matrix' => ['sometimes', 'array'],
            'matrix.*' => ['array'],
            'matrix.*.*' => ['uuid', 'exists:permissions,id'],
        ];
    }

    /**
     * Kunci matriks adalah pengenal peran dan tidak dapat divalidasi lewat daftar aturan biasa.
     * Pengenal yang tidak dikenal ditolak agar sinkronisasi tidak berjalan atas peran yang keliru.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var array<string, mixed> $matrix */
                $matrix = $this->input('matrix', []);
                $roleIds = array_keys($matrix);

                if ($roleIds === []) {
                    return;
                }

                $dikenal = Role::query()->whereIn('id', $roleIds)->pluck('id')->all();

                foreach ($roleIds as $roleId) {
                    if (! in_array($roleId, $dikenal, true)) {
                        $validator->errors()->add('matrix', 'Terdapat peran yang tidak dikenal pada matriks hak akses.');

                        return;
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'matrix.array' => 'Matriks hak akses tidak valid.',
            'matrix.*.*.uuid' => 'Terdapat pengenal hak akses yang tidak valid.',
            'matrix.*.*.exists' => 'Terdapat hak akses yang tidak ditemukan.',
        ];
    }
}
