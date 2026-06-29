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

    /**
     * Nilai contoh per tipe; hanya panduan format, bukan data nyata.
     *
     * @return array<string, string>
     */
    private function sampleValues(string $type): array
    {
        return match ($type) {
            'utama' => [
                'No' => '1',
                'Email Pegawai' => 'contoh@example.com',
                'Golongan' => 'III/a',
                'Jabatan' => 'Analis Kepegawaian',
                'Kelas Jabatan' => '7',
                'NIP' => '000000000000000000',
                'Nomor Telepon' => '081200000000',
                'Pangkat' => 'Penata Muda',
                'Pendidikan Terakhir' => 'S1',
                'Pensiun' => '2038-01-01',
                'Prodi Pendidikan Terakhir' => 'Manajemen',
                'Status Kepegawaian' => 'PNS',
                'Tanggal Lahir' => '1980-01-01',
                'Role' => 'pegawai',
            ],
            'pelengkap' => [
                'NIK' => '0000000000000000',
                'No KK' => '0000000000000000',
                'Tempat Lahir' => 'Manado',
                'Jenis Kelamin' => 'L',
                'Agama' => 'Islam',
                'Status Kawin' => 'Kawin',
                'Golongan Darah' => 'O',
            ],
            'kepangkatan' => [
                'Golongan' => 'III/a',
                'TMT Pangkat' => '2020-01-01',
                'No SK' => 'SK/0001/2020',
                'Tanggal SK' => '2019-12-01',
            ],
            'jabatan' => [
                'Nama Jabatan' => 'Analis Kepegawaian',
                'Jenis Jabatan' => 'Fungsional',
                'Unit Kerja' => 'Bagian Umum',
                'TMT Jabatan' => '2020-01-01',
                'No SK' => 'SK/0002/2020',
                'Tanggal SK' => '2019-12-01',
            ],
            'kgb' => [
                'TMT KGB' => '2022-01-01',
                'Gaji Pokok' => '3000000',
                'No SK' => 'SK/0003/2022',
                'Tanggal SK' => '2021-12-01',
            ],
            default => [],
        };
    }
}
