<?php

namespace Tests\Unit;

use App\Support\EmployeeImport\EmployeeRowMapper;
use PHPUnit\Framework\TestCase;

class EmployeeRowMapperTest extends TestCase
{
    public function test_maps_headers_to_employee_fields_and_normalizes_values(): void
    {
        $mapper = new EmployeeRowMapper;

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

        $this->assertSame('Budi Santoso', $mapped['nama_dengan_gelar']);
        $this->assertNull($mapped['nama_lengkap']);
        $this->assertSame('budi@example.com', $mapped['email_pribadi']);
        $this->assertArrayNotHasKey('person', $mapped);
        $this->assertSame('2038-01-01', $mapped['tanggal_pensiun']);
        $this->assertSame('1980-01-01', $mapped['tanggal_lahir']);
    }

    public function test_parses_english_long_month_dates(): void
    {
        $mapper = new EmployeeRowMapper;

        $mapped = $mapper->map([
            'Nama Pegawai' => 'Andi',
            'Tanggal Lahir' => 'June 25, 1979',
            'Pensiun' => 'July 1, 2037',
            'Status Kepegawaian' => 'PNS',
        ]);

        $this->assertSame('1979-06-25', $mapped['tanggal_lahir']);
        $this->assertSame('2037-07-01', $mapped['tanggal_pensiun']);
    }

    public function test_parses_short_month_and_iso_dates(): void
    {
        $mapper = new EmployeeRowMapper;

        $mapped = $mapper->map([
            'Nama Pegawai' => 'Budi',
            'Tanggal Lahir' => 'Jun 5, 1979',
            'Pensiun' => '2037-07-01',
            'Status Kepegawaian' => 'PNS',
        ]);

        $this->assertSame('1979-06-05', $mapped['tanggal_lahir']);
        $this->assertSame('2037-07-01', $mapped['tanggal_pensiun']);
    }

    public function test_validates_missing_headers(): void
    {
        $mapper = new EmployeeRowMapper;

        $result = $mapper->validateHeaders(['Nama Pegawai', 'Email Pegawai']);

        $this->assertContains('Tanggal Lahir', $result['missing']);
    }

    public function test_normalizes_bom_header(): void
    {
        $mapper = new EmployeeRowMapper;

        $this->assertSame('Nama Pegawai', $mapper->normalizeHeader("\xEF\xBB\xBFNama Pegawai"));
    }

    public function test_detects_example_marker_rows_regardless_of_column_position(): void
    {
        $mapper = new EmployeeRowMapper;

        $this->assertTrue($mapper->isExampleRow(['CONTOH - HAPUS BARIS INI', 'x', 'y']));
        $this->assertTrue($mapper->isExampleRow(['x', 'CONTOH - HAPUS BARIS INI', 'y']));
        $this->assertFalse($mapper->isExampleRow(['Budi', 'budi@mail.com', '198001012006041001']));
        $this->assertFalse($mapper->isExampleRow([null, '', '   ']));
    }

    public function test_exposes_the_example_row_marker_constant(): void
    {
        $this->assertSame('CONTOH - HAPUS BARIS INI', EmployeeRowMapper::EXAMPLE_ROW_MARKER);
    }
}
