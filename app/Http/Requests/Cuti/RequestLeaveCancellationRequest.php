<?php

namespace App\Http\Requests\Cuti;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;

/** Memvalidasi alasan pembatalan tanpa mempercayakan otorisasi pemilik kepada form. */
class RequestLeaveCancellationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Pembatalan mandiri melekat pada pemilik pengajuan, bukan checkbox membuat pengajuan baru.
        $actor = $this->user();
        $leave = $this->route('leaveRequest');

        return $actor?->employee?->isActive() === true
            && $leave instanceof LeaveRequest
            && $leave->employee_id === $actor->employee_id;
    }

    /** Alasan disimpan sebagai data sensitif pada record pembatalan, sehingga spasi tepi tidak dipertahankan. */
    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        if (is_string($reason)) {
            $this->merge(['reason' => trim($reason)]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['reason' => 'alasan pembatalan'];
    }
}
