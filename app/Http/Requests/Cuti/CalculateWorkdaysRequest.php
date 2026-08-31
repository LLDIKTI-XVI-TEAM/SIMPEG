<?php

namespace App\Http\Requests\Cuti;

use App\Support\Rbac\CutiPermissionMatrixPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi parameter kalkulasi hari kerja untuk form pengajuan cuti.
 * Otorisasi ditegakkan ganda: middleware route (permission:cuti.create) dan authorize() ini
 * agar backend tidak hanya bergantung pada penyembunyian menu/tombol di UI.
 */
class CalculateWorkdaysRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Hanya pengguna dengan hak mengajukan cuti yang boleh memakai kalkulasi hari kerja.
        $actor = $this->user();

        return $actor !== null
            && CutiPermissionMatrixPolicy::isAssignableToRole('cuti.create', (string) $actor->getEffectiveRole())
            && $actor->hasPermission('cuti.create');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'start' => ['required', 'date_format:Y-m-d'],
            // Tanggal selesai tidak boleh mendahului tanggal mulai agar rentang selalu valid.
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
        ];
    }

    public function attributes(): array
    {
        return [
            'start' => 'tanggal mulai',
            'end' => 'tanggal selesai',
        ];
    }
}
