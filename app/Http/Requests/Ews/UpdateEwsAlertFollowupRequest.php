<?php

namespace App\Http\Requests\Ews;

use App\Models\EwsAlert;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class UpdateEwsAlertFollowupRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Tindak lanjut EWS memutasi riwayat pangkat/KGB dan status pensiun pegawai,
        // sehingga permission dicek di backend dan tidak boleh hanya mengandalkan
        // pembatasan role di route atau penyembunyian tombol di UI.
        return $this->user()?->hasPermission('employees.update') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'followup_status' => [
                'required',
                Rule::in(EwsAlert::manualFollowupStatuses()),
            ],
            'handled_note' => ['required', 'string', 'max:1000'],
            'golongan_id' => [
                Rule::requiredIf($this->isRankApproval()),
                'nullable',
                'uuid',
                'exists:ref_golongan,id',
            ],
            'tmt_pangkat' => [Rule::requiredIf($this->isRankApproval()), 'nullable', 'date'],
            'tmt_kgb' => [Rule::requiredIf($this->isKgbApproval()), 'nullable', 'date'],
            'gaji_pokok' => [Rule::requiredIf($this->isKgbApproval()), 'nullable', 'numeric', 'min:0'],
            'no_sk' => [Rule::requiredIf($this->requiresHistoryCompletion()), 'nullable', 'string', 'max:100'],
            'tanggal_sk' => [Rule::requiredIf($this->requiresHistoryCompletion()), 'nullable', 'date'],
            'file_sk' => [
                Rule::requiredIf($this->requiresHistoryCompletion()),
                'nullable',
                'file',
                File::types(['pdf', 'jpg', 'jpeg', 'png'])->max('10mb'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->requiresHistoryCompletion()) {
                return;
            }

            if (! $this->hasFile('file_sk') || ! $this->file('file_sk')?->isValid()) {
                $validator->errors()->add('file_sk', 'File SK baru wajib diunggah saat persetujuan EWS Pangkat, KGB, atau Pensiun.');
            }
        });
    }

    private function requiresHistoryCompletion(): bool
    {
        return $this->isRankApproval() || $this->isKgbApproval() || $this->isPensionApproval();
    }

    private function isRankApproval(): bool
    {
        return $this->alertType() === 'KENAIKAN_PANGKAT';
    }

    private function isKgbApproval(): bool
    {
        return $this->alertType() === 'KGB';
    }

    private function isPensionApproval(): bool
    {
        return $this->alertType() === 'PENSIUN';
    }

    private function alertType(): ?string
    {
        /** @var EwsAlert|null $alert */
        $alert = $this->route('alert');

        if ($this->input('followup_status') !== EwsAlert::FOLLOWUP_STATUS_HANDLED) {
            return null;
        }

        return $alert instanceof EwsAlert ? $alert->type : null;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'followup_status' => 'status tindak lanjut',
            'handled_note' => 'catatan tindak lanjut',
            'no_sk' => 'nomor SK',
            'tanggal_sk' => 'tanggal SK',
            'file_sk' => 'file SK',
        ];
    }
}
