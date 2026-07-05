<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\RefJabatan;
use App\Models\RefStatusPegawai;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;

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

        if ($request->hasFile('foto')) {
            $data['foto'] = $this->files->storePhoto($request->file('foto'));
        }

        $employee = Employee::create($data);

        AuditService::log('CREATE', 'Employee', $employee->id, null, $employee->getRawOriginal(), $request);

        return $employee;
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

        $kepalaBagianId = $data['kepala_bagian_id'] ?? $data['atasan_langsung_id'] ?? null;
        if ($kepalaBagianId !== null) {
            $data['kepala_bagian_id'] = $kepalaBagianId;
            $data['atasan_langsung_id'] = $kepalaBagianId;
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
