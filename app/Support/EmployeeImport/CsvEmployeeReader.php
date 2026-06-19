<?php

namespace App\Support\EmployeeImport;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use SplFileObject;

class CsvEmployeeReader
{
    public function __construct(private readonly EmployeeRowMapper $mapper) {}

    public function read(UploadedFile $file): array
    {
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

            $row = array_pad($row, count($headers), null);
            $row = array_slice($row, 0, count($headers));
            $rows[] = [
                'row' => $index + 1,
                'data' => $this->mapper->map(array_combine($headers, $row)),
            ];
        }

        if ($headers === null) {
            throw new RuntimeException('File CSV tidak memiliki header.');
        }

        return $rows;
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
