<?php

namespace App\Actions\Employees;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\RefJenisJabatan;
use App\Models\RefUnitKerja;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreateEmployeeAction
{
    public function __construct(private readonly EmployeeFileStorageService $files) {}

    /**
     * Membuat pegawai baru, termasuk penyimpanan foto, dokumen SK pengangkatan,
     * riwayat jabatan awal, dan audit create.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, Request $request): Employee
    {
        return DB::transaction(function () use ($data, $request) {
            // Handle Photo
            if ($request->hasFile('foto') && $request->file('foto')->isValid()) {
                $file = $request->file('foto');
                $filename = $file->hashName();
                $file->move(storage_path('app/public/employees/photos'), $filename);
                $data['foto'] = 'employees/photos/'.$filename;
            } else {
                unset($data['foto']);
            }

            // Create Employee
            $employee = Employee::create($data);

            // Handle Appointment (SK Pengangkatan)
            $appointmentData = [
                'employee_id' => $employee->id,
                'jenis_pengangkatan' => $data['jenis_pengangkatan'] ?? null,
                'tmt_pengangkatan' => $data['tmt'] ?? null,
                'no_sk' => $data['nomor_sk'] ?? null,
                'tanggal_sk' => $data['tanggal_sk'] ?? null,
            ];

            if ($request->hasFile('file_sk') && $request->file('file_sk')->isValid()) {
                $skFile = $request->file('file_sk');
                $skFilename = $skFile->hashName();
                $skFile->move(storage_path('app/public/appointments/sk'), $skFilename);
                $appointmentData['file_sk'] = 'appointments/sk/'.$skFilename;
            }

            Appointment::create($appointmentData);

            if (! empty($data['jabatan_terakhir']) || ! empty($data['unit_kerja_id'])) {
                PositionHistory::create([
                    'employee_id' => $employee->id,
                    'nama_jabatan' => $data['jabatan_terakhir'] ?? '-',
                    'jenis_jabatan_id' => $data['jenis_jabatan_id'] ?? RefJenisJabatan::first()?->id,
                    'unit_kerja_id' => $data['unit_kerja_id'] ?? RefUnitKerja::first()?->id,
                    'tmt_jabatan' => $data['tmt'] ?? now()->format('Y-m-d'),
                    'no_sk' => $data['nomor_sk'] ?? '-',
                    'tanggal_sk' => $data['tanggal_sk'] ?? now()->format('Y-m-d'),
                    'is_latest' => true,
                ]);
            }

            AuditService::log('CREATE', 'Employee', $employee->id, null, $employee->toArray(), $request);

            return $employee;
        });
    }
}
