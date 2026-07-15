<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pimpinan_cannot_use_the_admin_global_search_endpoint(): void
    {
        $this->seed(RbacSeeder::class);

        $this->actingAs(User::factory()->pimpinan()->create())
            ->getJson(route('global.search', ['q' => 'pegawai']))
            ->assertForbidden();
    }
}
