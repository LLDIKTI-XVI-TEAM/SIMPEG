<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeEditPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_employee_edit_displays_existing_photo_preview(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Dengan Foto',
            'foto' => 'employees/photos/pegawai-foto.jpg',
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->get(route('pegawai.edit', $employee->id));

        $response->assertOk();
        $response->assertSee('src="'.asset('storage/'.$employee->foto).'"', false);
        $response->assertSee('alt="Foto Pegawai Dengan Foto"', false);
    }

    public function test_employee_edit_normalizes_photo_path_with_storage_prefix(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create([
            'foto' => 'storage/employees/photos/legacy-foto.jpg',
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->get(route('pegawai.edit', $employee->id));

        $response->assertOk();
        $response->assertSee('src="'.asset('storage/employees/photos/legacy-foto.jpg').'"', false);
        $response->assertDontSee(asset('storage/storage/employees/photos/legacy-foto.jpg'), false);
    }
}
