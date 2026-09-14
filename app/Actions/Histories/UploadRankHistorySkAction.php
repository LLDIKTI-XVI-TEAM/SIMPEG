<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Models\RankHistory;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

                // Re-check permission setelah row terkunci: FormRequest sudah cek create vs update
                // di gate awal, tetapi state file_sk dapat berubah di antara request dan lock.
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
                $history->load('golongan');

                $employee->documents()->updateOrCreate([
                    'jenis_dokumen' => 'sk_pangkat',
                    'history_id' => $history->id,
                ], [
                    'nama_dokumen' => 'SK Kenaikan Pangkat '.($history->golongan?->kode ?? ''),
                    'nomor_dokumen' => $history->no_sk,
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
