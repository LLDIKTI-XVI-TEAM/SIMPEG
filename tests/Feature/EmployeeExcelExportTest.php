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
                    Permission::where('name', 'employees.read')->firstOrFail()->id,
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

    public function test_pimpinan_export_memakai_kolom_masked(): void
    {
        $this->seed(RbacSeeder::class);
        Role::where('name', 'pimpinan')->firstOrFail()->permissions()->syncWithoutDetaching([
            Permission::where('name', 'employees.export')->firstOrFail()->id,
        ]);
        $employee = Employee::factory()->create([
            'email_pribadi' => 'pegawai@example.test',
            'no_hp' => '08123456789',
        ]);

        $response = $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pegawai.export', ['ids' => [$employee->id]]));

        $rows = $this->exportedRows($response);
        $this->assertSame('Unit Kerja', $rows[0][2]);
        $this->assertSame('Status Pegawai', $rows[0][6]);
        $this->assertNotContains('pegawai@example.test', $rows[1]);
        $this->assertNotContains('08123456789', $rows[1]);
    }

    public function test_kepala_bagian_export_hanya_memuat_bawahan_langsung_dan_menolak_uuid_asing(): void
    {
        $this->seed(RbacSeeder::class);
        Role::where('name', 'kepala_bagian')->firstOrFail()->permissions()->syncWithoutDetaching([
            Permission::where('name', 'employees.read')->firstOrFail()->id,
            Permission::where('name', 'employees.export')->firstOrFail()->id,
        ]);
        $kabag = Employee::factory()->create();
        $bawahan = Employee::factory()->create([
            'nama_lengkap' => 'Bawahan Export',
            'kepala_bagian_id' => $kabag->id,
        ]);
        $lainnya = Employee::factory()->create(['nama_lengkap' => 'Lain Export']);
        $user = User::factory()->kepalaBagian()->create(['employee_id' => $kabag->id]);

        $rows = $this->exportedRows($this->actingAs($user)->get(route('pegawai.export')));
        $this->assertCount(2, $rows);
        $this->assertSame('Bawahan Export', $rows[1][1]);

        $foreignRows = $this->exportedRows($this->actingAs($user)->get(route('pegawai.export', [
            'ids' => [$bawahan->id, $lainnya->id],
        ])));
        $this->assertCount(2, $foreignRows);
        $this->assertSame('Bawahan Export', $foreignRows[1][1]);
    }

    public function test_pegawai_export_hanya_memuat_data_sendiri_dan_menolak_uuid_asing(): void
    {
        $this->seed(RbacSeeder::class);
        Role::where('name', 'pegawai')->firstOrFail()->permissions()->syncWithoutDetaching([
            Permission::where('name', 'employees.read')->firstOrFail()->id,
            Permission::where('name', 'employees.export')->firstOrFail()->id,
        ]);
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Sendiri']);
        $lainnya = Employee::factory()->create(['nama_lengkap' => 'Pegawai Lain']);
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $rows = $this->exportedRows($this->actingAs($user)->get(route('pegawai.export')));
        $this->assertCount(2, $rows);
        $this->assertSame('Pegawai Sendiri', $rows[1][1]);

        $foreignRows = $this->exportedRows($this->actingAs($user)->get(route('pegawai.export', ['ids' => [$lainnya->id]])));
        $this->assertCount(1, $foreignRows);
    }

    public function test_super_admin_export_berubah_sesuai_matrix_permission(): void
    {
        $this->seed(RbacSeeder::class);
        $superAdmin = User::factory()->superAdmin()->create();
        $permission = Permission::where('name', 'employees.export')->firstOrFail();

        $this->actingAs($superAdmin)->get(route('pegawai.export'))->assertOk();

        Role::where('name', 'super_admin')->firstOrFail()->permissions()->detach($permission->id);

        $this->actingAs($superAdmin->fresh())->get(route('pegawai.export'))->assertForbidden();
    }

    /** @return array<int, array<int, mixed>> */
    private function exportedRows($response): array
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'simpeg-export-');
        file_put_contents($temporaryFile, $response->streamedContent());
        $spreadsheet = IOFactory::load($temporaryFile);

        try {
            return $spreadsheet->getActiveSheet()->toArray();
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($temporaryFile);
        }
    }
}
