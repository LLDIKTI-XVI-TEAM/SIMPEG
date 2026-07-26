<?php

namespace Tests\Unit;

use App\Actions\Employees\GenerateImportTemplateAction;
use App\Actions\Employees\UploadImportBatchAction;
use App\Support\EmployeeImport\EmployeeRowMapper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GenerateImportTemplateActionTest extends TestCase
{
    public function test_utama_headers_match_canonical_with_no_prepended(): void
    {
        $def = (new GenerateImportTemplateAction)->execute('utama');

        $expected = array_merge(['No'], UploadImportBatchAction::TEMPLATE_HEADERS['utama']);
        $this->assertSame($expected, $def['headers']);
        $this->assertContains('Role', $def['headers']);
    }

    public function test_template_utama_contains_two_example_rows(): void
    {
        $def = (new GenerateImportTemplateAction)->execute('utama');

        $this->assertCount(2, $def['examples']);
    }

    public function test_example_rows_carry_marker_and_valid_values_for_utama(): void
    {
        $def = (new GenerateImportTemplateAction)->execute('utama');

        [$pns, $pppk] = $def['examples'];

        $this->assertSame(EmployeeRowMapper::EXAMPLE_ROW_MARKER, $pns['Nama Pegawai']);
        $this->assertSame('pegawai', $pns['Role']);
        $this->assertSame('PNS', $pns['Status Kepegawaian']);
        $this->assertSame('1980-01-01', $pns['Tanggal Lahir']);
        $this->assertNotNull($pns['Pangkat']);
        $this->assertNotNull($pns['Pensiun']);

        // Baris kedua mencontohkan PPPK dengan Pangkat dan Pensiun kosong (kolom opsional non-PNS).
        $this->assertSame(EmployeeRowMapper::EXAMPLE_ROW_MARKER, $pppk['Nama Pegawai']);
        $this->assertSame('PPPK', $pppk['Status Kepegawaian']);
        $this->assertNull($pppk['Pangkat']);
        $this->assertNull($pppk['Pensiun']);
        $this->assertNotSame($pns['NIP'], $pppk['NIP']);
        $this->assertNotSame($pns['Email Pegawai'], $pppk['Email Pegawai']);
    }

    public function test_every_example_row_is_flagged_as_example_by_the_mapper(): void
    {
        $def = (new GenerateImportTemplateAction)->execute('utama');
        $mapper = new EmployeeRowMapper;

        foreach ($def['examples'] as $example) {
            $this->assertTrue($mapper->isExampleRow(array_values($example)));
        }
    }

    public function test_throws_for_unknown_template_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new GenerateImportTemplateAction)->execute('tidak_dikenal');
    }
}
