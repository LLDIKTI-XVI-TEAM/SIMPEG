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
        // Kalkulasi adalah bagian dari self-service pengajuan cuti PATEN.
        $actor = $this->user();

        return $actor !== null && in_array($actor->getEffectiveRole(), [
            'super_admin', 'admin_kepegawaian', 'kepala_bagian', 'pegawai',
        ], true);
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
