<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KepalaBagianRouteGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_non_kepala_bagian_roles_cannot_open_kepala_bagian_urls_directly(): void
    {
        $users = [
            User::factory()->superAdmin()->create(),
            User::factory()->adminKepegawaian()->create(),
            User::factory()->pimpinan()->create(),
            User::factory()->pegawai()->create(),
        ];
        $routes = [
            route('kepala-bagian.dashboard'),
            route('kepala-bagian.bawahan.index'),
            route('kepala-bagian.ews.index'),
        ];

        foreach ($users as $user) {
            foreach ($routes as $route) {
                $this->actingAs($user)->get($route)->assertForbidden();
            }
        }
    }
}
