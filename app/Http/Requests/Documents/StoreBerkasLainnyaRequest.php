<?php

namespace App\Http\Requests\Documents;

use App\Support\Documents\DocumentAuthorization;
use App\Support\Documents\DocumentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreBerkasLainnyaRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (DocumentAuthorization::allowsLocalApiBypass()) {
            return true;
        }

        return DocumentAuthorization::canCreate($this->user());
    }

    public function rules(): array
    {
        return [
            'nama_dokumen' => ['required', 'string', 'max:255'],
            'kategori_dokumen' => ['required', 'string', 'in:'.implode(',', DocumentCategory::otherUploadKeys())],
            'nomor_dokumen' => ['nullable', 'string', 'max:100'],
            'tanggal_terbit' => ['nullable', 'date'],
            'keterangan' => ['nullable', 'string', 'max:500'],
            'berkas' => [
                'required',
                File::types(DocumentCategory::ALLOWED_FILE_TYPES)->max('10mb'),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'nama_dokumen' => 'nama berkas',
            'kategori_dokumen' => 'kategori',
            'berkas' => 'berkas',
        ];
    }
}
