<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
        $spreadsheet = null;

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
            if ($spreadsheet instanceof Spreadsheet) {
                $spreadsheet->disconnectWorksheets();
            }
            @unlink($temporaryFile);
        }
    }

    public function test_employees_read_tanpa_export_tetap_dilarang_export(): void
    {
        $this->seed(RbacSeeder::class);
        $pimpinan = User::factory()->pimpinan()->create();

        // employees.read saja tidak cukup — export butuh employees.export (K-RBAC-01.7).
        $this->assertTrue($pimpinan->hasPermission('employees.read'));
        $this->assertFalse($pimpinan->hasPermission('employees.export'));

        $this->actingAs($pimpinan)
            ->get(route('pegawai.export'))
            ->assertForbidden();
    }

    public function test_dengan_employees_export_boleh_export_semua_role(): void
    {
        $this->seed(RbacSeeder::class);

        foreach (['pimpinan', 'kepala_bagian', 'pegawai'] as $role) {
            Role::where('name', $role)->firstOrFail()
                ->permissions()->syncWithoutDetaching([
                    Permission::where('name', 'employees.export')->firstOrFail()->id,
                ]);
        }

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pegawai.export'))
            ->assertOk();
        $this->actingAs(User::factory()->kepalaBagian()->create())
            ->get(route('pegawai.export'))
            ->assertOk();
        $this->actingAs(User::factory()->pegawai()->create())
            ->get(route('pegawai.export'))
            ->assertOk();
    }

    public function test_tanpa_employees_read_tetap_dilarang_export(): void
    {
        $this->seed(RbacSeeder::class);
        $pimpinan = User::factory()->pimpinan()->create();
        Role::where('name', 'pimpinan')->firstOrFail()
            ->permissions()->detach(Permission::where('name', 'employees.read')->firstOrFail()->id);

        $this->actingAs($pimpinan)
            ->get(route('pegawai.export'))
            ->assertForbidden();
    }
}
