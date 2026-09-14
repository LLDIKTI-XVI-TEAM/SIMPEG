<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PimpinanFixedEmployeeReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_pimpinan_can_preview_fixed_nominatif_report(): void
    {
        $this->seed(RbacSeeder::class);
        // Kode: ExportPegawaiRequest::authorize membutuhkan employees.read + employees.export;
        // pimpinan hanya punya read via seeder, sehingga grant export eksplisit (mengikuti kode).
        Role::where('name', 'pimpinan')->firstOrFail()
            ->permissions()->syncWithoutDetaching(
                Permission::whereIn('name', ['employees.read', 'employees.export'])->pluck('id')->all()
            );
        // Kode: EnsureActiveEmployeeAccount mewajibkan employee aktif; factory user tanpa employee akan redirect.
        $actorEmployee = Employee::factory()->create();
        $pimpinanUser = User::factory()->pimpinan()->create(['employee_id' => $actorEmployee->id]);
        Employee::factory()->create([
            'nama_lengkap' => 'Budi Santoso',
            'nip' => '199001012020011001',
        ]);

        $response = $this->actingAs($pimpinanUser)->get(route('pimpinan.laporan.nominatif'));

        $response->assertOk()
            ->assertSee('Laporan Nominatif Pegawai')
            ->assertSee('Budi Santoso')
            ->assertSee('199001012020011001');
    }

    public function test_pimpinan_can_download_fixed_nominatif_excel_and_pdf(): void
    {
        $this->seed(RbacSeeder::class);
        Role::where('name', 'pimpinan')->firstOrFail()
            ->permissions()->syncWithoutDetaching(
                Permission::whereIn('name', ['employees.read', 'employees.export'])->pluck('id')->all()
            );
        $actorEmployee = Employee::factory()->create();
        $pimpinanUser = User::factory()->pimpinan()->create(['employee_id' => $actorEmployee->id]);
        Employee::factory()->create([
            'nama_lengkap' => 'Siti Nurhaliza',
            'nip' => '199202022020022002',
        ]);

        $excelResponse = $this->actingAs($pimpinanUser)->get(route('pimpinan.laporan.nominatif.excel'));
        $excelResponse->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $pdfResponse = $this->actingAs($pimpinanUser)->get(route('pimpinan.laporan.nominatif.pdf'));
        $pdfResponse->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }
}
