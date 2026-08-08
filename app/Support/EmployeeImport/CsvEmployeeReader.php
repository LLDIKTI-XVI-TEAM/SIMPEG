<?php

namespace App\Support\EmployeeImport;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use SplFileObject;

class CsvEmployeeReader
{
    public function __construct(private readonly EmployeeRowMapper $mapper) {}

    public function read(UploadedFile $file): array
    {
        if ($this->isSpreadsheet($file)) {
            return $this->readSpreadsheet($file, mapRows: true);
        }

        $csv = new SplFileObject($file->getRealPath());
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $csv->setCsvControl($this->detectDelimiter($file), '"', '');

        $headers = null;
        $rows = [];

        foreach ($csv as $index => $row) {
            if ($row === [null] || $row === false || $this->mapper->isEmptyRow($row)) {
                continue;
            }

            if ($headers === null) {
                $validation = $this->mapper->validateHeaders($row);

                if ($validation['missing'] !== []) {
                    throw new RuntimeException('Header CSV tidak lengkap: '.implode(', ', $validation['missing']));
                }

                $headers = $validation['headers'];

                continue;
            }

            // Lewati baris contoh template agar tidak ikut ter-import bila admin lupa menghapusnya.
            if ($this->mapper->isExampleRow($row)) {
                continue;
            }

            $row = array_pad($row, count($headers), null);
            $row = array_slice($row, 0, count($headers));
            $rows[] = [
                'row' => $index + 1,
                'data' => $this->mapper->map($this->combineRow($headers, $row, keepNoColumn: true)),
            ];
        }

        if ($headers === null) {
            throw new RuntimeException('File CSV tidak memiliki header.');
        }

        return $rows;
    }

    public function readRaw(UploadedFile $file): array
    {
        if ($this->isSpreadsheet($file)) {
            return $this->readSpreadsheet($file, mapRows: false);
        }

        $csv = new SplFileObject($file->getRealPath());
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $csv->setCsvControl($this->detectDelimiter($file), '"', '');

        $headers = null;
        $rows = [];

        foreach ($csv as $index => $row) {
            if ($row === [null] || $row === false || $this->mapper->isEmptyRow($row)) {
                continue;
            }

            if ($headers === null) {
                $headers = array_map(fn ($header) => $this->mapper->normalizeHeader((string) $header), $row);

                continue;
            }

            // Lewati baris contoh template agar tidak ikut ter-import bila admin lupa menghapusnya.
            if ($this->mapper->isExampleRow($row)) {
                continue;
            }

            $row = array_pad($row, count($headers), null);
            $row = array_slice($row, 0, count($headers));
            $combined = $this->combineRow($headers, $row, keepNoColumn: false);

            $rows[] = [
                'row' => $index + 1,
                'data' => $combined,
            ];
        }

        if ($headers === null) {
            throw new RuntimeException('File CSV tidak memiliki header.');
        }

        return $rows;
    }

    private function readSpreadsheet(UploadedFile $file, bool $mapRows): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
        } catch (\Throwable $exception) {
            throw new RuntimeException('File Excel tidak dapat dibaca. Pastikan format file valid.');
        }

        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $headers = null;
        $rows = [];

        for ($rowNumber = 1; $rowNumber <= $highestRow; $rowNumber++) {
            $row = $sheet->rangeToArray("A{$rowNumber}:{$highestColumn}{$rowNumber}", null, true, false)[0] ?? [];

            if ($this->mapper->isEmptyRow($row)) {
                continue;
            }

            if ($headers === null) {
                if ($mapRows) {
                    $validation = $this->mapper->validateHeaders($row);

                    if ($validation['missing'] !== []) {
                        throw new RuntimeException('Header Excel tidak lengkap: '.implode(', ', $validation['missing']));
                    }

                    $headers = $validation['headers'];
                } else {
                    $headers = array_map(fn ($header) => $this->mapper->normalizeHeader((string) $header), $row);
                }

                continue;
            }

            // Lewati baris contoh template agar tidak ikut ter-import bila admin lupa menghapusnya.
            if ($this->mapper->isExampleRow($row)) {
                continue;
            }

            $row = array_pad($row, count($headers), null);
            $row = array_slice($row, 0, count($headers));
            $combined = $this->combineRow($headers, $row, keepNoColumn: $mapRows);

            $rows[] = [
                'row' => $rowNumber,
                'data' => $mapRows ? $this->mapper->map($combined) : $combined,
            ];
        }

        if ($headers === null) {
            throw new RuntimeException('File Excel tidak memiliki header.');
        }

        return $rows;
    }

    private function combineRow(array $headers, array $row, bool $keepNoColumn): array
    {
        $combined = array_combine($headers, $row);

        if ($combined === false) {
            return [];
        }

        if (! $keepNoColumn) {
            $combined = array_filter($combined, function ($key) {
                return strtolower(trim((string) $key)) !== 'no';
            }, ARRAY_FILTER_USE_KEY);
        }

        foreach ($combined as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;
            $value = $value === '' ? null : $value;

            if ($value !== null && preg_match('/^[0-9]+(\.[0-9]+)?[eE]\+?[0-9]+$/', (string) $value)) {
                $combined[$key] = number_format((float) $value, 0, '', '');
            } else {
                $combined[$key] = $value;
            }
        }

        $combined = $this->alignShiftedOptionalIdentityColumns($combined);

        return $combined;
    }

    private function alignShiftedOptionalIdentityColumns(array $row): array
    {
        $nik = isset($row['NIK']) ? trim((string) $row['NIK']) : '';
        $phone = isset($row['Nomor Telepon']) ? trim((string) $row['Nomor Telepon']) : '';

        $nikLooksLikePhone = preg_match('/^(08|\+?62)\d+$/', $nik)
            || (is_numeric($nik) && strlen($nik) >= 9 && strlen($nik) <= 14);
        $phoneLooksLikeEducation = in_array(strtoupper($phone), ['SD', 'SMP', 'SMA', 'SMK', 'D1', 'D2', 'D3', 'D4', 'S1', 'S2', 'S3'], true);

        if (! $nikLooksLikePhone || ! $phoneLooksLikeEducation) {
            return $row;
        }

        // Pada baris yang tergeser (NIK & No KK kosong), slot Status Kepegawaian berisi
        // sisa kolom Role dari layout lama. Import tidak lagi membaca Role (penetapan role
        // berjalan lewat Kelola Akses User), jadi slot tersebut cukup ditimpa rantai di bawah.
        $row['Tanggal Lahir'] = $row['Prodi Pendidikan Terakhir'] ?? null;
        $row['Status Kepegawaian'] = $row['Person Formula'] ?? null;
        $row['Prodi Pendidikan Terakhir'] = $row['Person'] ?? null;
        $row['Person Formula'] = $row['Pensiun'] ?? null;
        $row['Person'] = $row['Pendidikan Terakhir'] ?? null;
        $row['Pensiun'] = $row['Pangkat'] ?? null;
        $row['Pangkat'] = $row['No KK'] ?? null;
        $row['Pendidikan Terakhir'] = $row['Nomor Telepon'] ?? null;
        $row['Nomor Telepon'] = $row['NIK'] ?? null;
        $row['NIK'] = null;
        $row['No KK'] = null;

        return $row;
    }

    private function isSpreadsheet(UploadedFile $file): bool
    {
        return in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'xls'], true);
    }

    private function detectDelimiter(UploadedFile $file): string
    {
        $handle = fopen($file->getRealPath(), 'r');
        $firstLine = $handle ? fgets($handle) : false;

        if ($handle) {
            fclose($handle);
        }

        if (! is_string($firstLine)) {
            return ',';
        }

        $delimiters = [',' => 0, ';' => 0, "\t" => 0];

        foreach ($delimiters as $delimiter => $count) {
            $delimiters[$delimiter] = count(str_getcsv($firstLine, $delimiter, '"', ''));
        }

        arsort($delimiters);

        return (string) array_key_first($delimiters);
    }
}
