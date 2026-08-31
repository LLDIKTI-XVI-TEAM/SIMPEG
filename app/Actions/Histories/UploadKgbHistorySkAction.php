<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Models\SalaryHistory;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class UploadKgbHistorySkAction
{
    public function __construct(private readonly EmployeeFileStorageService $files) {}

    public function execute(Employee $employee, SalaryHistory $history, UploadedFile $file, ?Request $request = null): SalaryHistory
    {
        abort_unless($history->employee_id === $employee->id, 404);

        $oldValues = $history->toArray();
        $path = $this->files->storeSk($file);

        $history->update(['file_sk' => $path]);

        // Sinkronisasi ke koleksi dokumen pegawai
        $employee->documents()->updateOrCreate([
            'jenis_dokumen' => 'sk_kgb',
            'nomor_dokumen' => $history->no_sk,
        ], [
            'nama_dokumen' => 'SK KGB TMT '.($history->tmt_kgb ? $history->tmt_kgb->format('d-m-Y') : ''),
            'tanggal_dokumen' => $history->tanggal_sk,
            'file_path' => $path,
            'keterangan' => 'Unggah berkas riwayat kenaikan gaji berkala.',
        ]);

        AuditService::log('UPDATE', 'SalaryHistory', $history->id, $oldValues, $history->toArray(), $request);

        return $history;
    }
}
