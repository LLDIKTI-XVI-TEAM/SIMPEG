<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_expected_approval_and_mapped_users(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 9);
        $this->assertDatabaseHas('users', [
            'email' => 'merlina.rahman@example.com',
            'role' => 'admin_kepegawaian',
        ]);

        foreach ((array) config('services.keycloak.role_mapping', []) as $email => $role) {
            $this->assertDatabaseHas('users', [
                'email' => $email,
                'role' => $role,
            ]);
        }
    }

    public function test_database_seeder_creates_sso_role_mapped_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ((array) config('services.keycloak.role_mapping', []) as $email => $role) {
            $this->assertDatabaseHas('users', [
                'email' => $email,
                'role' => $role,
            ]);
            $this->assertDatabaseHas('employees', [
                'email' => $email,
                'status_aktif' => 'Aktif',
            ]);
        }
    }

    public function test_seeder_does_not_reactivate_existing_employee(): void
    {
        $email = collect(config('services.keycloak.role_mapping'))->keys()->first();

        // Pegawai existing berstatus Pensiun — seeder ulang tidak boleh
        // menghidupkannya kembali hanya agar login SSO lulus.
        $employee = Employee::factory()->create([
            'email' => $email,
            'status_aktif' => 'Pensiun',
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'status_aktif' => 'Pensiun',
        ]);
    }

    public function test_seeder_does_not_move_user_to_another_employee(): void
    {
        $email = collect(config('services.keycloak.role_mapping'))->keys()->first();

        $pegawaiAsal = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Asal',
        ]);

        // User sudah terhubung ke pegawai asal; seeder yang menemukan placeholder
        // baru untuk email yang sama tidak boleh memindahkan akun ke pegawai itu.
        User::factory()->create([
            'email' => $email,
            'employee_id' => $pegawaiAsal->id,
            'role' => 'pegawai',
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => $email,
            'employee_id' => $pegawaiAsal->id,
            'role' => 'pegawai',
        ]);
    }
}
