<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi parameter kalkulasi hari kerja untuk form pengajuan cuti.
 * Otorisasi mengunci konteks self-service agar backend tidak bergantung pada
 * penyembunyian menu/tombol di UI.
 */
class CalculateWorkdaysRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Kalkulasi mengikuti identitas PATEN pengajuan, bukan allowlist role atau pivot RBAC.
        return $this->user()?->hasPermission('cuti.create') ?? false;
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
