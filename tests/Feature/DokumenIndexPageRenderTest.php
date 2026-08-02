<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DokumenIndexPageRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_dokumen_index_page_renders_without_undefined_constant_error(): void
    {
        $this->seed(RbacSeeder::class);
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)->get('/dashboard/dokumen');

        $response->assertOk();
    }
}
