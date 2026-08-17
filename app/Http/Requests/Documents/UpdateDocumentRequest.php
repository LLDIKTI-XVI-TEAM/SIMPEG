<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use App\Support\Documents\DocumentAuthorization;
use App\Support\Documents\DocumentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (DocumentAuthorization::allowsLocalApiBypass()) {
            return true;
        }

        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    public function rules(): array
    {
        $param = $this->route('dokuman') ?? $this->route('id') ?? $this->route('document');

        /** @var Document|null $document */
        $document = $param instanceof Document ? $param : (is_scalar($param) ? Document::find($param) : null);

        $isStatusDoc = $document !== null && $document->jenis_dokumen === 'sk_status_pegawai';

        // Target category dibatasi ke berkas non-SK agar direct API caller tidak
        // dapat mereklasifikasi ijazah/ktp_kk/lainnya menjadi kategori SK tanpa
        // melewati jalur riwayat append-only dan permission employee_histories.create.
        $kategoriRules = $isStatusDoc
            ? ['required', 'string', Rule::in(['sk_status_pegawai'])]
            : ['required', 'string', Rule::in(DocumentCategory::otherUploadKeys())];

        return [
            'nama_dokumen' => ['required', 'string', 'max:255'],
            'nomor_dokumen' => ['nullable', 'string', 'max:100'],
            'tanggal_terbit' => ['nullable', 'date'],
            'kategori_dokumen' => $kategoriRules,
            'deskripsi' => ['nullable', 'string'],
            'berkas' => [
                'nullable', // Optional when updating
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
            'berkas' => 'berkas dokumen',
        ];
    }
}
