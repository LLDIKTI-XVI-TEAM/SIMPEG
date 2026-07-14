<?php

namespace App\Http\Requests\Laporan;

use Illuminate\Validation\Rule;

class CustomEmployeeExportRequest extends ExportPegawaiRequest
{
    /**
     * Kolom yang aman untuk laporan nominatif. Data identitas sensitif seperti
     * NIK dan No. KK sengaja tidak tersedia pada kontrak export ini.
     *
     * @var array<string, string>
     */
    public const ALLOWED_COLUMNS = [
        'nip' => 'NIP',
        'nama' => 'Nama',
        'golongan' => 'Golongan',
        'jabatan' => 'Jabatan',
        'unit' => 'Unit Kerja',
        'jenis' => 'Jenis Pegawai',
        'status' => 'Status',
        'pendidikan' => 'Pendidikan Terakhir',
        'tanggal_pensiun' => 'Tanggal Pensiun',
    ];

    /** @return array<string, list<string|Rule>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'columns' => ['required', 'array', 'min:1', 'max:'.count(self::ALLOWED_COLUMNS)],
            'columns.*' => ['required', 'string', Rule::in(array_keys(self::ALLOWED_COLUMNS)), 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'columns.required' => 'Pilih minimal satu kolom untuk export custom.',
            'columns.min' => 'Pilih minimal satu kolom untuk export custom.',
            'columns.*.in' => 'Kolom yang dipilih tidak diizinkan untuk laporan.',
        ];
    }
}
