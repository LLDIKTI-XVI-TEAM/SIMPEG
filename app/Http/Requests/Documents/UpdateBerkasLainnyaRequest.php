<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use App\Models\Employee;
use App\Support\Documents\DocumentAuthorization;
use App\Support\Documents\DocumentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UpdateBerkasLainnyaRequest extends FormRequest
{
    /** Otorisasi tetap memeriksa pemilik dan kategori walau autentikasi API lokal dinonaktifkan. */
    public function authorize(): bool
    {
        $employee = $this->route('employee');
        $document = $this->route('document');
        $actorAllowed = DocumentAuthorization::allowsLocalApiBypass()
            || DocumentAuthorization::canUpdate($this->user());

        return $actorAllowed
            && $employee instanceof Employee
            && $document instanceof Document
            && hash_equals((string) $employee->id, (string) $document->employee_id)
            && DocumentCategory::isOtherUpload($document->jenis_dokumen);
    }

    public function rules(): array
    {
        return [
            'nama_dokumen' => ['required', 'string', 'max:255'],
            'kategori_dokumen' => ['required', 'string', Rule::in(DocumentCategory::otherUploadKeys())],
            'nomor_dokumen' => ['nullable', 'string', 'max:100'],
            'tanggal_terbit' => ['nullable', 'date'],
            'keterangan' => ['nullable', 'string', 'max:500'],
            'berkas' => [
                'nullable',
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
