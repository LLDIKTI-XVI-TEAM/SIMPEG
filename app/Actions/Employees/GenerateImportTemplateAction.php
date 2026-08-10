<?php

namespace App\Actions\Employees;

use App\Support\EmployeeImport\EmployeeRowMapper;
use InvalidArgumentException;

class GenerateImportTemplateAction
{
    /**
     * Bangun definisi template (header + dua baris contoh) untuk satu tipe import.
     * Header diambil dari UploadImportBatchAction::TEMPLATE_HEADERS (sumber tunggal)
     * agar tidak terjadi drift dengan validasi importer. Role sengaja tidak menjadi
     * kolom template: penetapan role aplikasi berjalan lewat Kelola Akses User.
     *
     * @return array{headers: array<int, string>, examples: array<int, array<string, string|null>>}
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
            'examples' => $this->exampleRows($type, $headers),
        ];
    }

    /**
     * Susun baris-baris contoh. Setiap baris membawa penanda contoh pada kolom
     * identitas pertama sehingga importer melewatinya bila admin lupa menghapus.
     *
     * @param  array<int, string>  $headers
     * @return array<int, array<string, string|null>>
     */
    private function exampleRows(string $type, array $headers): array
    {
        $markerColumn = $type === 'utama' ? 'Nama Pegawai' : 'NIP';

        $rows = [];
        foreach ($this->sampleValueSets($type) as $samples) {
            $row = [];
            foreach ($headers as $header) {
                $row[$header] = $samples[$header] ?? null;
            }

            $row[$markerColumn] = EmployeeRowMapper::EXAMPLE_ROW_MARKER;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Dua set nilai contoh: PNS lengkap dan PPPK dengan Pangkat/Pensiun kosong
     * sebagai panduan bahwa kedua kolom tersebut opsional untuk non-PNS.
     *
     * @return array<int, array<string, string|null>>
     */
    private function sampleValueSets(string $type): array
    {
        return match ($type) {
            'utama' => [
                [
                    'No' => '1',
                    'Nama Pegawai' => 'Ahmad Fauzi, S.Kom.',
                    'Person' => 'Ahmad Saeful Fauzi',
                    'Person Formula' => 'Ahmad Saeful Fauzi',
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
                ],
                [
                    'No' => '2',
                    'Nama Pegawai' => 'Siti Rahmawati, A.Md.',
                    'Person' => 'Siti Rahmawati',
                    'Person Formula' => 'Siti Rahmawati',
                    'Email Pegawai' => 'contoh2@example.com',
                    'Golongan' => 'II/c',
                    'Jabatan' => 'Pengelola Kepegawaian',
                    'Kelas Jabatan' => '6',
                    'NIP' => '000000000000000001',
                    'Nomor Telepon' => '081200000001',
                    'Pangkat' => null,
                    'Pendidikan Terakhir' => 'D3',
                    'Pensiun' => null,
                    'Prodi Pendidikan Terakhir' => 'Administrasi Perkantoran',
                    'Status Kepegawaian' => 'PPPK',
                    'Tanggal Lahir' => '1990-05-15',
                ],
            ],
            default => [],
        };
    }
}
