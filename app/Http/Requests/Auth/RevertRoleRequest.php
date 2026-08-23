<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RevertRoleRequest extends FormRequest
{
    /**
     * Jalur pemulihan role harus tetap berjalan selama simulasi aktif, termasuk ketika
     * permission users.switch_role tidak lagi dimiliki role efektif. Karena itu otorisasi
     * cukup mensyaratkan user terautentikasi (tanpa gate role/permission) agar revert
     * selalu menjadi escape path yang aman bila state simulasi tidak valid.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
