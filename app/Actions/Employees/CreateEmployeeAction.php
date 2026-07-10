<?php

namespace App\Actions\Employees;

use App\Models\Document;
use App\Models\Employee;
use App\Models\RefJabatan;
use App\Models\RefStatusPegawai;
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
        $data = $this->normalizeEmployeeContract($data);

        return DB::transaction(function () use ($data, $request): Employee {
            if ($request->hasFile('foto')) {
                $data['foto'] = $this->files->storePhoto($request->file('foto'));
            }

            $employee = Employee::create($data);

            if ($request->filled('jenis_pengangkatan')) {
                $appointmentData = [
                    'jenis_pengangkatan' => $data['jenis_pengangkatan'],
                    'tmt_pengangkatan' => $data['tmt'],
                    'no_sk' => $data['nomor_sk'],
                    'tanggal_sk' => $data['tanggal_sk'],
                ];

                if ($request->hasFile('file_sk') && $request->file('file_sk')->isValid()) {
                    $appointmentData['file_sk'] = $this->files->storeSk($request->file('file_sk'));

                    Document::create([
                        'employee_id' => $employee->id,
                        'jenis_dokumen' => 'sk_pengangkatan',
                        'nama_dokumen' => 'SK Pengangkatan '.$appointmentData['jenis_pengangkatan'],
                        'nomor_dokumen' => $appointmentData['no_sk'],
                        'tanggal_dokumen' => $appointmentData['tanggal_sk'],
                        'file_path' => $appointmentData['file_sk'],
                        'keterangan' => 'Diunggah otomatis saat tambah pegawai',
                    ]);
                }

                // TMT pengangkatan menjadi sumber masa kerja cuti; simpan saat pegawai dibuat agar eligibility tidak kosong.
                $employee->appointment()->create($appointmentData);
            }

            $employee->refresh();
            AuditService::log('CREATE', 'Employee', $employee->id, null, $employee->getRawOriginal(), $request);

            return $employee;
        });
    }

    private function normalizeEmployeeContract(array $data): array
    {
        $email = $data['email_pribadi'] ?? $data['email'] ?? null;
        if ($email !== null) {
            $data['email_pribadi'] = $email;
            $data['email'] = $email;
        }

        $kelasJabatan = $data['kelas_jabatan_terakhir'] ?? $data['kelas_jabatan'] ?? null;
        if ($kelasJabatan !== null) {
            $data['kelas_jabatan_terakhir'] = $kelasJabatan;
            $data['kelas_jabatan'] = $kelasJabatan;
        }

        if (! empty($data['jabatan_id']) && empty($data['jabatan_terakhir'])) {
            $data['jabatan_terakhir'] = RefJabatan::find($data['jabatan_id'])?->nama;
        }

        if (empty($data['status_pegawai_id'])) {
            $statusName = $data['status_aktif'] ?? 'Aktif';
            $data['status_pegawai_id'] = RefStatusPegawai::where('nama', $statusName)->value('id')
                ?? RefStatusPegawai::where('is_default', true)->value('id');
        }

        if (! empty($data['status_pegawai_id']) && empty($data['status_aktif'])) {
            $data['status_aktif'] = RefStatusPegawai::whereKey($data['status_pegawai_id'])->value('nama') ?? 'Aktif';
        }

        return $data;
    }
}
