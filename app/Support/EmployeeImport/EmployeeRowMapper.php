<?php

namespace App\Support\EmployeeImport;

use Carbon\Carbon;

class EmployeeRowMapper
{
    /**
     * Expected headers from daftar_pegawai.xlsx.
     */
    public const HEADERS = [
        'Nama Pegawai',
        'Email Pegawai',
        'Golongan',
        'Jabatan',
        'Kelas Jabatan',
        'NIP',
        'Nomor Telepon',
        'Pangkat',
        'Pendidikan Terakhir',
        'Pensiun',
        'Person',
        'Person Formula',
        'Prodi Pendidikan Terakhir',
        'Status Kepegawaian',
        'Tanggal Lahir',
    ];

    /**
     * Mapping from Excel header to new SIMPEG field names.
     * 'Person' and 'Person Formula' are ignored (not in PRD schema).
     */
    private const MAP = [
        'Nama Pegawai' => 'nama_lengkap',
        'Email Pegawai' => 'email_pribadi',
        'Golongan' => 'golongan_terakhir',
        'Jabatan' => 'jabatan_terakhir',
        'Kelas Jabatan' => 'kelas_jabatan',
        'NIP' => 'nip',
        'Nomor Telepon' => 'no_hp',
        'Pangkat' => 'pangkat_terakhir',
        'Pendidikan Terakhir' => 'pendidikan_terakhir',
        'Pensiun' => 'tanggal_pensiun',
        'Prodi Pendidikan Terakhir' => 'prodi_pendidikan_terakhir',
        'Status Kepegawaian' => 'jenis_pegawai',
        'Tanggal Lahir' => 'tanggal_lahir',
    ];

    /**
     * Mapping for Status Kepegawaian values to enum values.
     */
    private const STATUS_MAP = [
        'pns' => 'PNS',
        'pppk' => 'PPPK',
        'cpns' => 'CPNS',
        'p3k' => 'PPPK',
    ];

    public function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = preg_replace('/\s+/', ' ', trim($header)) ?? $header;

        return $header;
    }

    public function validateHeaders(array $headers): array
    {
        $normalized = array_map(fn ($header) => $this->normalizeHeader((string) $header), $headers);
        $missing = array_values(array_diff(self::HEADERS, $normalized));

        return [
            'headers' => $normalized,
            'missing' => $missing,
        ];
    }

    public function map(array $row): array
    {
        $mapped = [];

        foreach (self::MAP as $header => $field) {
            $value = $row[$header] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            $value = $value === '' ? null : $value;

            if (in_array($field, ['tanggal_pensiun', 'tanggal_lahir'], true)) {
                $value = $this->parseDate($value);
            }

            if ($field === 'jenis_pegawai' && $value !== null) {
                $value = self::STATUS_MAP[strtolower($value)] ?? $value;
            }

            $mapped[$field] = $value;
        }

        // Auto-set defaults for imported records
        $mapped['status_aktif'] = 'Aktif';
        $mapped['profil_status'] = 'belum_lengkap';
        $mapped['is_kinerja_baik'] = true;

        return $mapped;
    }

    public function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function parseDate(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);

                if ($date && $date->format($format) === $value) {
                    return $date->format('Y-m-d');
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $value;
    }
}
