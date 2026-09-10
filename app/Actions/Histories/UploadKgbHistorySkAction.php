<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Models\SalaryHistory;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class UploadKgbHistorySkAction
{
    public function __construct(private readonly EmployeeFileStorageService $files) {}

    public function execute(Employee $employee, SalaryHistory $history, UploadedFile $file, ?Request $request = null): SalaryHistory
    {
        $storedPath = null;
        $replacedPath = null;

        try {
            $history = DB::transaction(function () use ($employee, $history, $file, $request, &$storedPath, &$replacedPath): SalaryHistory {
                $history = SalaryHistory::query()->whereKey($history->id)->lockForUpdate()->firstOrFail();
                abort_unless($history->employee_id === $employee->id, 404);

                $oldValues = $history->toArray();
                $replacedPath = $history->file_sk;
                $storedPath = $this->files->storeSk($file);
                $history->update(['file_sk' => $storedPath]);

                $employee->documents()->updateOrCreate([
                    'jenis_dokumen' => 'sk_kgb',
                    'history_id' => $history->id,
                ], [
                    'nama_dokumen' => 'SK KGB TMT '.($history->tmt_kgb ? $history->tmt_kgb->format('d-m-Y') : ''),
                    'nomor_dokumen' => $history->no_sk,
                    'tanggal_dokumen' => $history->tanggal_sk,
                    'file_path' => $storedPath,
                    'keterangan' => 'Unggah berkas riwayat kenaikan gaji berkala.',
                ]);
                AuditService::log('UPDATE', 'SalaryHistory', $history->id, $oldValues, $history->toArray(), $request);

                return $history;
            });
        } catch (\Throwable $exception) {
            $this->files->deleteEmployeeDocumentFile($storedPath);

            throw $exception;
        }

        if ($replacedPath !== $storedPath) {
            $this->files->deleteReplacedEmployeeDocumentFile($replacedPath);
        }

        return $history;
    }
}
