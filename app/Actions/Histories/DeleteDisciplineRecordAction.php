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
        $filePath = DB::transaction(function () use ($employee, $record, $request): ?string {
            /** @var DisciplineRecord $lockedRecord */
            $lockedRecord = DisciplineRecord::query()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($lockedRecord->employee_id === $employee->id, 404);

            $filePath = $lockedRecord->file_sk;
            $oldValues = $lockedRecord->toArray();
            $recordId = $lockedRecord->id;

            $lockedRecord->delete();

            if ($filePath) {
                // Hapus hanya mirror milik riwayat ini. Dua riwayat legacy bisa berbagi
                // file_sk yang sama sehingga mirror ganda dengan history_id berbeda;
                // predicate path-only akan menghapus arsip riwayat yang masih hidup.
                Document::where('employee_id', $employee->id)
                    ->where('jenis_dokumen', 'sk_hukuman_disiplin')
                    ->where('history_id', $recordId)
                    ->delete();
                // Fallback legacy: row tanpa history_id hanya dihapus bila path cocok.
                Document::where('employee_id', $employee->id)
                    ->where('jenis_dokumen', 'sk_hukuman_disiplin')
                    ->whereNull('history_id')
                    ->where('file_path', $filePath)
                    ->whereNotExists(function ($query) use ($employee, $filePath): void {
                        $query->selectRaw('1')
                            ->from('discipline_records')
                            ->whereColumn('discipline_records.employee_id', 'documents.employee_id')
                            ->where('discipline_records.employee_id', $employee->id)
                            ->where('discipline_records.file_sk', $filePath);
                    })
                    ->delete();
            }

            AuditService::log('DELETE', 'DisciplineRecord', $recordId, $oldValues, null, $request);

            return $filePath;
        });

        if ($filePath) {
            // Hapus file fisik hanya setelah kedua referensi sudah terhapus.
            $this->files->deleteReplacedEmployeeDocumentFile($filePath);
        }
    }
}
