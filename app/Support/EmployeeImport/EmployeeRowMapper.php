<?php

namespace App\Support\EmployeeImport;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

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
        'Role',
    ];

    /**
     * Optional headers that may or may not be present in the file.
     */
    public const OPTIONAL_HEADERS = [
        'NIK',
        'No KK',
    ];

    /**
     * Penanda baris contoh pada template import.
     * Dipakai bersama oleh penulis template dan pembaca import:
     * penulis menaruh penanda ini pada baris contoh, pembaca melewatinya
     * agar baris contoh tidak ikut ter-import bila admin lupa menghapusnya.
     */
    public const EXAMPLE_ROW_MARKER = 'CONTOH - HAPUS BARIS INI';

    /**
     * Mapping from Excel header to new SIMPEG field names.
     * 'Person' and 'Person Formula' are ignored (not in PRD schema).
     */
    private const MAP = [
        'Nama Pegawai' => 'nama_lengkap',
        'Email Pegawai' => 'email',
        'Golongan' => 'golongan_terakhir',
        'Jabatan' => 'jabatan_terakhir',
        'Kelas Jabatan' => 'kelas_jabatan',
        'NIP' => 'nip',
        'NIK' => 'nik',
        'No KK' => 'no_kk',
        'Nomor Telepon' => 'no_hp',
        'Pangkat' => 'pangkat_terakhir',
        'Pendidikan Terakhir' => 'pendidikan_terakhir',
        'Pensiun' => 'tanggal_pensiun',
        'Prodi Pendidikan Terakhir' => 'prodi_pendidikan_terakhir',
        'Status Kepegawaian' => 'jenis_pegawai',
        'Tanggal Lahir' => 'tanggal_lahir',
        'Role' => 'role',
    ];

    /**
     * Mapping untuk normalisasi nilai Role dari berbagai format input.
     */
    private const ROLE_MAP = [
        'admin_kepegawaian' => 'admin_kepegawaian',
        'admin kepegawaian' => 'admin_kepegawaian',
        'pimpinan' => 'pimpinan',
        'atasan_langsung' => 'atasan_langsung',
        'atasan langsung' => 'atasan_langsung',
        'pegawai' => 'pegawai',
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

    /**
     * Check which optional headers are present in the file.
     */
    public function detectOptionalHeaders(array $headers): array
    {
        $normalized = array_map(fn ($header) => $this->normalizeHeader((string) $header), $headers);

        return array_values(array_intersect(self::OPTIONAL_HEADERS, $normalized));
    }

    public function map(array $row): array
    {
        // ── Auto-Alignment for Shifted Columns ───────────────────────────────
        // Detect if columns are shifted due to missing NIK and No KK values.
        // If NIK (col 8) is a phone number (starts with 08 or is 9-14 digits)
        // AND Nomor Telepon (col 10) contains education (e.g. S1, S2, D3, etc.),
        // the row has shifted left.
        $nikVal = isset($row['NIK']) ? trim((string) $row['NIK']) : '';
        $phoneVal = isset($row['Nomor Telepon']) ? trim((string) $row['Nomor Telepon']) : '';

        $isNikAPhone = preg_match('/^(08|\+?62)\d+$/', $nikVal) || (is_numeric($nikVal) && strlen($nikVal) >= 9 && strlen($nikVal) <= 14);
        $isPhoneEducation = in_array(strtoupper($phoneVal), ['SD', 'SMP', 'SMA', 'SMK', 'D1', 'D2', 'D3', 'D4', 'S1', 'S2', 'S3'], true);

        if ($isNikAPhone && $isPhoneEducation) {
            $row['Role'] = $row['Status Kepegawaian'] ?? null;
            $row['Tanggal Lahir'] = $row['Prodi Pendidikan Terakhir'] ?? null;
            $row['Status Kepegawaian'] = $row['Person Formula'] ?? null;
            $row['Prodi Pendidikan Terakhir'] = $row['Person'] ?? null;
            $row['Pensiun'] = $row['Pangkat'] ?? null;
            $row['Pangkat'] = $row['No KK'] ?? null;
            $row['Pendidikan Terakhir'] = $row['Nomor Telepon'] ?? null;
            $row['Nomor Telepon'] = $row['NIK'] ?? null;
            $row['NIK'] = null;
            $row['No KK'] = null;
        }

        $mapped = [];

        foreach (self::MAP as $header => $field) {
            $value = $row[$header] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            $value = $value === '' ? null : $value;

            if ($value !== null && ! in_array($field, ['tanggal_pensiun', 'tanggal_lahir'], true)) {
                $value = (string) $value;
            }

            // Normalize scientific notation for NIP, NIK, and KK (long numerical values)
            if (in_array($field, ['nip', 'nik', 'no_kk'], true) && $value !== null) {
                if (preg_match('/^[0-9]+(\.[0-9]+)?[eE]\+?[0-9]+$/', $value)) {
                    $value = number_format((float) $value, 0, '', '');
                }
            }

            if (in_array($field, ['tanggal_pensiun', 'tanggal_lahir'], true)) {
                $value = $this->parseDate($value);
            }

            if ($field === 'jenis_pegawai' && $value !== null) {
                $value = self::STATUS_MAP[strtolower($value)] ?? $value;
            }

            // Normalisasi role ke format snake_case yang valid
            if ($field === 'role' && $value !== null) {
                $value = self::ROLE_MAP[strtolower($value)] ?? $value;
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

    /**
     * Cek apakah baris merupakan baris contoh template (mengandung penanda).
     * Pemeriksaan lintas kolom agar tetap dikenali walau urutan kolom berubah,
     * sehingga baris contoh tidak ikut ter-import bila admin lupa menghapusnya.
     */
    public function isExampleRow(array $row): bool
    {
        foreach ($row as $value) {
            if (is_string($value) && trim($value) === self::EXAMPLE_ROW_MARKER) {
                return true;
            }
        }

        return false;
    }

    private function parseDate(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return $value;
            }
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'F j, Y', 'F d, Y', 'M j, Y', 'M d, Y'] as $format) {
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
