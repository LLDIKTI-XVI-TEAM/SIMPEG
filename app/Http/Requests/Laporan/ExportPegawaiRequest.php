<?php

namespace App\Http\Requests\Laporan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportPegawaiRequest extends FormRequest
{
    /**
     * Kolom yang boleh dipilih pada export standar (termasuk 'no' dan data non-sensitif).
     * NIK, No. KK, email, dan no. HP sengaja dikecualikan.
     *
     * @var array<string, string>
     */
    public const ALLOWED_COLUMNS = [
        'no' => 'No',
        'nip' => 'NIP',
        'nama' => 'Nama',
        'golongan' => 'Golongan',
        'jabatan' => 'Jabatan',
        'unit' => 'Unit Kerja',
        'jenis' => 'Jenis Pegawai',
        'status' => 'Status',
        'pendidikan' => 'Pendidikan Terakhir',
        'tanggal_pensiun' => 'Tgl. Pensiun',
    ];

    /** @var list<string> */
    protected const REPORTING_ROLES = [
        'super_admin',
        'admin_kepegawaian',
        'pimpinan',
    ];

    public function authorize(): bool
    {
        return in_array($this->user()?->role, self::REPORTING_ROLES, true);
    }

    /** @return array<string, list<string|Rule>> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:255'],
            'golongan' => ['nullable', 'string', 'max:50'],
            'jenis' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:100'],
            'jabatan' => ['nullable', 'string', 'max:255'],
            'pensiun_dari' => ['nullable', 'date_format:Y-m-d'],
            'pensiun_sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:pensiun_dari'],
            'sort' => ['nullable', 'string', Rule::in(['nama', 'nip', 'golongan'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'prefix_field' => ['nullable', 'string', 'max:50'],
            'prefix_value' => ['nullable', 'string', 'max:100'],
            'row_start' => ['nullable', 'integer', 'min:1'],
            'row_end' => ['nullable', 'integer', 'min:1'],
            // Kolom opsional — jika tidak dikirim, backend pakai default.
            'columns' => ['nullable', 'array', 'max:'.count(self::ALLOWED_COLUMNS)],
            'columns.*' => ['string', Rule::in(array_keys(self::ALLOWED_COLUMNS)), 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'unit' => 'unit kerja',
            'jenis' => 'jenis pegawai',
            'pensiun_dari' => 'tanggal pensiun mulai',
            'pensiun_sampai' => 'tanggal pensiun sampai',
        ];
    }
}
