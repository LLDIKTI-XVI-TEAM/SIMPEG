<?php

namespace Tests\Unit;

use App\Support\EmployeeImport\EmployeeRowMapper;
use PHPUnit\Framework\TestCase;

class EmployeeRowMapperTest extends TestCase
{
    public function test_maps_headers_to_employee_fields_and_normalizes_values(): void
    {
        $mapper = new EmployeeRowMapper();

        $mapped = $mapper->map([
            'Nama Pegawai' => ' Budi Santoso ',
            'Email Pegawai' => ' budi@example.com ',
            'Golongan' => 'III/a',
            'Jabatan' => 'Analis Kepegawaian',
            'Kelas Jabatan' => '7',
            'NIP' => '198001012006041001',
            'Nomor Telepon' => '081234567890',
            'Pangkat' => 'Penata Muda',
            'Pendidikan Terakhir' => 'S1',
            'Pensiun' => '01/01/2038',
            'Person' => '',
            'Person Formula' => 'Budi',
            'Prodi Pendidikan Terakhir' => 'Manajemen',
            'Status Kepegawaian' => 'PNS',
            'Tanggal Lahir' => '01-01-1980',
        ]);

        $this->assertSame('Budi Santoso', $mapped['nama_lengkap']);
        $this->assertSame('budi@example.com', $mapped['email_pribadi']);
        $this->assertArrayNotHasKey('person', $mapped);
        $this->assertSame('2038-01-01', $mapped['tanggal_pensiun']);
        $this->assertSame('1980-01-01', $mapped['tanggal_lahir']);
    }

    public function test_validates_missing_headers(): void
    {
        $mapper = new EmployeeRowMapper();

        $result = $mapper->validateHeaders(['Nama Pegawai', 'Email Pegawai']);

        $this->assertContains('Tanggal Lahir', $result['missing']);
    }

    public function test_normalizes_bom_header(): void
    {
        $mapper = new EmployeeRowMapper();

        $this->assertSame('Nama Pegawai', $mapper->normalizeHeader("\xEF\xBB\xBFNama Pegawai"));
    }
}
