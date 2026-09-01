<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Models\RankHistory;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class UploadRankHistorySkAction
{
    public function __construct(private readonly EmployeeFileStorageService $files) {}

    public function execute(Employee $employee, RankHistory $history, UploadedFile $file, ?Request $request = null): RankHistory
    {
        $storedPath = null;
        $replacedPath = null;

        try {
            $history = DB::transaction(function () use ($employee, $history, $file, $request, &$storedPath, &$replacedPath): RankHistory {
                $history = RankHistory::query()->whereKey($history->id)->lockForUpdate()->firstOrFail();
                abort_unless($history->employee_id === $employee->id, 404);

                $oldValues = $history->toArray();
                $replacedPath = $history->file_sk;
                $storedPath = $this->files->storeSk($file);
                $history->update(['file_sk' => $storedPath]);
                $history->load('golongan');

                $employee->documents()->updateOrCreate([
                    'jenis_dokumen' => 'sk_pangkat',
                    'nomor_dokumen' => $history->no_sk,
                ], [
                    'nama_dokumen' => 'SK Kenaikan Pangkat '.($history->golongan?->kode ?? ''),
                    'tanggal_dokumen' => $history->tanggal_sk,
                    'file_path' => $storedPath,
                    'keterangan' => 'Unggah berkas riwayat kepangkatan.',
                ]);
                AuditService::log('UPDATE', 'RankHistory', $history->id, $oldValues, $history->toArray(), $request);

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
