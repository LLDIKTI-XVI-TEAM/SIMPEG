<?php

namespace App\Actions\Employees;

use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\SalaryHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrepareEmployeeHistoryAttachmentDownloadAction
{
    /**
     * Resolve attachment dari type dan UUID record yang diizinkan, bukan dari path request.
     *
     * @return array{path: string, filename: string}
     */
    public function execute(Employee $employee, string $type, string $historyId): array
    {
        [$record, $pathColumn] = match ($type) {
            'rank' => [$this->ownedRecord(RankHistory::class, $employee, $historyId), 'file_sk'],
            'position' => [$this->ownedRecord(PositionHistory::class, $employee, $historyId), 'file_sk'],
            'salary' => [$this->ownedRecord(SalaryHistory::class, $employee, $historyId), 'file_sk'],
            'appointment' => [$this->ownedRecord(Appointment::class, $employee, $historyId), 'file_sk'],
            'discipline' => [$this->ownedRecord(DisciplineRecord::class, $employee, $historyId), 'file_sk'],
            'education' => [$this->ownedRecord(EducationHistory::class, $employee, $historyId), 'file_ijazah'],
            'status' => [$this->ownedRecord(EmployeeStatusHistory::class, $employee, $historyId), 'file_sk'],
            'status-snapshot' => [$this->statusSnapshot($employee, $historyId), 'status_berkas_path'],
            default => abort(404),
        };

        $path = $record->getAttribute($pathColumn);
        abort_if(! is_string($path) || $path === '' || ! Storage::disk(Document::STORAGE_DISK)->exists($path), 404);

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $filename = Str::slug($employee->nama_lengkap.'-'.$type).($extension === '' ? '' : '.'.$extension);

        return ['path' => $path, 'filename' => $filename];
    }

    /** @param class-string<Model> $model */
    private function ownedRecord(string $model, Employee $employee, string $historyId): Model
    {
        return $model::query()
            ->where('employee_id', $employee->id)
            ->findOrFail($historyId);
    }

    private function statusSnapshot(Employee $employee, string $historyId): Employee
    {
        abort_unless(hash_equals($employee->id, $historyId), 404);

        return $employee;
    }
}
