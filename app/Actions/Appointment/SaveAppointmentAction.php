<?php

namespace App\Actions\Appointment;

use App\Models\Appointment;
use App\Models\Employee;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class SaveAppointmentAction
{
    public function __construct(private readonly EmployeeFileStorageService $files) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(
        Employee $employee,
        array $data,
        ?UploadedFile $file = null,
        ?Request $request = null,
    ): Appointment {
        if ($file instanceof UploadedFile) {
            $data['file_sk'] = $this->files->storeSk($file);
        }

        $appointment = $employee->appointment;
        if ($appointment) {
            $oldValues = $appointment->toArray();
            $appointment->update($data);
            AuditService::log('UPDATE', 'Appointment', $appointment->id, $oldValues, $appointment->toArray(), $request);
        } else {
            $appointment = $employee->appointment()->create($data);
            AuditService::log('CREATE', 'Appointment', $appointment->id, null, $appointment->toArray(), $request);
        }

        if ($appointment->file_sk) {
            $employee->documents()->updateOrCreate([
                'jenis_dokumen' => 'sk_pengangkatan',
            ], [
                'nama_dokumen' => 'SK Pengangkatan ' . ($appointment->jenis_pengangkatan ?: ''),
                'nomor_dokumen' => $appointment->no_sk,
                'tanggal_dokumen' => $appointment->tanggal_sk,
                'file_path' => $appointment->file_sk,
                'keterangan' => 'Dokumen SK pengangkatan pertama pegawai.',
            ]);
        }

        return $appointment;
    }
}
