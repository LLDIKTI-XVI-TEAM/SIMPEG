<?php

namespace App\Queries\Cuti;

use App\Models\Employee;
use App\Models\LeaveRequestCase;
use App\Services\Cuti\LeaveEligibilityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ManualLeaveCaseOptionQuery
{
    private const RESULT_LIMIT = 100;

    public function __construct(private readonly LeaveEligibilityService $eligibility) {}

    /**
     * Memuat opsi rangkaian hanya dari pegawai terpilih agar UUID lintas pegawai tidak pernah menjadi pilihan UI.
     *
     * @return Collection<int, array{id:string,label:string}>
     */
    public function forEmployee(?string $employeeId, ?string $selectedCaseId): Collection
    {
        if (! is_string($employeeId) || ! Str::isUuid($employeeId)) {
            return collect();
        }

        $employee = Employee::query()->select('id')->find($employeeId);

        if ($employee === null) {
            return collect();
        }

        return $this->forSelectedEmployee($employee, $selectedCaseId);
    }

    /**
     * Menggunakan pegawai workspace yang telah dimuat agar editor tidak melakukan lookup identitas kedua.
     *
     * @return Collection<int, array{id:string,label:string}>
     */
    public function forSelectedEmployee(?Employee $employee, ?string $selectedCaseId): Collection
    {
        if ($employee === null) {
            return collect();
        }

        return $this->eligibility
            ->continuationCasesFor($employee, $selectedCaseId)
            ->take(self::RESULT_LIMIT)
            ->map(fn (LeaveRequestCase $leaveCase): array => [
                'id' => $leaveCase->id,
                'label' => $this->label($leaveCase),
            ]);
    }

    /** Membuat label ringkas dari jenis, periode, dan status tanpa mengirim histori rangkaian ke Blade. */
    private function label(LeaveRequestCase $leaveCase): string
    {
        $starts = array_filter([
            $leaveCase->getAttribute('request_period_start'),
            $leaveCase->getAttribute('manual_period_start'),
        ]);
        $ends = array_filter([
            $leaveCase->getAttribute('request_period_end'),
            $leaveCase->getAttribute('manual_period_end'),
        ]);
        $period = $starts === [] || $ends === []
            ? 'Periode belum tersedia'
            : min($starts).' s.d. '.max($ends);

        return sprintf('%s · %s · Aktif', $leaveCase->jenisCuti?->nama ?? 'Jenis cuti', $period);
    }
}
