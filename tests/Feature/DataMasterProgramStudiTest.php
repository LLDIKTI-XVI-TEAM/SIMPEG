<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefProgramStudi;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DataMasterProgramStudiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_can_manage_program_studi(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)->postWithCsrf(route('data-master.program-studi.store'), [
            'nama' => 'Teknik Informatika',
        ])->assertRedirect()->assertSessionHas('success');

        $programStudi = RefProgramStudi::firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['event' => 'CREATE', 'auditable_type' => 'RefProgramStudi']);

        $this->actingAs($user)->postWithCsrf(route('data-master.program-studi.update', $programStudi), [
            'nama' => 'Informatika',
        ])->assertRedirect();
        $this->assertDatabaseHas('ref_program_studi', ['id' => $programStudi->id, 'nama' => 'Informatika']);

        $this->actingAs($user)->postWithCsrf(route('data-master.program-studi.toggle', $programStudi), [])
            ->assertRedirect();
        $this->assertFalse($programStudi->refresh()->is_active);
    }

    public function test_program_studi_used_by_employee_cannot_be_deleted(): void
    {
        $programStudi = RefProgramStudi::create(['nama' => 'Hukum']);
        Employee::factory()->create(['program_studi_id' => $programStudi->id]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.program-studi.destroy', $programStudi), [])
            ->assertSessionHasErrors('referensi');

        $this->assertDatabaseHas('ref_program_studi', ['id' => $programStudi->id]);
    }

    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
