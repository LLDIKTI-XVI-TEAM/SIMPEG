<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use App\Models\Employee;
use App\Support\Documents\DocumentAuthorization;
use App\Support\Documents\DocumentCategory;
use Illuminate\Foundation\Http\FormRequest;

class DeleteBerkasLainnyaRequest extends FormRequest
{
    /** Penghapusan hanya berlaku untuk Berkas Lainnya milik pegawai pada URL. */
    public function authorize(): bool
    {
        $employee = $this->route('employee');
        $document = $this->route('document');
        $actorAllowed = DocumentAuthorization::allowsLocalApiBypass()
            || DocumentAuthorization::canDelete($this->user());

        return $actorAllowed
            && $employee instanceof Employee
            && $document instanceof Document
            && hash_equals((string) $employee->id, (string) $document->employee_id)
            && DocumentCategory::isOtherUpload($document->jenis_dokumen);
    }

    public function rules(): array
    {
        return [];
    }
}
