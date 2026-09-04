<?php

namespace App\Actions\Appointment;

use App\Models\Appointment;
use App\Models\Employee;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UploadAppointmentSkAction
{
    public function __construct(private readonly EmployeeFileStorageService $files) {}

    public function execute(
        Employee $employee,
        UploadedFile $file,
        ?Request $request = null,
    ): Appointment {
        $storedPath = null;
        $replacedPath = null;

        try {
            $appointment = DB::transaction(function () use ($employee, $file, $request, &$storedPath, &$replacedPath): Appointment {
                // Selector kanonis deterministik "pengangkatan pertama": TMT paling
                // awal, lalu id paling kecil — sama seperti SaveAppointmentAction.
                $appointment = Appointment::query()
                    ->where('employee_id', $employee->id)
                    ->orderBy('tmt_pengangkatan')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($appointment === null) {
                    throw ValidationException::withMessages([
                        'appointment' => 'Simpan data pengangkatan lengkap terlebih dahulu sebelum mengunggah SK.',
                    ]);
                }

                $oldValues = $appointment->toArray();
                $replacedPath = $appointment->file_sk;
                $storedPath = $this->files->storeSk($file);
                $appointment->update(['file_sk' => $storedPath]);
                AuditService::log('UPDATE', 'Appointment', $appointment->id, $oldValues, $appointment->toArray(), $request);

                $employee->documents()->updateOrCreate([
                    'jenis_dokumen' => 'sk_pengangkatan',
                ], [
                    'nama_dokumen' => 'SK Pengangkatan '.($appointment->jenis_pengangkatan ?: ''),
                    'nomor_dokumen' => $appointment->no_sk,
                    'tanggal_dokumen' => $appointment->tanggal_sk,
                    'file_path' => $storedPath,
                    'keterangan' => 'Unggah berkas SK pengangkatan pertama.',
                ]);

                return $appointment;
            });
        } catch (\Throwable $exception) {
            $this->files->deleteEmployeeDocumentFile($storedPath);

            throw $exception;
        }

        if ($replacedPath !== $storedPath) {
            $this->files->deleteReplacedEmployeeDocumentFile($replacedPath);
        }

        return $appointment;
    }
}
