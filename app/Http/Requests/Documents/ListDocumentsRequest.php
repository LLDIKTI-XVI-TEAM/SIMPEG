<?php

namespace App\Http\Requests\Documents;

use App\Support\Documents\DocumentAuthorization;
use Illuminate\Foundation\Http\FormRequest;

class ListDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (DocumentAuthorization::allowsLocalApiBypass()) {
            return true;
        }

        return DocumentAuthorization::canBrowseArchive($this->user());
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'kategori' => ['nullable', 'string', 'max:100'],
            'employee_id' => ['nullable', 'uuid', 'exists:employees,id'],
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
            'employee_id' => 'Pegawai',
            'per_page' => 'Jumlah Data per Halaman',
            'page' => 'Halaman',
        ];
    }
}
