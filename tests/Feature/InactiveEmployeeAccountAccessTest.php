<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefStatusPegawai;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InactiveEmployeeAccountAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_pegawai_nonaktif_dialihkan_ke_halaman_status_akun(): void
    {
        $nonaktif = RefStatusPegawai::query()->where('kode', 'NONAKTIF')->firstOrFail();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Nonaktif',
            'status_aktif' => 'Non-Aktif',
            'status_pegawai_id' => $nonaktif->id,
            'status_note' => 'Silakan hubungi admin.',
        ]);
        $user = User::factory()->create([
            'employee_id' => $employee->id,
            'role' => 'pegawai',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('status-akun'));

        $this->actingAs($user)
            ->get(route('status-akun'))
            ->assertOk()
            ->assertSee('Akun Tidak Dapat Digunakan', false)
            ->assertSee('nonaktif atau belum dapat diverifikasi', false)
            ->assertSee('Silakan hubungi admin.', false);
    }

    public function test_pegawai_aktif_tidak_dialihkan(): void
    {
        $aktif = RefStatusPegawai::query()->where('kode', 'AKTIF')->firstOrFail();
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'status_pegawai_id' => $aktif->id,
        ]);
        $user = User::factory()->create([
            'employee_id' => $employee->id,
            'role' => 'pegawai',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_pegawai_tugas_belajar_kelompok_aktif_khusus_tidak_dialihkan(): void
    {
        $tugasBelajar = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $employee = Employee::factory()->create([
            'status_pegawai_id' => $tugasBelajar->id,
        ]);
        $user = User::factory()->create([
            'employee_id' => $employee->id,
            'role' => 'pegawai',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();
    }

    #[DataProvider('roleAplikasiProvider')]
    public function test_user_terautentikasi_tanpa_relasi_pegawai_diblokir_dari_fitur_bisnis(string $role): void
    {
        $user = User::factory()->create([
            'employee_id' => null,
            'role' => $role,
        ]);

        $this->actingAsUnmapped($user)
            ->get('/dashboard')
            ->assertRedirect(route('status-akun'));
    }

    public function test_user_tanpa_relasi_pegawai_tetap_dapat_membuka_status_akun_dan_logout(): void
    {
        $user = User::factory()->create([
            'employee_id' => null,
            'role' => 'pegawai',
        ]);

        $this->actingAsUnmapped($user)
            ->get(route('status-akun'))
            ->assertOk()
            ->assertSee('nonaktif atau belum dapat diverifikasi', false);

        $response = $this->actingAsUnmapped($user)
            ->post(route('logout'))
            ->assertRedirect();

        $this->assertNotSame(route('status-akun'), $response->headers->get('Location'));
    }

    public function test_user_dengan_status_pegawai_tidak_terverifikasi_diblokir_fail_closed(): void
    {
        $employee = Employee::factory()->create();
        DB::table('employees')->where('id', $employee->id)->update(['status_pegawai_id' => null]);
        $employee->refresh();
        $user = User::factory()->create([
            'employee_id' => $employee->id,
            'role' => 'pegawai',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('status-akun'));
    }

    public function test_user_dengan_kelompok_status_tidak_dikenali_diblokir_fail_closed(): void
    {
        $statusInvalid = RefStatusPegawai::query()->create([
            'kode' => 'STATUS_INVALID_QA',
            'nama' => 'Status Invalid QA',
            'kelompok' => 'Kelompok Tidak Dikenali',
            'is_default' => false,
            'is_active' => true,
        ]);
        $employee = Employee::factory()->create();
        DB::table('employees')->where('id', $employee->id)->update([
            'status_pegawai_id' => $statusInvalid->id,
        ]);
        $employee->refresh();
        $user = User::factory()->create([
            'employee_id' => $employee->id,
            'role' => 'pegawai',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('status-akun'));
    }

    /** @return array<string, array{string}> */
    public static function roleAplikasiProvider(): array
    {
        return [
            'super admin' => ['super_admin'],
            'admin kepegawaian' => ['admin_kepegawaian'],
            'pimpinan' => ['pimpinan'],
            'kepala bagian' => ['kepala_bagian'],
            'pegawai' => ['pegawai'],
        ];
    }
}
