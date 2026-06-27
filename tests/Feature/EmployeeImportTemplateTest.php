<?php

namespace Tests\Feature;

use App\Actions\Employees\UploadImportBatchAction;
use App\Models\User;
use App\Support\EmployeeImport\EmployeeRowMapper;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        // Seed RBAC agar permission employees.import tersedia untuk middleware permission.
        $this->seed(RbacSeeder::class);
    }

    private function downloadTemplate(string $type, string $format = 'xlsx')
    {
        return $this->get("/pegawai/import/template/{$type}?format={$format}");
    }

    /**
     * Ambil baris pertama dan kedua dari konten CSV streamed, lepas BOM.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function csvRows(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = explode("\n", trim($content));

        return [
            str_getcsv($lines[0]),
            isset($lines[1]) ? str_getcsv($lines[1]) : [],
        ];
    }

    public function test_forbids_pegawai_role_from_downloading_template(): void
    {
        $this->actingAs(User::factory()->pegawai()->create());

        $this->downloadTemplate('utama')->assertForbidden();
    }

    public function test_returns_404_for_unknown_template_type(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create());

        $this->downloadTemplate('tidak_dikenal')->assertNotFound();
    }

    public function test_downloads_utama_xlsx_with_spreadsheet_content_type(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create());

        $res = $this->downloadTemplate('utama', 'xlsx');
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));
    }

    public function test_utama_csv_header_minus_no_matches_canonical_and_contains_role(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create());

        $res = $this->downloadTemplate('utama', 'csv');
        $res->assertOk();

        [$header] = $this->csvRows($res->streamedContent());

        $this->assertSame(UploadImportBatchAction::TEMPLATE_HEADERS['utama'], array_slice($header, 1));
        $this->assertContains('Role', $header);
    }

    public function test_downloaded_utama_template_passes_importer_header_validation(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create());

        [$header] = $this->csvRows($this->downloadTemplate('utama', 'csv')->streamedContent());

        $result = (new EmployeeRowMapper())->validateHeaders($header);

        // Inti perbaikan bug Role: tidak ada header wajib yang hilang.
        $this->assertSame([], $result['missing']);
    }

    public function test_all_five_template_types_download_with_canonical_headers(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create());

        foreach (['utama', 'pelengkap', 'kepangkatan', 'jabatan', 'kgb'] as $type) {
            $res = $this->downloadTemplate($type, 'csv');
            $res->assertOk();

            [$header] = $this->csvRows($res->streamedContent());
            $compare = $type === 'utama' ? array_slice($header, 1) : $header;

            $this->assertSame(UploadImportBatchAction::TEMPLATE_HEADERS[$type], $compare);
        }
    }

    public function test_example_row_in_downloaded_template_is_flagged_as_example(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create());

        [, $exampleCells] = $this->csvRows($this->downloadTemplate('utama', 'csv')->streamedContent());

        $this->assertTrue((new EmployeeRowMapper())->isExampleRow($exampleCells));
    }
}
