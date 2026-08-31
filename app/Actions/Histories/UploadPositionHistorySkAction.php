<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Models\PositionHistory;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class UploadPositionHistorySkAction
{
    public function __construct(private readonly EmployeeFileStorageService $files) {}

    public function execute(Employee $employee, PositionHistory $history, UploadedFile $file, ?Request $request = null): PositionHistory
    {
        abort_unless($history->employee_id === $employee->id, 404);

        $oldValues = $history->toArray();
        $path = $this->files->storeSk($file);

        $history->update(['file_sk' => $path]);
        $history->load(['jabatan', 'unitKerja']);

        // Sinkronisasi ke koleksi dokumen pegawai
        $employee->documents()->updateOrCreate([
            'jenis_dokumen' => 'sk_jabatan',
            'nomor_dokumen' => $history->no_sk,
        ], [
            'nama_dokumen' => 'SK Kenaikan Jabatan '.($history->jabatan?->nama ?? $history->nama_jabatan ?? ''),
            'tanggal_dokumen' => $history->tanggal_sk,
            'file_path' => $path,
            'keterangan' => 'Unggah berkas riwayat jabatan.',
        ]);

        AuditService::log('UPDATE', 'PositionHistory', $history->id, $oldValues, $history->toArray(), $request);

        return $history;
    }
}
