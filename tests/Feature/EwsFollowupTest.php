<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EwsFollowupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_satyalancana_alert_type_is_allowed_by_schema(): void
    {
        $employee = Employee::factory()->create();

        EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'SATYALANCANA',
            'target_date' => now()->addDays(180)->toDateString(),
            'interval_days' => 180,
            'is_processed' => false,
        ]);

        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'SATYALANCANA',
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
    }

    public function test_admin_kepegawaian_can_mark_alert_as_ditangani_and_it_disappears_from_active_page(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $alert = $this->activeAlert('Pegawai Followup Ditangani');

        $this->actingAs($user)
            ->from(route('ews'))
            ->postWithCsrf(route('ews.followup.update', $alert), [
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
                'handled_note' => 'Berkas kenaikan pangkat sudah diproses.',
            ])
            ->assertRedirect(route('ews'));

        $alert->refresh();

        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_HANDLED, $alert->followup_status);
        $this->assertSame($user->id, $alert->handled_by);
        $this->assertSame('Berkas kenaikan pangkat sudah diproses.', $alert->handled_note);
        $this->assertNotNull($alert->handled_at);
        $this->assertTrue($alert->is_processed);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'EwsAlert',
            'auditable_id' => $alert->id,
        ]);

        $this->actingAs($user)
            ->get(route('ews'))
            ->assertOk()
            ->assertDontSee('Pegawai Followup Ditangani');
    }

    public function test_super_admin_can_mark_alert_as_tidak_perlu_with_patch(): void
    {
        $user = User::factory()->superAdmin()->create();
        $alert = $this->activeAlert('Pegawai Followup Tidak Perlu');

        $this->actingAs($user)
            ->patchJsonWithCsrf(route('ews.followup.update', $alert), [
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_NOT_NEEDED,
                'handled_note' => 'Tidak perlu diproses karena data sudah diperbarui.',
            ])
            ->assertOk()
            ->assertJsonPath('followup_status', EwsAlert::FOLLOWUP_STATUS_NOT_NEEDED);

        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_NOT_NEEDED, $alert->refresh()->followup_status);
    }

    public function test_followup_requires_manual_status_and_note(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $alert = $this->activeAlert('Pegawai Followup Invalid');

        $this->actingAs($user)
            ->postJsonWithCsrf(route('ews.followup.update', $alert), [
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['followup_status', 'handled_note']);
    }

    public function test_pimpinan_and_pegawai_cannot_mark_alert_followup(): void
    {
        foreach (['pimpinan', 'pegawai'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $alert = $this->activeAlert('Pegawai Blocked '.$role);

            $this->actingAs($user)
                ->postWithCsrf(route('ews.followup.update', $alert), [
                    'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
                    'handled_note' => 'Percobaan tindak lanjut.',
                ])
                ->assertForbidden();

            $this->assertSame(EwsAlert::FOLLOWUP_STATUS_ACTIVE, $alert->refresh()->followup_status);
        }
    }

    private function activeAlert(string $employeeName): EwsAlert
    {
        $employee = Employee::factory()->create(['nama_lengkap' => $employeeName]);

        return EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'is_processed' => false,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, array_merge($data, ['_token' => 'test-token']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postJsonWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, array_merge($data, ['_token' => 'test-token']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function patchJsonWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->patchJson($uri, array_merge($data, ['_token' => 'test-token']));
    }
}
