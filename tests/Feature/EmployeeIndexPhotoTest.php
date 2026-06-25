<?php

namespace Tests\Feature;

use App\Models\Employee;
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
        $response->assertSee('src="' . asset('storage/' . $employee->foto) . '"', false);
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
        $response->assertSee('href="' . route('pegawai.show', $employee->id) . '"', false);
        $response->assertSee('title="Buka detail profil Citra Detail"', false);
        $response->assertSee('aria-label="Buka detail profil Citra Detail"', false);
        $response->assertSee('title="Buka detail Citra Detail"', false);
        $response->assertSee('Citra Detail');
    }
}
