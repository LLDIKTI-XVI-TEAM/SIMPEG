<?php

namespace App\Http\Requests\Cuti;

use App\Services\Cuti\AnnualLeaveBusinessClock;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\UploadedFile;

class CorrectAnnualLeaveUsageRequest extends ReconcileAnnualLeaveUsageRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['balance_year'] = [
            'required',
            'integer',
            'between:1900,'.app(AnnualLeaveBusinessClock::class)->currentYear(),
        ];

        return array_merge($rules, [
            'correction_reason' => ['required', 'string', 'max:2000'],
            'dokumen' => ['required', 'file', 'max:10240', 'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png'],
        ]);
    }

    /** Nama asli diverifikasi terpisah agar MIME sah tidak dapat menyamarkan ekstensi skrip. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $document = $this->file('dokumen');

            if ($document instanceof UploadedFile
                && ! in_array(strtolower($document->getClientOriginalExtension()), ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'], true)) {
                $validator->errors()->add('dokumen', 'Ekstensi dokumen harus PDF, DOC, DOCX, JPG, JPEG, atau PNG.');
            }
        });
    }
}
