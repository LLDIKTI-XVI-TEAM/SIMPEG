<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteUuidConstraintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_dashboard_detail_routes_reject_malformed_uuid_ids(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user);

        $this->get('/dashboard/cuti/not-a-uuid')->assertNotFound();
        $this->withSession(['_token' => 'test-token'])
            ->post('/cuti/not-a-uuid/approve', ['_token' => 'test-token'])
            ->assertNotFound();
        $this->withSession(['_token' => 'test-token'])
            ->post('/cuti/not-a-uuid/postpone', ['_token' => 'test-token'])
            ->assertNotFound();
        $this->get('/dashboard/dokumen/not-a-uuid')->assertNotFound();
        $this->get('/dashboard/dokumen/not-a-uuid/download')->assertNotFound();
        $this->get('/dashboard/audit/not-a-uuid')->assertNotFound();
    }

    public function test_legacy_dashboard_routes_are_not_captured_as_dynamic_ids(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user);

        $this->get('/dashboard/cuti/legacy')->assertRedirect(route('cuti'));
        $this->get('/dashboard/dokumen/legacy')->assertRedirect(route('dokumen'));
        $this->get('/dashboard/audit/legacy')->assertRedirect(route('audit-log'));
    }
}
