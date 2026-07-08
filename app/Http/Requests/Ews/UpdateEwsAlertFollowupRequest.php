<?php

namespace App\Http\Requests\Ews;

use App\Models\EwsAlert;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEwsAlertFollowupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'followup_status' => 'status tindak lanjut',
            'handled_note' => 'catatan tindak lanjut',
        ];
    }
}
