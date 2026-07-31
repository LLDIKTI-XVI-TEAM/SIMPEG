<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class EmployeeValidationRules
{
    /**
     * Full validation rules for creating an employee via form.
     */
    public static function create(): array
    {
        return [
            'nama_lengkap' => ['required', 'string', 'max:255'],
            'nama_dengan_gelar' => ['nullable', 'string', 'max:255'],
            'nip' => ['nullable', 'string', 'size:18', 'unique:employees,nip'],
            'nik' => ['nullable', 'string', 'size:16'],
            'no_kk' => ['nullable', 'string', 'size:16'],
            'tempat_lahir' => ['nullable', 'string', 'max:100'],
            'tanggal_lahir' => ['nullable', 'date', 'before:today'],
            'jenis_kelamin' => ['nullable', 'in:L,P'],
            'agama_id' => ['nullable', 'uuid', 'exists:ref_agama,id'],
            'status_kawin_id' => ['nullable', 'uuid', 'exists:ref_status_perkawinan,id'],
            'golongan_darah' => ['nullable', 'in:A,B,AB,O'],
            'foto' => ['nullable', File::image()->types(['jpg', 'jpeg', 'png'])->max('10mb')],
            'jenis_pegawai_id' => ['nullable', 'uuid', 'exists:ref_jenis_pegawai,id'],
            'status_aktif' => ['nullable', 'in:Aktif,Non-Aktif,Pensiun,Mutasi'],
            'status_pegawai_id' => ['nullable', 'uuid', 'exists:ref_status_pegawai,id'],
            'status_keterangan' => ['nullable', 'string', 'max:2000'],
            'kepala_bagian_id' => ['nullable', 'uuid', 'exists:employees,id'],

            // Snapshot
            'golongan_terakhir' => ['nullable', 'string', 'max:20'],
            'pangkat_terakhir' => ['nullable', 'string', 'max:100'],
            'jabatan_terakhir' => ['nullable', 'string', 'max:255'],
            'jabatan_id' => ['nullable', 'uuid', 'exists:ref_jabatan,id'],
            'jenis_jabatan_id' => ['nullable', 'uuid', 'exists:ref_jenis_jabatan,id'],
            'unit_kerja_id' => ['nullable', 'uuid', 'exists:ref_unit_kerja,id'],
            'kelas_jabatan' => ['nullable', 'string', 'max:10'],
            'kelas_jabatan_terakhir' => ['nullable', 'string', 'max:10'],

            // Pendidikan snapshot
            'pendidikan_terakhir' => ['nullable', 'string', 'max:50'],
            'prodi_pendidikan_terakhir' => ['nullable', 'string', 'max:255'],

            // Pensiun

            // Penanda eksplisit Kepala Lembaga untuk kebutuhan dokumen cuti tanpa inferensi jabatan bebas.
            'is_kepala_lembaga' => ['sometimes', 'boolean'],

            // Kontak
            'alamat' => ['nullable', 'string'],
            'no_hp' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255', 'unique:employees,email'],
            'email_pribadi' => ['nullable', 'email', 'max:255', 'unique:employees,email_pribadi'],
            'no_telepon_rumah' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * Full validation rules for updating an employee via API/form.
     */
    public static function update(Employee $employee): array
    {
        $rules = self::create();

        $rules['nip'] = [
            'nullable',
            'string',
            'size:18',
            Rule::unique('employees', 'nip')->ignore($employee->id),
        ];
        $rules['email'] = [
            'nullable',
            'email',
            'max:255',
            Rule::unique('employees', 'email')->ignore($employee->id),
        ];
        $rules['email_pribadi'] = [
            'nullable',
            'email',
            'max:255',
            Rule::unique('employees', 'email_pribadi')->ignore($employee->id),
        ];

        return $rules;
    }

    /**
     * Relaxed rules for import from Excel/CSV.
     * Only fields available in the Excel are validated; the rest are nullable.
     *
     * - nama_dengan_gelar : wajib diisi, diambil dari kolom 'Nama Pegawai' (termasuk gelar).
     * - nama_lengkap      : opsional, diambil dari kolom 'Person' (nama tanpa gelar).
     *                       Diisi nullable agar file yang tidak memiliki kolom Person
     *                       tetap dapat di-import tanpa error.
     */
    public static function import(): array
    {
        return [
            'nama_dengan_gelar' => ['required', 'string', 'max:255'],
            'nama_lengkap' => ['nullable', 'string', 'max:255'],
            'nip' => ['required', 'string', 'size:18', 'unique:employees,nip'],
            'email_pribadi' => ['required', 'email', 'max:255', 'unique:employees,email_pribadi'],
            'email' => ['nullable', 'email', 'max:255', 'unique:employees,email'],
            'tanggal_lahir' => ['nullable', 'date', 'before:today'],
            'jenis_pegawai' => ['required', 'in:PNS,PPPK,CPNS'],
            'golongan_terakhir' => ['required', 'string', 'max:20'],
            'pangkat_terakhir' => ['nullable', 'string', 'max:100'],
            'jabatan_terakhir' => ['required', 'string', 'max:255'],
            'kelas_jabatan_terakhir' => ['required', 'string', 'max:10'],
            'kelas_jabatan' => ['nullable', 'string', 'max:10'],
            'pendidikan_terakhir' => ['required', 'string', 'max:20'],
            'prodi_pendidikan_terakhir' => ['required', 'string', 'max:255'],
            'tanggal_pensiun' => ['nullable', 'date'],
            'no_hp' => ['required', 'string', 'max:20'],
            'role' => ['required', 'in:admin_kepegawaian,pimpinan,kepala_bagian,pegawai'],
        ];
    }

    public static function attributes(): array
    {
        return [
            'nama_lengkap' => 'Nama Lengkap',
            'nama_dengan_gelar' => 'Nama dengan Gelar',
            'nip' => 'NIP',
            'nik' => 'NIK',
            'no_kk' => 'No. KK',
            'tempat_lahir' => 'Tempat Lahir',
            'tanggal_lahir' => 'Tanggal Lahir',
            'jenis_kelamin' => 'Jenis Kelamin',
            'agama_id' => 'Agama',
            'status_kawin_id' => 'Status Perkawinan',
            'golongan_darah' => 'Golongan Darah',
            'foto' => 'Foto',
            'jenis_pegawai_id' => 'Jenis Pegawai',
            'jenis_pegawai' => 'Jenis Pegawai',
            'status_aktif' => 'Status Aktif',
            'status_pegawai_id' => 'Status Pegawai',
            'status_keterangan' => 'Keterangan Status',
            'kepala_bagian_id' => 'Kepala Bagian',
            'golongan_terakhir' => 'Golongan',
            'pangkat_terakhir' => 'Pangkat',
            'jabatan_terakhir' => 'Jabatan',
            'jabatan_id' => 'Jabatan',
            'kelas_jabatan' => 'Kelas Jabatan',
            'kelas_jabatan_terakhir' => 'Kelas Jabatan',
            'pendidikan_terakhir' => 'Pendidikan Terakhir',
            'prodi_pendidikan_terakhir' => 'Prodi Pendidikan Terakhir',

            'is_kepala_lembaga' => 'Penanda Kepala Lembaga',
            'alamat' => 'Alamat',
            'no_hp' => 'Nomor HP',
            'email' => 'Email Pegawai',
            'email_pribadi' => 'Email Pegawai',
            'no_telepon_rumah' => 'No. Telepon Rumah',
            'role' => 'Role',
        ];
    }
}
