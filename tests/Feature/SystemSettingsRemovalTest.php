<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SystemSettingsRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_placeholder_settings_routes_are_not_registered_or_reachable(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertFalse(Route::has('pengaturan'));
        $this->assertFalse(Route::has('settings.update'));
        $this->assertFalse(Route::has('settings.index'));

        $this->actingAs($superAdmin)->withSession(['active_role' => 'super_admin']);

        foreach ([
            '/dashboard/pengaturan',
            '/dashboard/pengaturan/legacy',
            '/dashboard/Pengaturan',
            '/pengaturan',
        ] as $uri) {
            $this->get($uri)->assertNotFound();
        }

        $this->post('/dashboard/pengaturan')->assertNotFound();
    }

    public function test_super_admin_profile_only_advertises_available_administration_surfaces(): void
    {
        $employee = Employee::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create(['employee_id' => $employee->id]);

        $this->actingAs($superAdmin)
            ->withSession(['active_role' => 'super_admin'])
            ->get(route('profil'))
            ->assertOk()
            ->assertDontSee('Kelola pengguna, data master, audit, dan data backup sistem SIMPEG secara terpusat.')
            ->assertSee('Kelola pengguna, data master, dan audit sistem SIMPEG secara terpusat.');
    }
}
