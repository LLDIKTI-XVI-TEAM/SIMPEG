<?php

namespace App\Actions\Employees;

use App\Support\EmployeeImport\EmployeeRowMapper;
use InvalidArgumentException;

class GenerateImportTemplateAction
{
    /**
     * Bangun definisi template (header + satu baris contoh) untuk satu tipe import.
     * Header diambil dari UploadImportBatchAction::TEMPLATE_HEADERS (sumber tunggal)
     * agar tidak terjadi drift dengan validasi importer yang menyebabkan kolom
     * wajib seperti Role hilang dari template.
     *
     * @return array{headers: array<int, string>, example: array<string, string|null>}
     */
    public function execute(string $type): array
    {
        $canonical = UploadImportBatchAction::TEMPLATE_HEADERS[$type] ?? null;

        if ($canonical === null) {
            throw new InvalidArgumentException("Tipe template tidak dikenal: {$type}");
        }

        // Kolom display 'No' hanya untuk tampilan template utama; importer mengabaikannya.
        $headers = $type === 'utama'
            ? array_merge(['No'], $canonical)
            : $canonical;

        return [
            'headers' => $headers,
            'example' => $this->exampleRow($type, $headers),
        ];
    }

    /**
     * Susun satu baris contoh. Kolom identitas pertama diisi penanda contoh
     * sehingga importer dapat melewatinya bila admin lupa menghapus baris ini.
     *
     * @param  array<int, string>  $headers
     * @return array<string, string|null>
     */
    private function exampleRow(string $type, array $headers): array
    {
        $samples = $this->sampleValues($type);

        $row = [];
        foreach ($headers as $header) {
            $row[$header] = $samples[$header] ?? null;
        }

        // Penanda diletakkan pada kolom nama/identitas pertama tiap tipe.
        $markerColumn = $type === 'utama' ? 'Nama Pegawai' : 'NIP';
        $row[$markerColumn] = EmployeeRowMapper::EXAMPLE_ROW_MARKER;

        return $row;
    }

    private function sampleValues(string $type): array
    {
        return match ($type) {
            'utama' => [
                'No'                         => '1',
                'Nama Pegawai'               => 'Ahmad Fauzi, S.Kom.',
                'Person'                     => 'Ahmad Saeful Fauzi',
                'Person Formula'             => 'Ahmad Saeful Fauzi',
                'Email Pegawai'              => 'contoh@example.com',
                'Golongan'                   => 'III/a',
                'Jabatan'                    => 'Analis Kepegawaian',
                'Kelas Jabatan'              => '7',
                'NIP'                        => '000000000000000000',
                'Nomor Telepon'              => '081200000000',
                'Pangkat'                    => 'Penata Muda',
                'Pendidikan Terakhir'        => 'S1',
                'Pensiun'                    => '2038-01-01',
                'Prodi Pendidikan Terakhir'  => 'Manajemen',
                'Status Kepegawaian'         => 'PNS',
                'Tanggal Lahir'              => '1980-01-01',
                'Role'                       => 'pegawai',
            ],
            default => [],
        };
    }
}
