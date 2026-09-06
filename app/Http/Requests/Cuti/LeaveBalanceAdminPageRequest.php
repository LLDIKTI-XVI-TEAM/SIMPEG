<?php

namespace App\Http\Requests\Cuti;

use App\Models\LeaveUsageRecord;
use App\Services\Cuti\AnnualLeaveBusinessClock;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Menjaga administrasi saldo hanya membuka tahun bisnis WITA yang sedang berjalan.
 */
class LeaveBalanceAdminPageRequest extends ListCutiRekapRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'periode' => ['bail', 'nullable', 'string', 'regex:/^(?:20\d{2}|2100)$/'],
            'pegawai' => ['bail', 'nullable', 'uuid'],
            'status' => ['bail', 'nullable', 'in:perlu_tindakan,sudah_terdaftar,semua_pegawai'],
            'search' => ['bail', 'nullable', 'string', 'max:150'],
            'per_page' => ['bail', 'nullable', 'integer', Rule::in([10, 25, 50])],
            'tab' => ['bail', 'nullable', 'in:pendaftaran,manual,riwayat'],
            'source_type' => ['bail', 'nullable', 'string', Rule::in([
                LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
                LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            ])],
            'record_status' => ['bail', 'nullable', 'string', Rule::in([
                LeaveUsageRecord::STATUS_ACTIVE,
                LeaveUsageRecord::STATUS_SUPERSEDED,
                LeaveUsageRecord::STATUS_CANCELLED,
            ])],
            'usage_year' => ['bail', 'nullable', 'integer', 'between:1900,2100'],
            'leave_type' => ['bail', 'nullable', 'uuid', 'exists:ref_jenis_cuti,id'],
            'sort' => ['bail', 'nullable', 'string', Rule::in(['effective_date', 'created_at', 'usage_year', 'workdays'])],
            'direction' => ['bail', 'nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page_usage' => ['bail', 'nullable', 'integer', Rule::in([10, 25, 50])],
            'page_pegawai' => ['bail', 'nullable', 'integer', 'min:1'],
            'page_ledger' => ['bail', 'nullable', 'integer', 'min:1'],
            'page_usage' => ['bail', 'nullable', 'integer', 'min:1'],
            'edit_usage' => ['bail', 'nullable', 'uuid'],
            'manual_action' => ['bail', 'nullable', 'string', Rule::in(['correct', 'cancel'])],
        ];
    }

    /**
     * Parameter lama diterima hanya bila sama dengan tahun bisnis WITA, lalu dibuang agar URL turunan tetap kanonis.
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $leaveType = $this->input('leave_type');
        if ($leaveType !== null && (is_array($leaveType) || ! is_string($leaveType) || ! Str::isUuid($leaveType))) {
            abort(404);
        }

        // String kosong tidak dianggap filter aktif; nilai non-string dibiarkan agar validator menolaknya.
        foreach (['search', 'source_type', 'record_status', 'sort', 'direction'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $this->merge([$field => trim($value) === '' ? null : trim($value)]);
            }
        }

        $periode = $this->input('periode');

        if ($periode !== null && (is_array($periode) || ! is_string($periode) || preg_match('/^(?:20\d{2}|2100)$/', $periode) !== 1)) {
            abort(404);
        }

        if ($periode !== null
            && (int) $periode !== app(AnnualLeaveBusinessClock::class)->currentYear()) {
            abort(404);
        }

        $this->query->remove('periode');
    }
}
