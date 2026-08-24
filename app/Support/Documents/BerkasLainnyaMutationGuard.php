<?php

namespace App\Support\Documents;

use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\SalaryHistory;
use Illuminate\Validation\ValidationException;

class BerkasLainnyaMutationGuard
{
    /**
     * Pastikan jalur Berkas Lainnya tidak memutasi SK atau lampiran riwayat kanonis.
     *
     * Record riwayat tetap dikelola melalui domain asalnya. Pemeriksaan file_path
     * mencegah metadata dokumen dan referensi riwayat menunjuk versi file berbeda.
     */
    public function assertCanMutate(Employee $employee, Document $document): void
    {
        if (! hash_equals((string) $employee->id, (string) $document->employee_id)
            || ! DocumentCategory::isOtherUpload($document->jenis_dokumen)) {
            throw ValidationException::withMessages([
                'document' => 'Dokumen ini tidak dapat dikelola melalui jalur Berkas Lainnya.',
            ]);
        }

        if ($this->isUsedByEmployeeHistory($employee, $document->file_path)) {
            throw ValidationException::withMessages([
                'document' => 'Dokumen masih digunakan oleh riwayat kepegawaian dan harus dikelola melalui alur riwayat terkait.',
            ]);
        }
    }

    /** File lama hanya boleh dibersihkan setelah tidak dirujuk metadata atau riwayat mana pun. */
    public function fileIsStillReferenced(string $filePath): bool
    {
        return Document::query()->where('file_path', $filePath)->exists()
            || Employee::query()->where('status_berkas_path', $filePath)->exists()
            || EmployeeStatusHistory::query()->where('file_sk', $filePath)->exists()
            || RankHistory::query()->where('file_sk', $filePath)->exists()
            || PositionHistory::query()->where('file_sk', $filePath)->exists()
            || SalaryHistory::query()->where('file_sk', $filePath)->exists()
            || DisciplineRecord::query()->where('file_sk', $filePath)->exists()
            || Appointment::query()->where('file_sk', $filePath)->exists()
            || EducationHistory::query()->where('file_ijazah', $filePath)->exists();
    }

    private function isUsedByEmployeeHistory(Employee $employee, string $filePath): bool
    {
        return Employee::query()
            ->whereKey($employee->id)
            ->where('status_berkas_path', $filePath)
            ->exists()
            || EmployeeStatusHistory::query()
                ->where('employee_id', $employee->id)
                ->where('file_sk', $filePath)
                ->exists()
            || RankHistory::query()
                ->where('employee_id', $employee->id)
                ->where('file_sk', $filePath)
                ->exists()
            || PositionHistory::query()
                ->where('employee_id', $employee->id)
                ->where('file_sk', $filePath)
                ->exists()
            || SalaryHistory::query()
                ->where('employee_id', $employee->id)
                ->where('file_sk', $filePath)
                ->exists()
            || DisciplineRecord::query()
                ->where('employee_id', $employee->id)
                ->where('file_sk', $filePath)
                ->exists()
            || Appointment::query()
                ->where('employee_id', $employee->id)
                ->where('file_sk', $filePath)
                ->exists()
            || EducationHistory::query()
                ->where('employee_id', $employee->id)
                ->where('file_ijazah', $filePath)
                ->exists();
    }
}
