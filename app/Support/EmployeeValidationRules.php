<?php

namespace App\Support;

class EmployeeValidationRules
{
    /**
     * Full validation rules for creating an employee via form.
     */
    public static function create(): array
    {
        return [
            'nama_lengkap' => ['required', 'string', 'max:255'],
            'nip' => ['required', 'string', 'size:18', 'unique:employees,nip'],
            'nik' => ['nullable', 'string', 'size:16'],
            'no_kk' => ['nullable', 'string', 'size:16'],
            'tempat_lahir' => ['nullable', 'string', 'max:100'],
            'tanggal_lahir' => ['required', 'date', 'before:today'],
            'jenis_kelamin' => ['nullable', 'in:L,P'],
            'agama_id' => ['nullable', 'uuid', 'exists:ref_agama,id'],
            'status_kawin_id' => ['nullable', 'uuid', 'exists:ref_status_perkawinan,id'],
            'golongan_darah' => ['nullable', 'in:A,B,AB,O'],
            'foto' => ['nullable', 'string', 'max:255'],
            'jenis_pegawai_id' => ['required', 'uuid', 'exists:ref_jenis_pegawai,id'],
            'status_aktif' => ['nullable', 'in:Aktif,Non-Aktif,Pensiun,Mutasi'],

            // Snapshot
            'golongan_terakhir' => ['nullable', 'string', 'max:20'],
            'pangkat_terakhir' => ['nullable', 'string', 'max:100'],
            'jabatan_terakhir' => ['nullable', 'string', 'max:255'],
            'kelas_jabatan' => ['nullable', 'string', 'max:10'],

            // Pendidikan snapshot
            'pendidikan_terakhir' => ['nullable', 'string', 'max:20'],
            'prodi_pendidikan_terakhir' => ['nullable', 'string', 'max:255'],

            // Pensiun
            'tanggal_pensiun' => ['nullable', 'date'],

            // Kontak
            'alamat' => ['nullable', 'string'],
            'no_hp' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255', 'unique:employees,email'],
            'no_telepon_rumah' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * Relaxed rules for import from Excel/CSV.
     * Only fields available in the Excel are validated; the rest are nullable.
     */
    public static function import(): array
    {
        return [
            'nama_lengkap' => ['required', 'string', 'max:255'],
            'nip' => ['required', 'string', 'size:18', 'unique:employees,nip'],
            'email' => ['nullable', 'email', 'max:255', 'unique:employees,email'],
            'tanggal_lahir' => ['nullable', 'date', 'before:today'],
            'jenis_pegawai' => ['required', 'in:PNS,PPPK,CPNS'],
            'golongan_terakhir' => ['nullable', 'string', 'max:20'],
            'pangkat_terakhir' => ['nullable', 'string', 'max:100'],
            'jabatan_terakhir' => ['nullable', 'string', 'max:255'],
            'kelas_jabatan' => ['nullable', 'string', 'max:10'],
            'pendidikan_terakhir' => ['nullable', 'string', 'max:20'],
            'prodi_pendidikan_terakhir' => ['nullable', 'string', 'max:255'],
            'tanggal_pensiun' => ['nullable', 'date'],
            'no_hp' => ['nullable', 'string', 'max:20'],
        ];
    }

    public static function attributes(): array
    {
        return [
            'nama_lengkap' => 'Nama Lengkap',
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
            'golongan_terakhir' => 'Golongan',
            'pangkat_terakhir' => 'Pangkat',
            'jabatan_terakhir' => 'Jabatan',
            'kelas_jabatan' => 'Kelas Jabatan',
            'pendidikan_terakhir' => 'Pendidikan Terakhir',
            'prodi_pendidikan_terakhir' => 'Prodi Pendidikan Terakhir',
            'tanggal_pensiun' => 'Tanggal Pensiun',
            'alamat' => 'Alamat',
            'no_hp' => 'Nomor HP',
            'email' => 'Email Pegawai',
            'no_telepon_rumah' => 'No. Telepon Rumah',
        ];
    }
}
