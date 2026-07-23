<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\EwsConfig;
use App\Models\RefGolongan;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\EwsEngineService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $alert = $this->activeAlert('Pegawai Followup Ditangani', 'PENSIUN');

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

    public function test_pangkat_approval_creates_new_history_and_resets_ews_from_configured_tmt(): void
    {
        Storage::fake('public');
        EwsConfig::setVal('pangkat_required_years', '3');
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'tanggal_kenaikan_pangkat_berikutnya' => now()->subDay()->toDateString(),
        ]);
        $golongan = RefGolongan::where('kode', 'III/b')->firstOrFail();
        $current = $this->activeAlertFor($employee, 'KENAIKAN_PANGKAT', now()->subDay()->toDateString(), 30);
        $otherStage = $this->activeAlertFor($employee, 'KENAIKAN_PANGKAT', now()->subDay()->toDateString(), 60);
        foreach ([$current, $otherStage] as $alert) {
            SimpegNotification::create([
                'user_id' => $employee->id,
                'ews_alert_id' => $alert->id,
                'type' => 'ews.kenaikan_pangkat',
                'title' => 'Peringatan Pangkat',
                'body' => 'Segera lengkapi berkas.',
                'data' => ['ews_alert_id' => $alert->id],
            ]);
        }

        $this->actingAs($user)
            ->from(route('ews'))
            ->postWithCsrf(route('ews.followup.update', $current), [
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
                'handled_note' => 'SK pangkat baru sudah disetujui.',
                'golongan_id' => $golongan->id,
                'tmt_pangkat' => '2026-07-22',
                'no_sk' => 'SK-PANGKAT-EWS-001',
                'tanggal_sk' => '2026-07-25',
                'file_sk' => UploadedFile::fake()->create('sk-pangkat-baru.pdf', 128, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('ews'));

        $this->assertDatabaseHas('rank_histories', [
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'no_sk' => 'SK-PANGKAT-EWS-001',
            'is_latest' => true,
        ]);
        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pangkat',
            'nomor_dokumen' => 'SK-PANGKAT-EWS-001',
        ]);
        $this->assertSame('2029-07-22', $employee->fresh()->tanggal_kenaikan_pangkat_berikutnya->toDateString());
        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_HANDLED, $current->refresh()->followup_status);
        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_HANDLED, $otherStage->refresh()->followup_status);
        $this->assertTrue(SimpegNotification::whereIn('ews_alert_id', [$current->id, $otherStage->id])->where('is_read', true)->exists());

        app(EwsEngineService::class)->run();
        $this->assertSame(0, EwsAlert::query()
            ->where('employee_id', $employee->id)
            ->where('type', 'KENAIKAN_PANGKAT')
            ->where('followup_status', EwsAlert::FOLLOWUP_STATUS_ACTIVE)
            ->count());
    }

    public function test_kgb_approval_creates_new_history_and_resets_ews_from_configured_tmt(): void
    {
        Storage::fake('public');
        EwsConfig::setVal('kgb_required_years', '4');
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create([
            'tanggal_kgb_berikutnya' => now()->subDay()->toDateString(),
        ]);
        $alert = $this->activeAlertFor($employee, 'KGB', now()->subDay()->toDateString(), 14);
        SimpegNotification::create([
            'user_id' => $employee->id,
            'ews_alert_id' => $alert->id,
            'type' => 'ews.kgb',
            'title' => 'Peringatan KGB',
            'body' => 'Segera lengkapi berkas.',
            'data' => ['ews_alert_id' => $alert->id],
        ]);

        $this->actingAs($user)
            ->from(route('ews'))
            ->postWithCsrf(route('ews.followup.update', $alert), [
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
                'handled_note' => 'SK KGB baru sudah disetujui.',
                'tmt_kgb' => '2026-07-22',
                'gaji_pokok' => 5200000,
                'no_sk' => 'SK-KGB-EWS-001',
                'tanggal_sk' => '2026-07-25',
                'file_sk' => UploadedFile::fake()->create('sk-kgb-baru.pdf', 128, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('ews'));

        $this->assertDatabaseHas('salary_histories', [
            'employee_id' => $employee->id,
            'no_sk' => 'SK-KGB-EWS-001',
            'is_latest' => true,
        ]);
        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_kgb',
            'nomor_dokumen' => 'SK-KGB-EWS-001',
        ]);
        $this->assertSame('2030-07-22', $employee->fresh()->tanggal_kgb_berikutnya->toDateString());
        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_HANDLED, $alert->refresh()->followup_status);
        $this->assertTrue(SimpegNotification::where('ews_alert_id', $alert->id)->firstOrFail()->is_read);
    }

    public function test_pangkat_or_kgb_approval_requires_new_history_and_sk_data(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $alert = $this->activeAlert('Pegawai EWS Pangkat Wajib SK');

        $this->actingAs($user)
            ->postJsonWithCsrf(route('ews.followup.update', $alert), [
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
                'handled_note' => 'Akan disetujui.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['golongan_id', 'tmt_pangkat', 'no_sk', 'tanggal_sk', 'file_sk']);

        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_ACTIVE, $alert->refresh()->followup_status);
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

    private function activeAlert(string $employeeName, string $type = 'KENAIKAN_PANGKAT'): EwsAlert
    {
        $employee = Employee::factory()->create(['nama_lengkap' => $employeeName]);

        return $this->activeAlertFor($employee, $type, now()->addDays(30)->toDateString(), 30);
    }

    private function activeAlertFor(Employee $employee, string $type, string $targetDate, int $intervalDays): EwsAlert
    {
        return EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => $type,
            'target_date' => $targetDate,
            'interval_days' => $intervalDays,
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
