<?php

namespace App\Actions\Appointment;

use App\Models\Appointment;
use App\Models\Employee;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class UploadAppointmentSkAction
{
    public function __construct(private readonly EmployeeFileStorageService $files) {}

    public function execute(
        Employee $employee,
        UploadedFile $file,
        ?Request $request = null,
    ): Appointment {
        $path = $this->files->storeSk($file);
        $appointment = $employee->appointment;

        if ($appointment) {
            $oldValues = $appointment->toArray();
            $appointment->update(['file_sk' => $path]);
            AuditService::log('UPDATE', 'Appointment', $appointment->id, $oldValues, $appointment->toArray(), $request);
        } else {
            $appointment = $employee->appointment()->create([
                'jenis_pengangkatan' => $employee->jenisPegawai?->nama ?? 'CPNS',
                'no_sk' => '-',
                'tanggal_sk' => now()->toDateString(),
                'tmt_pengangkatan' => now()->toDateString(),
                'file_sk' => $path,
            ]);
            AuditService::log('CREATE', 'Appointment', $appointment->id, null, $appointment->toArray(), $request);
        }

        $employee->documents()->updateOrCreate([
            'jenis_dokumen' => 'sk_pengangkatan',
        ], [
            'nama_dokumen' => 'SK Pengangkatan ' . ($appointment->jenis_pengangkatan ?: ''),
            'nomor_dokumen' => $appointment->no_sk,
            'tanggal_dokumen' => $appointment->tanggal_sk,
            'file_path' => $path,
            'keterangan' => 'Unggah berkas SK pengangkatan pertama.',
        ]);

        return $appointment;
    }
}
