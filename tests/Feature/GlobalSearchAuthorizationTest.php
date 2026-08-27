<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pimpinan_can_use_the_global_search_endpoint(): void
    {
        $this->seed(RbacSeeder::class);

        $this->actingAs(User::factory()->pimpinan()->create())
            ->getJson(route('global.search', ['q' => 'pegawai']))
            ->assertOk();
    }

    public function test_global_search_marks_background_fetch_as_ajax_so_validation_redirect_stays_on_page(): void
    {
        $this->seed(RbacSeeder::class);
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertMatchesRegularExpression(
            "/fetch\\(url\\.toString\\(\\),\\s*\\{\\s*headers:\\s*\\{\\s*Accept:\\s*'application\\/json',\\s*'X-Requested-With':\\s*'XMLHttpRequest'/s",
            $response->getContent(),
        );
    }
}
