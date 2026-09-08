<?php

namespace App\Http\Requests\Cuti;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Cuti\AdministrativeLeavePostponementAccess;
use Illuminate\Foundation\Http\FormRequest;

/** Form administratif terpisah agar validasi tidak tercampur dengan keputusan approver. */
final class RecordAdministrativeLeavePostponementRequest extends FormRequest
{
    protected $errorBag = 'administrativePostponement';

    /** Scope diperiksa sebelum menerima alasan; Action tetap melakukan recheck di dalam transaksi. */
    public function authorize(): bool
    {
        $actor = $this->user();
        $leaveRequest = $this->route('leaveRequest');

        return $actor instanceof User && $leaveRequest instanceof LeaveRequest
            && app(AdministrativeLeavePostponementAccess::class)->canManage($leaveRequest, $actor);
    }

    /** Alasan wajib bermakna setelah spasi tepi dibuang. */
    protected function prepareForValidation(): void
    {
        $reason = $this->input('alasan');
        if (is_string($reason)) {
            $this->merge(['alasan' => trim($reason)]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['alasan' => ['required', 'string', 'max:500']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['alasan' => 'alasan penangguhan administratif'];
    }
}
