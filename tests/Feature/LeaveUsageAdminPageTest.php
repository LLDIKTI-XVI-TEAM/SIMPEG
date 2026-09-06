<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveUsageAdminPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_workspace_menampilkan_ringkasan_baca_saja_dan_editor_cuti_luar_simpeg(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $response = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
        ]));

        $response
            ->assertOk()
            ->assertSee('Ringkasan Pemakaian Tahunan', false)
            ->assertSee('Cuti di Luar SIMPEG', false)
            ->assertSee('Ledger &amp; Rollover', false)
            ->assertDontSee('Simpan Pemakaian Tahunan', false)
            ->assertDontSee('Catat Pemakaian Tahunan', false)
            ->assertDontSee('Perbaiki Data Pemakaian', false)
            ->assertDontSee('Rekonsiliasi Pemakaian Tahunan', false);

        $this->actingAs($admin)
            ->get(route('cuti.saldo.administrasi', [
                'pegawai' => $employee->id,
                'status' => 'semua_pegawai',
                'tab' => 'manual',
            ]))
            ->assertOk()
            ->assertSee('Catat Cuti di Luar SIMPEG', false)
            ->assertSee(route('cuti.manual.store', $employee), false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertDontSee('name="jumlah_hari_kerja"', false);
    }

    public function test_editor_manual_disembunyikan_bila_permission_manual_dicabut(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $permission = Permission::query()->where('name', 'cuti.manual.manage')->sole();
        $role = Role::query()->where('name', 'admin_kepegawaian')->sole();
        $role->permissions()->detach($permission->id);

        $this->actingAs($admin)
            ->get(route('cuti.saldo.administrasi', [
                'pegawai' => $employee->id,
                'status' => 'semua_pegawai',
                'tab' => 'manual',
            ]))
            ->assertOk()
            ->assertDontSee(route('cuti.manual.store', $employee), false)
            ->assertDontSee('Simpan Cuti Eksternal', false);
    }
}
