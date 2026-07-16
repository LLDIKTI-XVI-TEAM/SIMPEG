<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi unggahan dokumen pendukung Kepala Lembaga dengan allowlist berkas privat.
 */
class StoreKepalaLembagaSupportingDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi peran dan permission ditegakkan oleh middleware rute.
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'berkas' => [
                'required',
                'file',
                'max:10240',
                'mimes:pdf,doc,docx,jpg,jpeg,png',
                'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png',
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'berkas' => 'berkas dokumen pendukung',
        ];
    }
}
