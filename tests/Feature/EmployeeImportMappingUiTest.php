<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeImportMappingUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_admin_kepegawaian_can_access_import_data_page_with_complete_mapping_ui(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($admin)->get('/pegawai/import-data');
        $componentScript = file_get_contents(resource_path('js/pages/employee-import.js'));

        $response->assertOk();
        $this->assertNotFalse($componentScript);
        $response->assertSee('Import Data Pegawai');
        $response->assertSee('Berisi data pegawai seperti NIP, NIK, No. KK, golongan, dan jabatan. Role tidak diimpor; kelola Role melalui menu Kelola Akses User.');
        $response->assertDontSee('Berisi kolom NIP, NIK, No KK, Golongan, Jabatan, Role, dll.');
        $response->assertSee('Pemetaan Kolom');
        $response->assertSee('Kolom tidak dikenal ditemukan');
        $response->assertSee('Kolom SIMPEG sengaja tidak dipakai');
        $response->assertSee('Field wajib belum dipetakan');
        $this->assertStringContainsString('simpegTargetFields:', $componentScript);
        $this->assertStringContainsString('await this.persistMapping();', $componentScript);
        $response->assertSee('aria-describedby="mapping-readiness-message"', false);
        $this->assertStringContainsString('isLockedIgnoredHeader(header)', $componentScript);
        $this->assertStringContainsString('isCanonicalSourceHeader(header)', $componentScript);
        $this->assertStringContainsString('replace(/\\s+/g, \' \')', $componentScript);
        $response->assertSee('intentionallySkippedSourceHeaders', false);
        $response->assertSee('value="tidak_dipakai"', false);
        $this->assertStringContainsString("'Nama Lengkap (Person)': 'Person'", $componentScript);
        $this->assertStringContainsString('errorSourceHeaders,', $componentScript);
        $response->assertSee('item.errorSourceHeaders?.includes(header)', false);
        $response->assertSee("'Baris validasi ' + item.row + ', ' + header", false);
        $response->assertSee('dusk="mapping-required-warning"', false);
        $this->assertStringContainsString('hasDuplicateMapping', $componentScript);
        $response->assertSee('Selesaikan konflik dan field wajib pada panel pemetaan.', false);
        $response->assertSee('dusk="mapping-continue"', false);
    }

    public function test_super_admin_import_page_keeps_alpine_component_out_of_visible_text(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->get('/pegawai/import-data');

        $response->assertOk();

        $previousErrorHandling = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;
            $document->loadHTML($response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorHandling);
        }

        $xpath = new DOMXPath($document);
        $component = $xpath->query('//*[@x-data="employeeImport"]')?->item(0);
        $componentScript = $xpath->query('//script[contains(@src, "employee-import")]')?->item(0);

        $this->assertInstanceOf(DOMElement::class, $component);
        $this->assertInstanceOf(DOMElement::class, $componentScript);
        $this->assertStringNotContainsString('Number(row.row) === Number(r.row))', $document->textContent);
    }
}
