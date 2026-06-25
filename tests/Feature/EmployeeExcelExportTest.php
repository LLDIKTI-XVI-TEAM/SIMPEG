<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class EmployeeExcelExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_export_uses_database_data_and_expected_layout(): void
    {
        $this->seed(RbacSeeder::class);

        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Abd Rahim Har, S.E.',
            'nip' => '197906252008011009',
            'no_hp' => '08114456399',
            'tanggal_lahir' => '1979-06-25',
            'tanggal_pensiun' => '2037-07-01',
        ]);

        $response = $this->actingAs($user)->get(route('pegawai.export', [
            'nips' => [$employee->nip],
        ]));

        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        $temporaryFile = tempnam(sys_get_temp_dir(), 'simpeg-export-');
        file_put_contents($temporaryFile, $response->streamedContent());

        try {
            $spreadsheet = IOFactory::load($temporaryFile);
            $sheet = $spreadsheet->getActiveSheet();

            $this->assertSame('Data Pegawai', $sheet->getTitle());
            $this->assertSame('Nama Pegawai', $sheet->getCell('B1')->getValue());
            $this->assertSame('Person Formula', $sheet->getCell('M1')->getValue());
            $this->assertSame('Abd Rahim Har, S.E.', $sheet->getCell('B2')->getValue());
            $this->assertSame('197906252008011009', $sheet->getCell('G2')->getValue());
            $this->assertSame('1F5A83', $sheet->getStyle('A1')->getFill()->getStartColor()->getRGB());
            $this->assertSame('D9F2FB', $sheet->getStyle('A2')->getFill()->getStartColor()->getRGB());
            $this->assertSame('mmmm d, yyyy', $sheet->getStyle('P2')->getNumberFormat()->getFormatCode());
            $this->assertSame('A2', $sheet->getFreezePane());
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($temporaryFile);
        }
    }
}
