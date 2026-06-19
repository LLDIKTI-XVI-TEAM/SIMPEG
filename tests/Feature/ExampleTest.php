<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_home_redirects_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    public function test_login_renders_login_page(): void
    {
        $response = $this->get('/login');
        
        $response->assertStatus(200);
        $response->assertSee('Masuk dengan SSO LLDIKTI');
    }

    public function test_authenticated_dashboard_renders(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Total Pegawai');
        $response->assertSee($user->name);
    }

    public function test_authenticated_settings_renders(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard/pengaturan');

        $response->assertOk();
        $response->assertSee('Umum & Instansi', false);
        $response->assertSee('Alur Approval Cuti');
    }

    public function test_settings_redirects(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/pengaturan');
        $response->assertRedirect('/dashboard/pengaturan');

        $responseCap = $this->actingAs($user)->get('/dashboard/Pengaturan');
        $responseCap->assertRedirect('/dashboard/pengaturan');
    }
}
