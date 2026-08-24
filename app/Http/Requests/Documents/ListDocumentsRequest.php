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

        return DocumentAuthorization::canViewArchive($this->user());
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'kategori' => ['nullable', 'string', 'max:100'],
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
            'per_page' => 'Jumlah Data per Halaman',
            'page' => 'Halaman',
        ];
    }
}
