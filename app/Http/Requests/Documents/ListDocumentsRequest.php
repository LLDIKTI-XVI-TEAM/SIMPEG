<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class ListDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (app()->environment('local')
            && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'kategori' => ['nullable', 'string', 'max:100'],
            'unit_kerja' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'in:tersedia,file_tidak_ditemukan'],
            // Dikirim tombol Refresh untuk menandai pemeriksaan filesystem terbaru.
            'refresh' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'in:5,10,25,50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'search' => 'Kata Pencarian',
            'kategori' => 'Kategori Dokumen',
            'unit_kerja' => 'Unit Kerja',
            'status' => 'Status Dokumen',
            'per_page' => 'Jumlah Data per Halaman',
            'page' => 'Halaman',
        ];
    }
}
