<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeShowPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_employee_show_displays_existing_profile_photo(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Detail Dengan Foto',
            'foto' => 'employees/photos/detail-foto.jpg',
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->get(route('pegawai.show', $employee->id));

        $response->assertOk();
        $response->assertSee('src="'.asset('storage/'.$employee->foto).'"', false);
        $response->assertSee('alt="Foto Detail Dengan Foto"', false);
    }

    public function test_employee_show_displays_initial_when_photo_is_missing(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Tanpa Foto Detail',
            'foto' => null,
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->get(route('pegawai.show', $employee->id));

        $response->assertOk();
        $response->assertSee('T', false);
        $response->assertDontSee('alt="Foto Tanpa Foto Detail"', false);
    }
}
