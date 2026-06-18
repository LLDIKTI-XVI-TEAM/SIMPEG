<?php

namespace App\Support;

class EmployeeValidationRules
{
    public static function create(): array
    {
        return [
            'nama_pegawai' => ['required', 'string', 'max:255'],
            'email_pegawai' => ['nullable', 'email', 'max:255', 'unique:employees,email_pegawai'],
            'golongan' => ['nullable', 'string', 'max:50'],
            'jabatan' => ['nullable', 'string', 'max:255'],
            'kelas_jabatan' => ['nullable', 'string', 'max:50'],
            'nip' => ['nullable', 'string', 'max:50', 'unique:employees,nip'],
            'nomor_telepon' => ['nullable', 'string', 'max:30'],
            'pangkat' => ['nullable', 'string', 'max:100'],
            'pendidikan_terakhir' => ['nullable', 'string', 'max:100'],
            'pensiun' => ['nullable', 'date'],
            'person' => ['nullable', 'string', 'max:255'],
            'person_formula' => ['nullable', 'string', 'max:255'],
            'prodi_pendidikan_terakhir' => ['nullable', 'string', 'max:255'],
            'status_kepegawaian' => ['nullable', 'string', 'max:100'],
            'tanggal_lahir' => ['nullable', 'date', 'before:today'],
        ];
    }

    public static function attributes(): array
    {
        return [
            'nama_pegawai' => 'Nama Pegawai',
            'email_pegawai' => 'Email Pegawai',
            'golongan' => 'Golongan',
            'jabatan' => 'Jabatan',
            'kelas_jabatan' => 'Kelas Jabatan',
            'nip' => 'NIP',
            'nomor_telepon' => 'Nomor Telepon',
            'pangkat' => 'Pangkat',
            'pendidikan_terakhir' => 'Pendidikan Terakhir',
            'pensiun' => 'Pensiun',
            'person' => 'Person',
            'person_formula' => 'Person Formula',
            'prodi_pendidikan_terakhir' => 'Prodi Pendidikan Terakhir',
            'status_kepegawaian' => 'Status Kepegawaian',
            'tanggal_lahir' => 'Tanggal Lahir',
        ];
    }
}
