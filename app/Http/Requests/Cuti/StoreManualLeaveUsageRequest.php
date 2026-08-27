<?php

namespace App\Http\Requests\Cuti;

use App\Models\User;
use App\Services\Cuti\LeaveUsageAuthorizationService;
use App\Services\Cuti\ManualExternalApprovalChainService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreManualLeaveUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        app(LeaveUsageAuthorizationService::class)->assertCanManageManual($user);

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'leave_type_id' => ['bail', 'required', 'uuid', 'exists:ref_jenis_cuti,id'],
            'leave_request_case_id' => ['bail', 'nullable', 'uuid', 'exists:leave_request_cases,id'],
            'tanggal_mulai' => ['required', 'date_format:Y-m-d'],
            'tanggal_selesai' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_mulai'],
            'alasan' => ['required', 'string', 'max:2000'],
            'approval_document_number' => ['nullable', 'string', 'max:255'],
            'approval_steps' => ['required', 'array', 'min:2', 'max:10'],
            'approval_steps.*' => ['required', 'array'],
            'approval_steps.*.step_type' => ['required', 'string'],
            'approval_steps.*.approver_source' => ['required', 'string'],
            'approval_steps.*.approver_employee_id' => ['nullable', 'string'],
            'approval_steps.*.approver_name' => ['nullable', 'string', 'max:255'],
            'approval_steps.*.approver_position' => ['nullable', 'string', 'max:255'],
            'approval_steps.*.approver_institution' => ['nullable', 'string', 'max:255'],
            'approval_steps.*.acted_on' => ['required', 'date_format:Y-m-d'],
            'approval_steps.*.decision_note' => ['nullable', 'string', 'max:2000'],
            'approval_steps.*.step_order' => ['prohibited'],
            'approval_steps.*.result_code' => ['prohibited'],
            'dokumen' => ['nullable', 'file', 'max:10240', 'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png'],
        ];
    }

    /** Memastikan nama asli tidak menyamarkan skrip sebagai berkas dengan MIME yang diizinkan. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isEmpty()) {
                foreach (app(ManualExternalApprovalChainService::class)->violations($this->input('approval_steps')) as $path => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($path, $message);
                    }
                }
            }

            $document = $this->file('dokumen');

            if ($document instanceof UploadedFile
                && ! in_array(strtolower($document->getClientOriginalExtension()), ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'], true)) {
                $validator->errors()->add('dokumen', 'Ekstensi dokumen harus PDF, DOC, DOCX, JPG, JPEG, atau PNG.');
            }
        });
    }
}
