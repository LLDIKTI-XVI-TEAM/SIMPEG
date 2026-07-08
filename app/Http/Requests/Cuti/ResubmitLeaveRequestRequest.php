<?php

namespace App\Http\Requests\Cuti;

use App\Services\Cuti\LeaveBalanceService;
use App\Services\WorkdayCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Memvalidasi revisi pengajuan berstatus perlu_perubahan.
 * Jenis cuti tetap terkunci; pemohon hanya boleh memperbaiki tanggal, alasan, dan lampiran.
 */
class ResubmitLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leaveRequest = $this->route('leaveRequest');

        return $leaveRequest !== null
            && $this->user()?->employee_id === $leaveRequest->employee_id
            && $leaveRequest->status === 'perlu_perubahan';
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'tanggal_mulai' => ['required', 'date_format:Y-m-d'],
            'tanggal_selesai' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_mulai'],
            'alasan' => ['required', 'string', 'max:500'],
            'lampiran' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }

    public function attributes(): array
    {
        return [
            'tanggal_mulai' => 'tanggal mulai',
            'tanggal_selesai' => 'tanggal selesai',
            'alasan' => 'alasan',
            'lampiran' => 'lampiran',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $leaveRequest = $this->route('leaveRequest');

            if (! $leaveRequest?->jenisCuti?->mengurangi_saldo_tahunan) {
                return;
            }

            $mulai = Carbon::createFromFormat('Y-m-d', (string) $this->input('tanggal_mulai'))->startOfDay();
            $selesai = Carbon::createFromFormat('Y-m-d', (string) $this->input('tanggal_selesai'))->startOfDay();

            if ($mulai->year !== $selesai->year) {
                $validator->errors()->add('tanggal_selesai', 'Cuti tahunan yang melintasi pergantian tahun belum dapat diajukan. Pisahkan pengajuan untuk tiap tahun.');

                return;
            }

            $hariKerja = app(WorkdayCalculator::class)->calculate($mulai, $selesai);
            // Resubmit hanya mengecek ketersediaan saldo dari bucket summary; pemotongan tetap terjadi saat final approval.
            $employee = $this->user()?->employee;
            $sisaSaldo = $employee === null
                ? 0
                : app(LeaveBalanceService::class)->availableFor($employee, $mulai->year, $mulai);

            if ($sisaSaldo < $hariKerja) {
                $validator->errors()->add('tanggal_selesai', "Saldo cuti tahunan tidak mencukupi. Sisa saldo {$sisaSaldo} hari, sedangkan pengajuan membutuhkan {$hariKerja} hari kerja.");
            }
        });
    }
}
