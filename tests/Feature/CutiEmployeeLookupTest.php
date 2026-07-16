<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CutiEmployeeLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_role_pemilik_rekap_dan_konfigurasi_dapat_mencari_pegawai(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Lookup Cuti',
            'nip' => '198765432100000001',
            'status_aktif' => 'Aktif',
        ]);

        foreach (['super_admin', 'admin_kepegawaian', 'pimpinan'] as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role]))
                ->getJson(route('cuti.employee-lookup', ['q' => 'Lookup']));

            $response->assertOk()
                ->assertExactJson([
                    'data' => [[
                        'id' => $employee->id,
                        'nama_lengkap' => 'Pegawai Lookup Cuti',
                        'nip' => '198765432100000001',
                    ]],
                ]);
        }
    }

    public function test_role_di_luar_scope_rekap_ditolak(): void
    {
        foreach (['pegawai', 'kepala_bagian'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->getJson(route('cuti.employee-lookup', ['q' => 'Pegawai']))
                ->assertForbidden();
        }
    }

    public function test_query_lookup_wajib_dua_karakter_dan_maksimal_seratus_karakter(): void
    {
        $actor = User::factory()->superAdmin()->create();

        $this->actingAs($actor)
            ->getJson(route('cuti.employee-lookup', ['q' => 'P']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');

        $this->actingAs($actor)
            ->getJson(route('cuti.employee-lookup', ['q' => str_repeat('P', 101)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');

        foreach (['  ', '%%', '__'] as $query) {
            $this->actingAs($actor)
                ->getJson(route('cuti.employee-lookup', ['q' => $query]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('q');
        }
    }

    public function test_lookup_hanya_mengembalikan_lima_belas_pegawai_aktif_secara_terurut(): void
    {
        $actor = User::factory()->superAdmin()->create();

        foreach (range(16, 1) as $number) {
            Employee::factory()->create([
                'nama_lengkap' => sprintf('Target Lookup %02d', $number),
                'status_aktif' => 'Aktif',
            ]);
        }

        Employee::factory()->create([
            'nama_lengkap' => 'Target Lookup Nonaktif',
            'status_aktif' => 'Non-Aktif',
        ]);

        $response = $this->actingAs($actor)
            ->getJson(route('cuti.employee-lookup', ['q' => 'Target Lookup']));

        $response->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('data.0.nama_lengkap', 'Target Lookup 01')
            ->assertJsonMissing(['nama_lengkap' => 'Target Lookup 16'])
            ->assertJsonMissing(['nama_lengkap' => 'Target Lookup Nonaktif']);
    }
}
