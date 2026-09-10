<?php

namespace App\Http\Requests\Documents;

use App\Support\Documents\DocumentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->hasPermission('dokumen_sk.create');
    }

    public function rules(): array
    {
        return [
            'nama_dokumen' => ['required', 'string', 'max:255'],
            'nomor_dokumen' => ['nullable', 'string', 'max:100'],
            'tanggal_terbit' => ['nullable', 'date'],
            'kategori_dokumen' => [
                'required',
                'string',
                Rule::in(array_filter(DocumentCategory::editableKeys(), fn ($key) => $key !== 'sk_status_pegawai')),
            ],
            'pegawai_id' => ['required', 'uuid', 'exists:employees,id'],
            'deskripsi' => ['nullable', 'string'],
            'berkas' => [
                'required',
                File::types(DocumentCategory::ALLOWED_FILE_TYPES)->max('10mb'),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'nama_dokumen' => 'nama dokumen',
            'nomor_dokumen' => 'nomor dokumen',
            'tanggal_terbit' => 'tanggal dokumen',
            'kategori_dokumen' => 'kategori dokumen',
            'pegawai_id' => 'pegawai',
            'berkas' => 'berkas dokumen',
        ];
    }
}
