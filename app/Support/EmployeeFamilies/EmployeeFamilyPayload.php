<?php

namespace App\Support\EmployeeFamilies;

use App\Models\EmployeeFamily;
use Illuminate\Support\Arr;

/**
 * Helper untuk payload data keluarga pegawai.
 *
 * Menyediakan metode untuk membuka field keluarga yang aman dikembalikan ke admin
 * dan untuk audit masking (mengecualikan data sensitif seperti NIK).
 */
class EmployeeFamilyPayload
{
    /**
     * Membuka field keluarga yang aman dikembalikan ke admin; relasi pegawai tidak disertakan.
     * Termasuk NIK untuk response admin.
     *
     * @param  EmployeeFamily  $family
     * @return array
     */
    public function response(EmployeeFamily $family): array
    {
        return Arr::only($family->toArray(), [
            'id',
            'employee_id',
            'nama_anggota',
            'hubungan',
            'nik',
            'tempat_lahir',
            'tanggal_lahir',
            'jenis_kelamin',
            'status_tunjangan',
            'pekerjaan',
            'created_at',
            'updated_at',
        ]);
    }

    /**
     * NIK tidak dicatat di audit karena termasuk identitas keluarga yang sensitif.
     * Mengembalikan response payload minus NIK untuk audit log masking.
     *
     * @param  EmployeeFamily  $family
     * @return array
     */
    public function audit(EmployeeFamily $family): array
    {
        return Arr::except($this->response($family), ['nik']);
    }
}
