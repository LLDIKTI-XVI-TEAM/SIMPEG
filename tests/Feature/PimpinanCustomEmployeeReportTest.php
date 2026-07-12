<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class PimpinanCustomEmployeeReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_pimpinan_downloads_an_excel_report_with_selected_columns_and_filters(): void
    {
        $this->seed(RbacSeeder::class);

        $included = Employee::factory()->create([
            'nama_lengkap' => 'Ayu Lestari',
            'nip' => '198001012005011001',
            'golongan_terakhir' => 'IV/a',
            'status_aktif' => 'Aktif',
            'nik' => '7371010101010001',
            'no_kk' => '7371010101010002',
        ]);
        Employee::factory()->create([
            'nama_lengkap' => 'Bima Pratama',
            'nip' => '198102022006021002',
            'golongan_terakhir' => 'III/d',
            'status_aktif' => 'Aktif',
        ]);

        $response = $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.laporan.pegawai.custom', [
                'columns' => ['nama', 'nip', 'golongan'],
                'golongan' => 'IV',
            ]));

        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        $temporaryFile = tempnam(sys_get_temp_dir(), 'simpeg-pimpinan-custom-');
        file_put_contents($temporaryFile, $response->streamedContent());
        $spreadsheet = null;

        try {
            $spreadsheet = IOFactory::load($temporaryFile);
            $sheet = $spreadsheet->getActiveSheet();

            $this->assertSame('Laporan Pegawai Custom', $sheet->getTitle());
            $this->assertSame('Nama Pegawai', $sheet->getCell('A1')->getValue());
            $this->assertSame('NIP', $sheet->getCell('B1')->getValue());
            $this->assertSame('Golongan', $sheet->getCell('C1')->getValue());
            $this->assertSame($included->nama_lengkap, $sheet->getCell('A2')->getValue());
            $this->assertSame($included->nip, $sheet->getCell('B2')->getValue());
            $this->assertSame('IV/a', $sheet->getCell('C2')->getValue());
            $this->assertNull($sheet->getCell('A3')->getValue());
            $cells = implode(' ', array_map(
                fn (array $row): string => implode(' ', $row),
                $sheet->toArray(null, true, true, false),
            ));
            $this->assertStringNotContainsString('7371010101010001', $cells);
            $this->assertStringNotContainsString('7371010101010002', $cells);
        } finally {
            if ($spreadsheet instanceof Spreadsheet) {
                $spreadsheet->disconnectWorksheets();
            }

            @unlink($temporaryFile);
        }
    }

    public function test_pimpinan_report_page_renders_export_submit_control(): void
    {
        $this->seed(RbacSeeder::class);

        $response = $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.laporan.pegawai'))
            ->assertOk()
            ->assertSee('Terapkan Filter')
            ->assertSee('Unduh Excel (.xlsx)');

        $pattern = '/<form action="'.preg_quote(route('pimpinan.laporan.pegawai'), '/').'" method="GET" class="space-y-6" aria-describedby="employee-report-help">(.*?)<\/form>/s';

        $this->assertSame(1, preg_match($pattern, $response->getContent(), $matches));
        $this->assertStringContainsString('formaction="'.route('pimpinan.laporan.pegawai.custom').'"', $matches[0]);
        $this->assertStringNotContainsString('name="_token"', $matches[0]);
        $this->assertStringNotContainsString('_token=', $matches[0]);
    }

    public function test_pimpinan_preview_rejects_invalid_filters(): void
    {
        $this->seed(RbacSeeder::class);

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.laporan.pegawai', ['pensiun_dari' => 'bukan-tanggal']))
            ->assertRedirect()
            ->assertSessionHasErrors('pensiun_dari');
    }

    public function test_pimpinan_preview_retains_selected_export_columns(): void
    {
        $this->seed(RbacSeeder::class);

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.laporan.pegawai', ['columns' => ['nip', 'nama']]))
            ->assertOk()
            ->assertSee('id="column-nip" name="columns[]" value="nip" type="checkbox" checked', false)
            ->assertSee('id="column-nama" name="columns[]" value="nama" type="checkbox" checked', false)
            ->assertDontSee('id="column-status" name="columns[]" value="status" type="checkbox" checked', false);
    }

    public function test_non_pimpinan_cannot_download_custom_employee_report(): void
    {
        $this->seed(RbacSeeder::class);

        $this->actingAs(User::factory()->pegawai()->create())
            ->get(route('pimpinan.laporan.pegawai.custom', ['columns' => ['nip']]))
            ->assertForbidden();
    }

    public function test_pimpinan_downloads_formula_like_employee_text_as_a_literal_cell(): void
    {
        $this->seed(RbacSeeder::class);
        Employee::factory()->create([
            'nama_lengkap' => '=1+1',
        ]);

        $response = $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.laporan.pegawai.custom', ['columns' => ['nama']]));

        $temporaryFile = tempnam(sys_get_temp_dir(), 'simpeg-pimpinan-formula-');
        file_put_contents($temporaryFile, $response->streamedContent());
        $spreadsheet = null;

        try {
            $spreadsheet = IOFactory::load($temporaryFile);
            $cell = $spreadsheet->getActiveSheet()->getCell('A2');

            $this->assertSame('=1+1', $cell->getValue());
            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        } finally {
            if ($spreadsheet instanceof Spreadsheet) {
                $spreadsheet->disconnectWorksheets();
            }

            @unlink($temporaryFile);
        }
    }
}
