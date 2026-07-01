<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefUnitKerja;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeIndexPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_employee_index_displays_photo_thumbnail_when_photo_exists(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Andi Foto',
            'foto' => 'employees/photos/andi-foto.jpg',
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->get(route('data-pegawai'));

        $response->assertOk();
        $response->assertSee('src="'.asset('storage/'.$employee->foto).'"', false);
        $response->assertSee('alt="Foto Andi Foto"', false);
        $response->assertSee('class="h-full w-full object-cover"', false);
        $response->assertSee('loading="lazy"', false);
    }

    public function test_employee_index_displays_initial_fallback_when_photo_is_empty(): void
    {
        $admin = User::factory()->superAdmin()->create();
        Employee::factory()->create([
            'nama_lengkap' => 'Budi Tanpa Foto',
            'foto' => null,
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->get(route('data-pegawai'));

        $response->assertOk();
        $response->assertSee('Budi Tanpa Foto');
        $response->assertSee('<span class="" aria-hidden="true">', false);
        $response->assertSee('B', false);
        $response->assertDontSee('alt="Foto Budi Tanpa Foto"', false);
    }

    public function test_employee_name_links_to_detail_page(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Citra Detail',
            'nip' => '198801012010012001',
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->get(route('data-pegawai'));

        $response->assertOk();
        $response->assertSee('href="'.route('pegawai.show', $employee->id).'"', false);
        $response->assertSee('Buka detail profil Citra Detail', false);
        $response->assertSee('aria-label="Buka detail profil Citra Detail"', false);
        $response->assertSee('Buka detail Citra Detail', false);
        $response->assertSee('Citra Detail');
    }

    public function test_employee_index_sorts_entire_database_before_pagination(): void
    {
        $admin = User::factory()->superAdmin()->create();

        foreach (range(1, 10) as $number) {
            Employee::factory()->create([
                'nama_lengkap' => 'Zulu '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            ]);
        }

        Employee::factory()->create([
            'nama_lengkap' => 'Alpha Global',
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->get(route('data-pegawai', [
                'sort' => 'pegawai',
                'direction' => 'asc',
            ]));

        $response->assertOk();
        $response->assertSee('Alpha Global');
        $response->assertDontSee('Zulu 10');
        $response->assertSee('sort=pegawai', false);
        $response->assertSee('direction=desc', false);
        $response->assertDontSee('function sortTable', false);
    }

    public function test_employee_index_searches_entire_database_before_pagination(): void
    {
        $admin = User::factory()->superAdmin()->create();

        foreach (range(1, 10) as $number) {
            Employee::factory()->create([
                'nama_lengkap' => 'Current Page '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
                'nip' => '1999000000000000'.$number,
            ]);
        }

        Employee::factory()->create([
            'nama_lengkap' => 'Hidden Search Match',
            'nip' => '777777777777777777',
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->get(route('data-pegawai', [
                'search' => '777777',
            ]));

        $response->assertOk();
        $response->assertSee('Hidden Search Match');
        $response->assertDontSee('Current Page 10');
    }

    public function test_employee_index_filters_by_real_unit_kerja_and_displays_tmt(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $jenisJabatan = RefJenisJabatan::create([
            'nama' => 'Jabatan Index Test',
            'maks_usia_pensiun' => 58,
        ]);
        $targetUnit = RefUnitKerja::create(['nama' => 'Bagian Target']);
        $otherUnit = RefUnitKerja::create(['nama' => 'Bagian Lain']);
        $targetEmployee = Employee::factory()->create([
            'nama_lengkap' => 'Unit Target Employee',
            'jabatan_terakhir' => 'Analis Unit Target',
        ]);
        $otherEmployee = Employee::factory()->create([
            'nama_lengkap' => 'Unit Other Employee',
            'jabatan_terakhir' => 'Analis Unit Lain',
        ]);

        PositionHistory::create([
            'employee_id' => $targetEmployee->id,
            'nama_jabatan' => 'Analis Unit Target',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'unit_kerja_id' => $targetUnit->id,
            'tmt_jabatan' => '2024-03-01',
            'no_sk' => 'SK-UNIT-001',
            'tanggal_sk' => '2024-02-15',
            'is_latest' => true,
        ]);
        PositionHistory::create([
            'employee_id' => $otherEmployee->id,
            'nama_jabatan' => 'Analis Unit Lain',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'unit_kerja_id' => $otherUnit->id,
            'tmt_jabatan' => '2024-04-01',
            'no_sk' => 'SK-UNIT-002',
            'tanggal_sk' => '2024-03-15',
            'is_latest' => true,
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->get(route('data-pegawai', [
                'unit_kerja_id' => $targetUnit->id,
            ]));

        $response->assertOk();
        $response->assertSee('Unit Target Employee');
        $response->assertSee('Bagian Target');
        $response->assertSee('01-03-2024');
        $response->assertDontSee('Unit Other Employee');
    }

    public function test_employee_index_filters_by_golongan_jenis_and_status_in_database(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $pns = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $pppk = RefJenisPegawai::firstOrCreate(['nama' => 'PPPK']);

        Employee::factory()->create([
            'nama_lengkap' => 'Filtered Database Employee',
            'golongan_terakhir' => 'III/a',
            'jenis_pegawai_id' => $pns->id,
            'status_aktif' => 'Pensiun',
        ]);
        Employee::factory()->create([
            'nama_lengkap' => 'Wrong Filter Employee',
            'golongan_terakhir' => 'IV/a',
            'jenis_pegawai_id' => $pppk->id,
            'status_aktif' => 'Aktif',
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->get(route('data-pegawai', [
                'golongan' => 'III',
                'jenis_pegawai_id' => $pns->id,
                'status_aktif' => 'Pensiun',
            ]));

        $response->assertOk();
        $response->assertSee('Filtered Database Employee');
        $response->assertDontSee('Wrong Filter Employee');
    }
}
