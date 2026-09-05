<?php

namespace App\Http\Requests\Cuti;

use App\Models\LeaveCancellationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Membatasi filter antrean pembatalan agar query Admin tetap terprediksi dan terikat pagination. */
final class ListLeaveCancellationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('cuti.cancellation.manage');
    }

    /** @return array<string, list<string|int>> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in([
                'all',
                LeaveCancellationRequest::STATUS_PENDING,
                LeaveCancellationRequest::STATUS_APPROVED,
                LeaveCancellationRequest::STATUS_REJECTED,
            ])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
        ];
    }
}
