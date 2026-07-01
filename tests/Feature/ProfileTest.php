<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_profile_shows_current_year_leave_balance_when_available(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => now()->year,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 5,
            'sisa' => 7,
        ]);

        $this->actingAs($user);
        $response = $this->get('/dashboard/profil?tab=cuti');

        $response->assertOk();
        $response->assertSee('Informasi Saldo Cuti ('.now()->year.')', false);
        $response->assertSee('7', false);
    }

    public function test_profile_does_not_show_fake_default_leave_balance_when_missing(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->actingAs($user);
        $response = $this->get('/dashboard/profil?tab=cuti');

        $response->assertOk();
        $response->assertSee('Belum tersedia', false);
        $response->assertDontSee('12 <span class="text-sm font-normal text-muted">Hari</span>', false);
    }

    public function test_profile_password_update_rejects_wrong_current_password(): void
    {
        $user = User::factory()->pegawai()->create([
            'password' => Hash::make('password-lama'),
        ]);

        $this->actingAs($user);
        $response = $this->from('/dashboard/profil')->post('/dashboard/profil/password', [
            'current_password' => 'salah',
            'new_password' => 'password-baru',
            'new_password_confirmation' => 'password-baru',
        ]);

        $response->assertRedirect('/dashboard/profil');
        $response->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('password-lama', $user->refresh()->password));
    }

    public function test_profile_password_update_changes_password_when_current_password_matches(): void
    {
        $user = User::factory()->pegawai()->create([
            'password' => Hash::make('password-lama'),
        ]);

        $this->actingAs($user);
        $response = $this->post('/dashboard/profil/password', [
            'current_password' => 'password-lama',
            'new_password' => 'password-baru',
            'new_password_confirmation' => 'password-baru',
        ]);

        $response->assertRedirect('/dashboard/profil');
        $response->assertSessionHas('success');
        $this->assertTrue(Hash::check('password-baru', $user->refresh()->password));
    }
}
