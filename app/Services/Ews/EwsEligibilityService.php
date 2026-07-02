<?php

namespace App\Services\Ews;

use App\Models\Employee;

class EwsEligibilityService
{
    /**
     * Menentukan eligibility kenaikan pangkat dari flag kinerja dan hukuman disiplin aktif.
     * SKP belum masuk scope, sehingga flag manual menjadi sumber kontrol sementara.
     *
     * @return array{is_eligible: bool, reason: string, checks: array<int, array{label: string, passed: bool}>}
     */
    public function promotion(Employee $employee): array
    {
        $isKinerjaBaik = $employee->is_kinerja_baik === true;
        $hasActiveDiscipline = $employee->disciplineRecords->contains('is_active', true);
        $reasons = [];

        if (! $isKinerjaBaik) {
            $reasons[] = 'Kinerja perlu ditinjau';
        }

        if ($hasActiveDiscipline) {
            $reasons[] = 'Hukuman disiplin aktif';
        }

        return [
            'is_eligible' => $isKinerjaBaik && ! $hasActiveDiscipline,
            'reason' => $reasons === [] ? 'Kinerja baik' : implode(', ', $reasons),
            'checks' => [
                ['label' => 'Kinerja baik', 'passed' => $isKinerjaBaik],
                ['label' => 'Bebas hukuman disiplin', 'passed' => ! $hasActiveDiscipline],
            ],
        ];
    }
}
