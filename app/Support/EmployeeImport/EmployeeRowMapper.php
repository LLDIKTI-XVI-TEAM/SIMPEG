<?php

namespace App\Support\EmployeeImport;

use Carbon\Carbon;

class EmployeeRowMapper
{
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

    private const MAP = [
        'Nama Pegawai' => 'nama_pegawai',
        'Email Pegawai' => 'email_pegawai',
        'Golongan' => 'golongan',
        'Jabatan' => 'jabatan',
        'Kelas Jabatan' => 'kelas_jabatan',
        'NIP' => 'nip',
        'Nomor Telepon' => 'nomor_telepon',
        'Pangkat' => 'pangkat',
        'Pendidikan Terakhir' => 'pendidikan_terakhir',
        'Pensiun' => 'pensiun',
        'Person' => 'person',
        'Person Formula' => 'person_formula',
        'Prodi Pendidikan Terakhir' => 'prodi_pendidikan_terakhir',
        'Status Kepegawaian' => 'status_kepegawaian',
        'Tanggal Lahir' => 'tanggal_lahir',
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

            if (in_array($field, ['pensiun', 'tanggal_lahir'], true)) {
                $value = $this->parseDate($value);
            }

            $mapped[$field] = $value;
        }

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
