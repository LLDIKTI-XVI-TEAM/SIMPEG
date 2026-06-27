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
        $def = (new GenerateImportTemplateAction())->execute('utama');

        $expected = array_merge(['No'], UploadImportBatchAction::TEMPLATE_HEADERS['utama']);
        $this->assertSame($expected, $def['headers']);
        $this->assertContains('Role', $def['headers']);
    }

    public function test_non_utama_headers_match_canonical_exactly(): void
    {
        foreach (['pelengkap', 'kepangkatan', 'jabatan', 'kgb'] as $type) {
            $def = (new GenerateImportTemplateAction())->execute($type);
            $this->assertSame(UploadImportBatchAction::TEMPLATE_HEADERS[$type], $def['headers']);
        }
    }

    public function test_example_row_carries_marker_and_valid_role_for_utama(): void
    {
        $def = (new GenerateImportTemplateAction())->execute('utama');

        $this->assertSame(EmployeeRowMapper::EXAMPLE_ROW_MARKER, $def['example']['Nama Pegawai']);
        $this->assertSame('pegawai', $def['example']['Role']);
        $this->assertSame('PNS', $def['example']['Status Kepegawaian']);
        $this->assertSame('1980-01-01', $def['example']['Tanggal Lahir']);
    }

    public function test_example_row_is_flagged_as_example_by_the_mapper(): void
    {
        $def = (new GenerateImportTemplateAction())->execute('utama');
        $mapper = new EmployeeRowMapper();

        $this->assertTrue($mapper->isExampleRow(array_values($def['example'])));
    }

    public function test_throws_for_unknown_template_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new GenerateImportTemplateAction())->execute('tidak_dikenal');
    }
}
