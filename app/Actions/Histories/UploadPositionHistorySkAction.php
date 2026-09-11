<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UploadPositionHistorySkAction
{
    public function __construct(private readonly EmployeeFileStorageService $files) {}

    public function execute(Employee $employee, PositionHistory $history, UploadedFile $file, ?Request $request = null): PositionHistory
    {
        $storedPath = null;
        $replacedPath = null;

        try {
            $history = DB::transaction(function () use ($employee, $history, $file, $request, &$storedPath, &$replacedPath): PositionHistory {
                $history = PositionHistory::query()->whereKey($history->id)->lockForUpdate()->firstOrFail();
                abort_unless($history->employee_id === $employee->id, 404);

                $actor = $request?->user();
                $permission = filled($history->file_sk) ? 'dokumen_sk.update' : 'dokumen_sk.create';
                if ($actor instanceof User && ! $actor->hasPermission($permission)) {
                    throw ValidationException::withMessages([
                        'file_sk' => 'Anda tidak memiliki permission '.$permission.'.',
                    ]);
                }

                $oldValues = $history->toArray();
                $replacedPath = $history->file_sk;
                $storedPath = $this->files->storeSk($file);
                $history->update(['file_sk' => $storedPath]);
                $history->load(['jabatan', 'unitKerja']);

                $employee->documents()->updateOrCreate([
                    'jenis_dokumen' => 'sk_jabatan',
                    'history_id' => $history->id,
                ], [
                    'nama_dokumen' => 'SK Kenaikan Jabatan '.($history->jabatan?->nama ?? $history->nama_jabatan ?? ''),
                    'nomor_dokumen' => $history->no_sk,
                    'tanggal_dokumen' => $history->tanggal_sk,
                    'file_path' => $storedPath,
                    'keterangan' => 'Unggah berkas riwayat jabatan.',
                ]);
                AuditService::log('UPDATE', 'PositionHistory', $history->id, $oldValues, $history->toArray(), $request);

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
