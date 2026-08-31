<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Models\RankHistory;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class UploadRankHistorySkAction
{
    public function __construct(private readonly EmployeeFileStorageService $files) {}

    public function execute(Employee $employee, RankHistory $history, UploadedFile $file, ?Request $request = null): RankHistory
    {
        abort_unless($history->employee_id === $employee->id, 404);

        $oldValues = $history->toArray();
        $path = $this->files->storeSk($file);

        $history->update(['file_sk' => $path]);
        $history->load('golongan');

        // Sinkronisasi ke koleksi dokumen pegawai
        $employee->documents()->updateOrCreate([
            'jenis_dokumen' => 'sk_pangkat',
            'nomor_dokumen' => $history->no_sk,
        ], [
            'nama_dokumen' => 'SK Kenaikan Pangkat '.($history->golongan?->kode ?? ''),
            'tanggal_dokumen' => $history->tanggal_sk,
            'file_path' => $path,
            'keterangan' => 'Unggah berkas riwayat kepangkatan.',
        ]);

        AuditService::log('UPDATE', 'RankHistory', $history->id, $oldValues, $history->toArray(), $request);

        return $history;
    }
}
