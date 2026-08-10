<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\EwsConfig;
use App\Models\Permission;
use App\Models\RefGolongan;
use App\Models\Role;
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
        $alert = $this->activeAlert('Pegawai Followup Ditangani', 'SATYALANCANA');

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

        $this->assertDatabaseHas('notifications', [
            'user_id' => $alert->employee_id,
            'type' => 'ews.followup.satyalancana',
            'title' => 'Tindak Lanjut EWS: Disetujui',
            'body' => 'Berkas kenaikan pangkat sudah diproses.',
            'is_read' => false,
        ]);

        // Notifikasi follow-up harus mengarah ke halaman EWS Saya, bukan daftar notifikasi.
        $followupNotification = SimpegNotification::where('type', 'ews.followup.satyalancana')->firstOrFail();
        $this->assertSame(route('ews.saya', [], false), $followupNotification->data['url']);
        $this->assertSame($alert->id, $followupNotification->data['ews_alert_id']);

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

    public function test_followup_is_forbidden_when_role_lacks_employees_update_permission(): void
    {
        // Follow-up EWS memutasi riwayat dan status pegawai; role saja tidak cukup,
        // permission employees.update harus tetap ditegakkan di backend.
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.update')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);

        $user = User::factory()->adminKepegawaian()->create();
        $alert = $this->activeAlert('Pegawai Followup Tanpa Permission');

        $this->actingAs($user)
            ->postWithCsrf(route('ews.followup.update', $alert), [
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
                'handled_note' => 'Percobaan tanpa permission.',
            ])
            ->assertForbidden();

        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_ACTIVE, $alert->refresh()->followup_status);
    }

    public function test_pangkat_approval_creates_new_history_and_resets_ews_from_configured_tmt(): void
    {

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

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.followup.kenaikan_pangkat',
            'title' => 'Tindak Lanjut EWS: Disetujui',
            'body' => 'SK pangkat baru sudah disetujui.',
            'is_read' => false,
        ]);

        app(EwsEngineService::class)->run();
        $this->assertSame(0, EwsAlert::query()
            ->where('employee_id', $employee->id)
            ->where('type', 'KENAIKAN_PANGKAT')
            ->where('followup_status', EwsAlert::FOLLOWUP_STATUS_ACTIVE)
            ->count());
    }

    public function test_kgb_approval_creates_new_history_and_resets_ews_from_configured_tmt(): void
    {

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

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.followup.kgb',
            'title' => 'Tindak Lanjut EWS: Disetujui',
            'body' => 'SK KGB baru sudah disetujui.',
            'is_read' => false,
        ]);
    }

    public function test_pension_approval_uploads_sk_sets_employee_to_pensiun_and_stops_reminders(): void
    {

        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $alert = $this->activeAlertFor($employee, 'PENSIUN', now()->subDay()->toDateString(), 90);
        $otherStage = $this->activeAlertFor($employee, 'PENSIUN', now()->subDay()->toDateString(), 180);
        foreach ([$alert, $otherStage] as $pensionAlert) {
            SimpegNotification::create([
                'user_id' => $employee->id,
                'ews_alert_id' => $pensionAlert->id,
                'type' => 'ews.pensiun',
                'title' => 'Peringatan Pensiun',
                'body' => 'Segera lengkapi berkas.',
                'data' => ['ews_alert_id' => $pensionAlert->id],
            ]);
        }

        $this->actingAs($user)
            ->from(route('ews'))
            ->postWithCsrf(route('ews.followup.update', $alert), [
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
                'handled_note' => 'SK pensiun telah diterbitkan.',
                'no_sk' => 'SK-PENSIUN-EWS-001',
                'tanggal_sk' => '2026-07-25',
                'file_sk' => UploadedFile::fake()->create('sk-pensiun.pdf', 128, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('ews'));

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'status_aktif' => 'Pensiun',
        ]);
        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pensiun',
            'nomor_dokumen' => 'SK-PENSIUN-EWS-001',
        ]);
        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_HANDLED, $alert->refresh()->followup_status);
        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_HANDLED, $otherStage->refresh()->followup_status);
        $this->assertTrue(SimpegNotification::whereIn('ews_alert_id', [$alert->id, $otherStage->id])->where('is_read', true)->exists());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.followup.pensiun',
            'title' => 'Tindak Lanjut EWS: Disetujui',
            'body' => 'SK pensiun telah diterbitkan.',
            'is_read' => false,
        ]);
    }

    public function test_handled_satyalancana_closes_sibling_alerts_and_stops_reminders(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        // Dua tahap pengingat untuk milestone yang sama, keduanya masih aktif.
        $alert = $this->activeAlertFor($employee, 'SATYALANCANA', now()->addDays(30)->toDateString(), 90);
        $otherStage = $this->activeAlertFor($employee, 'SATYALANCANA', now()->addDays(30)->toDateString(), 180);
        foreach ([$alert, $otherStage] as $stageAlert) {
            SimpegNotification::create([
                'user_id' => $employee->id,
                'ews_alert_id' => $stageAlert->id,
                'type' => 'ews.satyalancana',
                'title' => 'Peringatan Satyalancana',
                'body' => 'Segera lengkapi berkas.',
                'data' => ['ews_alert_id' => $stageAlert->id],
            ]);
        }

        $this->actingAs($user)
            ->postWithCsrf(route('ews.followup.update', $alert), [
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
                'handled_note' => 'Usulan satyalancana sudah diproses.',
            ])
            ->assertSessionHasNoErrors();

        // Target satyalancana/PPPK tidak berubah setelah ditangani, sehingga
        // notifikasi harus ikut ditutup; jika tidak, scheduler berikutnya akan
        // menghidupkan kembali pengingat dan menghapus acknowledgement.
        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_HANDLED, $alert->refresh()->followup_status);
        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_HANDLED, $otherStage->refresh()->followup_status);
        $this->assertNotNull($alert->notification_acknowledged_at);
        $this->assertSame(
            0,
            SimpegNotification::whereIn('ews_alert_id', [$alert->id, $otherStage->id])
                ->where('is_read', false)
                ->count()
        );
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.followup.satyalancana',
            'title' => 'Tindak Lanjut EWS: Disetujui',
            'body' => 'Usulan satyalancana sudah diproses.',
            'is_read' => false,
        ]);
    }

    public function test_failed_pension_followup_cleans_up_uploaded_sk_file(): void
    {

        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $alert = $this->activeAlertFor($employee, 'PENSIUN', now()->subDay()->toDateString(), 90);

        // Simulasi kegagalan transaksi setelah file SK tersimpan ke storage:
        // perubahan status pegawai dipaksa gagal sehingga seluruh transaksi rollback.
        Employee::updating(function (Employee $updating): void {
            if ($updating->isDirty('status_pegawai_id')) {
                throw new \RuntimeException('Simulasi kegagalan transaksi pensiun.');
            }
        });
        $this->withoutExceptionHandling();

        $thrown = false;

        try {
            $this->actingAs($user)
                ->postWithCsrf(route('ews.followup.update', $alert), [
                    'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
                    'handled_note' => 'SK pensiun telah diterbitkan.',
                    'no_sk' => 'SK-PENSIUN-GAGAL-001',
                    'tanggal_sk' => '2026-07-25',
                    'file_sk' => UploadedFile::fake()->create('sk-pensiun.pdf', 128, 'application/pdf'),
                ]);
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi kegagalan transaksi pensiun.', $exception->getMessage());
            $thrown = true;
        }

        $this->assertTrue($thrown, 'Kegagalan transaksi pensiun harus diteruskan.');

        // Rollback tidak boleh menyisakan orphan file SK di storage.
        $this->assertSame([], Storage::disk('public')->allFiles('sk'));
        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_ACTIVE, $alert->refresh()->followup_status);
        $this->assertDatabaseMissing('documents', ['employee_id' => $employee->id, 'jenis_dokumen' => 'sk_pensiun']);
    }

    public function test_failed_pangkat_followup_cleans_up_uploaded_sk_file(): void
    {

        $user = User::factory()->adminKepegawaian()->create();
        // Golongan awal ditetapkan eksplisit agar update snapshot ke III/b selalu
        // dirty; nilai acak dari factory bisa kebetulan sudah III/b sehingga hook
        // simulasi kegagalan tidak terpicu dan test menjadi flaky.
        $employee = Employee::factory()->create(['golongan_terakhir' => 'III/a']);
        $golongan = RefGolongan::where('kode', 'III/b')->firstOrFail();
        $alert = $this->activeAlertFor($employee, 'KENAIKAN_PANGKAT', now()->subDay()->toDateString(), 30);

        // Simulasi kegagalan transaksi setelah file SK tersimpan: sinkronisasi
        // snapshot golongan pegawai dipaksa gagal di dalam transaksi riwayat.
        Employee::updating(function (Employee $updating): void {
            if ($updating->isDirty('golongan_terakhir')) {
                throw new \RuntimeException('Simulasi kegagalan transaksi pangkat.');
            }
        });
        $this->withoutExceptionHandling();

        $thrown = false;

        try {
            $this->actingAs($user)
                ->postWithCsrf(route('ews.followup.update', $alert), [
                    'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
                    'handled_note' => 'SK kenaikan pangkat terbit.',
                    'golongan_id' => $golongan->id,
                    'tmt_pangkat' => now()->addDay()->toDateString(),
                    'no_sk' => 'SK-PANGKAT-GAGAL-001',
                    'tanggal_sk' => '2026-07-25',
                    'file_sk' => UploadedFile::fake()->create('sk-pangkat.pdf', 128, 'application/pdf'),
                ]);
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi kegagalan transaksi pangkat.', $exception->getMessage());
            $thrown = true;
        }

        $this->assertTrue($thrown, 'Kegagalan transaksi pangkat harus diteruskan.');

        // Rollback tidak boleh menyisakan orphan file SK di storage.
        $this->assertSame([], Storage::disk('public')->allFiles('sk'));
        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_ACTIVE, $alert->refresh()->followup_status);
        $this->assertDatabaseMissing('rank_histories', ['employee_id' => $employee->id, 'no_sk' => 'SK-PANGKAT-GAGAL-001']);
    }

    public function test_pension_approval_requires_sk_data(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $alert = $this->activeAlert('Pegawai Pensiun Wajib SK', 'PENSIUN');

        $this->actingAs($user)
            ->postJsonWithCsrf(route('ews.followup.update', $alert), [
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
                'handled_note' => 'Pensiun akan diproses.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['no_sk', 'tanggal_sk', 'file_sk']);

        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_ACTIVE, $alert->refresh()->followup_status);
    }

    public function test_tidak_perlu_keeps_employee_data_and_sends_note_notification(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create([
            'golongan_terakhir' => 'III/a',
            'tanggal_kenaikan_pangkat_berikutnya' => '2030-07-22',
        ]);
        $alert = $this->activeAlertFor($employee, 'KENAIKAN_PANGKAT', now()->addDays(30)->toDateString(), 30);
        SimpegNotification::create([
            'user_id' => $employee->id,
            'ews_alert_id' => $alert->id,
            'type' => 'ews.kenaikan_pangkat',
            'title' => 'Peringatan Pangkat',
            'body' => 'Segera lengkapi berkas.',
            'data' => ['ews_alert_id' => $alert->id],
        ]);

        $this->actingAs($user)
            ->from(route('ews'))
            ->postWithCsrf(route('ews.followup.update', $alert), [
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_NOT_NEEDED,
                'handled_note' => 'Usulan belum diperlukan karena data masih valid.',
            ])
            ->assertRedirect(route('ews'));

        $employee->refresh();
        $this->assertSame('III/a', $employee->golongan_terakhir);
        $this->assertSame('2030-07-22', $employee->tanggal_kenaikan_pangkat_berikutnya->toDateString());
        $this->assertSame(0, $employee->rankHistories()->count());
        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_NOT_NEEDED, $alert->refresh()->followup_status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.followup.tidak_perlu',
            'title' => 'Tindak Lanjut EWS: Tidak Perlu',
            'body' => 'Usulan belum diperlukan karena data masih valid.',
            'is_read' => false,
        ]);

        // Notifikasi follow-up harus mengarah ke halaman EWS Saya, bukan daftar notifikasi.
        $followupNotification = SimpegNotification::where('type', 'ews.followup.tidak_perlu')->firstOrFail();
        $this->assertSame(route('ews.saya', [], false), $followupNotification->data['url']);
        $this->assertSame($alert->id, $followupNotification->data['ews_alert_id']);
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
