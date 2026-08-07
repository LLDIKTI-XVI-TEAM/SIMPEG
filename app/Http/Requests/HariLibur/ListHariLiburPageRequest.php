<?php

namespace App\Http\Requests\HariLibur;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListHariLiburPageRequest extends FormRequest
{
    /**
     * Kalender hari libur adalah konfigurasi sistem yang memengaruhi kalkulasi
     * hari kerja cuti dan jadwal EWS, sehingga pembacaannya tetap dibatasi
     * Super Admin walaupun route sudah memasang gerbang role yang sama.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    public function rules(): array
    {
        return [
            'tahun' => ['nullable', 'integer', 'between:1900,2200'],
            'tipe' => ['nullable', Rule::in(['libur_nasional', 'cuti_bersama'])],
            'search' => ['nullable', 'string', 'max:100'],
            // Jumlah baris dibatasi allowlist agar query halaman tidak bisa
            // dipaksa memuat seluruh tabel lewat query string.
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
        ];
    }

    public function attributes(): array
    {
        return [
            'tahun' => 'tahun',
            'tipe' => 'tipe hari libur',
            'search' => 'kata pencarian',
            'per_page' => 'jumlah baris per halaman',
        ];
    }
}
