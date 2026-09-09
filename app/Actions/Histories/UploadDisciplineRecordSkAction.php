<?php

namespace App\Actions\Histories;

use App\Models\DisciplineRecord;
use App\Models\Employee;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class UploadDisciplineRecordSkAction
{
    public function __construct(private readonly EmployeeFileStorageService $files) {}

    public function execute(Employee $employee, DisciplineRecord $record, UploadedFile $file, ?Request $request = null): DisciplineRecord
    {
        $storedPath = null;
        $replacedPath = null;

        try {
            $record = DB::transaction(function () use ($employee, $record, $file, $request, &$storedPath, &$replacedPath): DisciplineRecord {
                $record = DisciplineRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                abort_unless($record->employee_id === $employee->id, 404);

                $oldValues = $record->toArray();
                $replacedPath = $record->file_sk;
                $storedPath = $this->files->storeSk($file);
                $record->update(['file_sk' => $storedPath]);

                $employee->documents()->updateOrCreate([
                    'jenis_dokumen' => 'sk_hukuman_disiplin',
                    'history_id' => $record->id,
                ], [
                    'nama_dokumen' => 'SK Hukuman Disiplin '.$record->jenis_hukuman,
                    'tanggal_dokumen' => $record->tanggal_sk,
                    'file_path' => $storedPath,
                    'keterangan' => 'Unggah berkas riwayat hukuman disiplin.',
                ]);
                AuditService::log('UPDATE', 'DisciplineRecord', $record->id, $oldValues, $record->toArray(), $request);

                return $record;
            });
        } catch (\Throwable $exception) {
            $this->files->deleteEmployeeDocumentFile($storedPath);

            throw $exception;
        }

        if ($replacedPath !== $storedPath) {
            $this->files->deleteReplacedEmployeeDocumentFile($replacedPath);
        }

        return $record;
    }
}
