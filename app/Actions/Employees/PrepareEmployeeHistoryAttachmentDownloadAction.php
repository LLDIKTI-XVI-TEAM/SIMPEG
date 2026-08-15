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
use App\Services\Employees\EmployeeHistoryAttachmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrepareEmployeeHistoryAttachmentDownloadAction
{
    public function __construct(private readonly EmployeeHistoryAttachmentService $attachments) {}

    /**
     * Resolve attachment dari type dan UUID record yang diizinkan, bukan dari path request.
     *
     * @return array{path: string, filename: string}
     */
    public function execute(Employee $employee, string $type, string $historyId): array
    {
        $record = match ($type) {
            'rank' => $this->ownedRecord(RankHistory::class, $employee, $historyId),
            'position' => $this->ownedRecord(PositionHistory::class, $employee, $historyId),
            'salary' => $this->ownedRecord(SalaryHistory::class, $employee, $historyId),
            'appointment' => $this->ownedRecord(Appointment::class, $employee, $historyId),
            'discipline' => $this->ownedRecord(DisciplineRecord::class, $employee, $historyId),
            'education' => $this->ownedRecord(EducationHistory::class, $employee, $historyId),
            'status' => $this->ownedRecord(EmployeeStatusHistory::class, $employee, $historyId),
            'status-snapshot' => $this->statusSnapshot($employee, $historyId),
            default => abort(404),
        };

        $path = match ($type) {
            'status' => $record instanceof EmployeeStatusHistory
                ? $this->attachments->availableStatusPath($employee, $record)
                : null,
            'status-snapshot' => $this->attachments->availableStatusSnapshotPath($employee),
            default => $this->attachments->availablePath($employee, $type, $record),
        };
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
