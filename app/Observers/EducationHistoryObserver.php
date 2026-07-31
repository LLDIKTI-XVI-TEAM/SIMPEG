<?php

namespace App\Observers;

use App\Models\EducationHistory;

class EducationHistoryObserver
{
    /**
     * Handle the EducationHistory "saved" event.
     */
    public function saved(EducationHistory $educationHistory): void
    {
        $this->syncEmployeeEducation($educationHistory);
    }

    /**
     * Handle the EducationHistory "deleted" event.
     */
    public function deleted(EducationHistory $educationHistory): void
    {
        $this->syncEmployeeEducation($educationHistory);
    }

    private function syncEmployeeEducation(EducationHistory $educationHistory): void
    {
        $employee = $educationHistory->employee;

        if (!$employee) {
            return;
        }

        $latestEducation = $employee->educationHistories()
            ->leftJoin('ref_jenjang_pendidikan', 'education_histories.jenjang_id', '=', 'ref_jenjang_pendidikan.id')
            ->orderByDesc('ref_jenjang_pendidikan.urutan')
            ->orderByDesc('education_histories.tahun_lulus')
            ->select('education_histories.*', 'ref_jenjang_pendidikan.nama as jenjang_nama')
            ->first();

        if ($latestEducation) {
            $employee->updateQuietly([
                'pendidikan_terakhir' => $latestEducation->jenjang_nama,
                'prodi_pendidikan_terakhir' => $latestEducation->jurusan,
            ]);
        } else {
            $employee->updateQuietly([
                'pendidikan_terakhir' => null,
                'prodi_pendidikan_terakhir' => null,
            ]);
        }
    }
}
