<?php

namespace Tests\Feature\Documents;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\RefGolongan;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class StoreBerkasSkAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_admin_kepegawaian_tanpa_izin_create_riwayat_ditolak_untuk_sk_pangkat(): void
    {
        // Buat user admin_kepegawaian dan cabut permission employee_histories.create
        $user = User::factory()->adminKepegawaian()->create();

        // Verifikasi bahwa user default punya employees.update
        $this->assertTrue($user->hasPermission('employees.update'));

        // Cabut permission employee_histories.create dari role admin_kepegawaian untuk pengujian ini
        $role = Role::where('name', 'admin_kepegawaian')->first();
        $permission = Permission::where('name', 'employee_histories.create')->first();
        $role->permissions()->detach($permission->id);

        $employee = Employee::factory()->create();
        $golongan = RefGolongan::create(['kode' => 'III/a', 'nama' => 'Penata Muda', 'urutan' => 1, 'is_active' => true]);

        $response = $this->actingAs($user)->post(
            route('api.v1.pegawai.berkas-sk.store', $employee->id),
            [
                'kategori_dokumen' => 'sk_pangkat',
                'golongan_id' => $golongan->id,
                'tmt_pangkat' => '2024-01-01',
                'no_sk' => 'SK/PANGKAT/TEST',
                'tanggal_sk' => '2024-01-01',
                'file_sk' => UploadedFile::fake()->create('sk.pdf', 100, 'application/pdf'),
            ],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(403);
    }

    public function test_admin_kepegawaian_dengan_izin_create_riwayat_diizinkan_untuk_sk_pangkat(): void
    {
        // Admin kepegawaian default memiliki employee_histories.create
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $golongan = RefGolongan::create(['kode' => 'III/a', 'nama' => 'Penata Muda', 'urutan' => 1, 'is_active' => true]);

        $response = $this->actingAs($user)->post(
            route('api.v1.pegawai.berkas-sk.store', $employee->id),
            [
                'kategori_dokumen' => 'sk_pangkat',
                'golongan_id' => $golongan->id,
                'tmt_pangkat' => '2024-01-01',
                'no_sk' => 'SK/PANGKAT/TEST',
                'tanggal_sk' => '2024-01-01',
                'file_sk' => UploadedFile::fake()->create('sk.pdf', 100, 'application/pdf'),
            ],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(201);
    }

    public function test_admin_kepegawaian_tanpa_izin_create_riwayat_tetap_bisa_replace_sk_pengangkatan(): void
    {
        // sk_pengangkatan hanya membutuhkan employees.update (bukan employee_histories.create)
        $user = User::factory()->adminKepegawaian()->create();

        $role = Role::where('name', 'admin_kepegawaian')->first();
        $permission = Permission::where('name', 'employee_histories.create')->first();
        $role->permissions()->detach($permission->id);

        $employee = Employee::factory()->create();

        $response = $this->actingAs($user)->post(
            route('api.v1.pegawai.berkas-sk.store', $employee->id),
            [
                'kategori_dokumen' => 'sk_pengangkatan',
                'jenis_pengangkatan' => 'PNS',
                'tmt_pengangkatan' => '2024-01-01',
                'no_sk' => 'SK/ANGKAT/TEST',
                'tanggal_sk' => '2024-01-01',
                'file_sk' => UploadedFile::fake()->create('sk.pdf', 100, 'application/pdf'),
            ],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(201);
    }
}
