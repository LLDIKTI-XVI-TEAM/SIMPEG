<?php

namespace App\Actions\Histories;

use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\Employee;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeleteDisciplineRecordAction
{
    public function __construct(private readonly EmployeeFileStorageService $files) {}

    public function execute(Employee $employee, DisciplineRecord $record, ?Request $request = null): void
    {
        abort_unless($record->employee_id === $employee->id, 404);

        $filePath = $record->file_sk;
        $oldValues = $record->toArray();
        $recordId = $record->id;

        DB::transaction(function () use ($employee, $filePath, $record, $request, $oldValues, $recordId): void {
            $record->delete();

            if ($filePath) {
                // Mirror harus hilang dalam transaksi yang sama. Cleanup fisik
                // setelah commit lalu melihat referensi yang benar-benar tersisa.
                Document::where('employee_id', $employee->id)
                    ->where('file_path', $filePath)
                    ->where('jenis_dokumen', 'sk_hukuman_disiplin')
                    ->delete();
            }

            AuditService::log('DELETE', 'DisciplineRecord', $recordId, $oldValues, null, $request);
        });

        if ($filePath) {
            // Hapus file fisik hanya setelah kedua referensi sudah terhapus.
            $this->files->deleteReplacedEmployeeDocumentFile($filePath);
        }
    }
}
