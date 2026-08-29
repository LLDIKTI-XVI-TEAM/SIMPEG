<?php

namespace Tests\Feature;

use App\Actions\Employees\RestoreEmployeeAction;
use App\Http\Requests\Employee\DeactivateEmployeeRequest;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeStatusTransition;
use App\Models\EwsAlert;
use App\Models\EwsConfig;
use App\Models\Permission;
use App\Models\RefGolongan;
use App\Models\RefStatusPegawai;
use App\Models\Role;
use App\Models\SimpegNotification;
use App\Models\StorageRecoveryTask;
use App\Models\User;
use App\Services\Employees\EmployeeStatusTransitionService;
use App\Services\EwsEngineService;
use App\Services\NotificationService;
use App\Services\StorageRecoveryService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
            'title' => 'Tindak Lanjut EWS: Ditangani',
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

    public function test_pension_followup_is_forbidden_without_employees_deactivate_permission(): void
    {
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.deactivate')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);

        $user = User::factory()->adminKepegawaian()->create();
        $alert = $this->activeAlert('Pegawai Pensiun Tanpa Permission', 'PENSIUN');

        $this->actingAs($user)
            ->postWithCsrf(route('ews.followup.update', $alert), [
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
                'handled_note' => 'Percobaan pensiun tanpa permission deactivate.',
                'no_sk' => 'SK-PENSIUN-TANPA-IZIN',
                'tanggal_sk' => '2026-08-27',
                'file_sk' => UploadedFile::fake()->create('sk-pensiun.pdf', 128, 'application/pdf'),
            ])
            ->assertForbidden();

        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_ACTIVE, $alert->refresh()->followup_status);
        $this->assertTrue($alert->employee->refresh()->isActive());
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
            'title' => 'Tindak Lanjut EWS: Ditangani',
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
            'title' => 'Tindak Lanjut EWS: Ditangani',
            'body' => 'SK KGB baru sudah disetujui.',
            'is_read' => false,
        ]);
    }

    public function test_pension_approval_uploads_sk_sets_employee_to_pensiun_and_stops_reminders(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake('public');
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
            'status_note' => DeactivateEmployeeRequest::DEFAULT_NOTE,
        ]);
        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pensiun',
            'nomor_dokumen' => 'SK-PENSIUN-EWS-001',
        ]);
        $history = EmployeeStatusHistory::query()
            ->where('employee_id', $employee->id)
            ->where('status_nama', 'Pensiun')
            ->firstOrFail();
        $document = Document::query()
            ->where('employee_id', $employee->id)
            ->where('jenis_dokumen', 'sk_pensiun')
            ->firstOrFail();
        $this->assertSame($document->file_path, $history->file_sk);
        $this->assertSame($document->file_path, $employee->fresh()->status_berkas_path);

        $this->actingAs($user)
            ->get(route('pegawai.history-attachments.download', [
                'employee' => $employee,
                'type' => 'status',
                'history' => $history,
            ]))
            ->assertOk()
            ->assertDownload();
        $this->actingAs($user)
            ->get(route('pegawai.history-attachments.download', [
                'employee' => $employee,
                'type' => 'status-snapshot',
                'history' => $employee,
            ]))
            ->assertOk()
            ->assertDownload();
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
            'title' => 'Tindak Lanjut EWS: Ditangani',
            'body' => 'SK pensiun telah diterbitkan.',
            'is_read' => false,
        ]);
        $this->assertSame(1, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'status_pegawai.dinonaktifkan')
            ->count());
        $this->assertSame(1, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'ews.followup.pensiun')
            ->count());

        $employeeAudit = AuditLog::query()
            ->where('auditable_type', 'Employee')
            ->where('auditable_id', $employee->id)
            ->sole();
        $serializedAudit = json_encode([$employeeAudit->old_values, $employeeAudit->new_values], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString((string) $employee->nip, $serializedAudit);
        $this->assertStringNotContainsString((string) $document->file_path, $serializedAudit);
    }

    public function test_future_pension_approval_schedules_one_private_sk_without_mutating_lifecycle_until_due(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $activeStatusId = $employee->status_pegawai_id;
        $effectiveDate = now('Asia/Makassar')->addDays(30)->toDateString();
        $selected = $this->activeAlertFor($employee, 'PENSIUN', $effectiveDate, 90);
        $sibling = $this->activeAlertFor($employee, 'PENSIUN', $effectiveDate, 180);

        $this->actingAs($user)->postJsonWithCsrf(route('ews.followup.update', $selected), [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'SK pensiun future telah diterbitkan.',
            'no_sk' => 'SK-PENSIUN-FUTURE-001',
            'tanggal_sk' => $effectiveDate,
            'file_sk' => UploadedFile::fake()->createWithContent('sk-pensiun-future.pdf', 'SK pensiun future'),
        ])->assertOk();

        $transition = EmployeeStatusTransition::query()->sole();
        $document = Document::query()->sole();
        $employee->refresh();

        $this->assertFalse($transition->is_applied);
        $this->assertSame(EmployeeStatusTransition::KIND_EWS_RETIREMENT, $transition->kind);
        $this->assertSame($selected->id, $transition->source_ews_alert_id);
        $this->assertSame($document->id, $transition->document_id);
        $this->assertSame('employees.deactivate', $transition->authorization_permission);
        $this->assertSame(EmployeeStatusTransition::KIND_DEACTIVATE, $transition->authorization_action);
        $this->assertSame($activeStatusId, $employee->status_pegawai_id);
        $this->assertTrue($employee->isActive());
        $this->assertNull($employee->status_berkas_path);
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertSame(0, AuditLog::query()
            ->where('auditable_type', 'Employee')
            ->where('auditable_id', $employee->id)
            ->count());
        $this->assertSame(0, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'status_pegawai.dinonaktifkan')
            ->count());
        $this->assertSame('sk_pensiun', $document->jenis_dokumen);
        $this->assertSame('SK-PENSIUN-FUTURE-001', $document->nomor_dokumen);
        Storage::disk(Document::STORAGE_DISK)->assertExists($document->file_path);
        $this->assertCount(1, Storage::disk(Document::STORAGE_DISK)->allFiles());
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'operation' => StorageRecoveryTask::OPERATION_CREATION_TARGET,
            'status' => StorageRecoveryTask::STATUS_ADOPTED,
            'category' => StorageRecoveryService::CATEGORY_EMPLOYEE_STATUS_DOCUMENT,
            'disk' => Document::STORAGE_DISK,
            'path' => $document->file_path,
            'owner_id' => $employee->id,
        ]);
        $this->assertNotNull($selected->refresh()->followup_notified_at);
        $this->assertNull($selected->lifecycle_notified_at);
        $this->assertNotNull($sibling->refresh()->followup_notified_at);
        $this->assertNull($sibling->lifecycle_notified_at);

        $service = app(EmployeeStatusTransitionService::class);
        $this->assertSame(0, $service->applyDue(now('Asia/Makassar')->toDateString()));
        $this->assertTrue($employee->refresh()->isActive());

        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        $permission = Permission::query()->where('name', 'employees.deactivate')->firstOrFail();
        $role->permissions()->detach($permission->id);

        $this->assertSame(1, $service->applyDue($effectiveDate));

        $employee->refresh();
        $history = EmployeeStatusHistory::query()->where('employee_id', $employee->id)->sole();
        $this->assertFalse($employee->isActive());
        $this->assertSame('Pensiun', $employee->status_aktif);
        $this->assertSame($document->file_path, $employee->status_berkas_path);
        $this->assertSame($document->file_path, $history->file_sk);
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_type', 'Employee')
            ->where('auditable_id', $employee->id)
            ->count());
        $this->assertSame(1, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'status_pegawai.dinonaktifkan')
            ->count());
        $this->assertNotNull($selected->refresh()->lifecycle_notified_at);
        $this->assertNotNull($sibling->refresh()->lifecycle_notified_at);

        $this->assertSame(0, $service->applyDue($effectiveDate));
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('employee_status_histories', 1);
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_type', 'Employee')
            ->where('auditable_id', $employee->id)
            ->count());
        $this->assertSame(1, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'status_pegawai.dinonaktifkan')
            ->count());
        $this->assertCount(1, Storage::disk(Document::STORAGE_DISK)->allFiles());
    }

    public function test_ews_engine_suppresses_only_pension_alert_for_employee_with_pending_retirement(): void
    {
        $this->travelTo(now()->startOfSecond());
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->superAdmin()->create();
        $effectiveDate = now('Asia/Makassar')->addDays(180)->toDateString();
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => $effectiveDate,
            'tanggal_kgb_berikutnya' => now('Asia/Makassar')->addDays(150)->toDateString(),
        ]);
        $selected = $this->activeAlertFor($employee, 'PENSIUN', $effectiveDate, 180);
        $this->activeAlertFor($employee, 'PENSIUN', $effectiveDate, 365);

        $this->actingAs($user)->postJsonWithCsrf(route('ews.followup.update', $selected), [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'Pensiun future sudah disetujui.',
            'no_sk' => 'SK-PENSIUN-ENGINE-SUPPRESS',
            'tanggal_sk' => $effectiveDate,
            'file_sk' => UploadedFile::fake()->createWithContent('sk-pensiun-engine.pdf', 'SK pensiun engine'),
        ])->assertOk();

        $employeeWithoutTransition = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => $effectiveDate,
        ]);

        $this->travel(90)->days();
        app(EwsEngineService::class)->run();

        $this->assertSame(0, EwsAlert::query()
            ->where('employee_id', $employee->id)
            ->where('type', 'PENSIUN')
            ->where('followup_status', EwsAlert::FOLLOWUP_STATUS_ACTIVE)
            ->count());
        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'KGB',
            'interval_days' => 60,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employeeWithoutTransition->id,
            'type' => 'PENSIUN',
            'interval_days' => 90,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
    }

    public function test_due_future_pension_retries_only_missing_lifecycle_intent_after_notification_failure(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $effectiveDate = now('Asia/Makassar')->addDays(30)->toDateString();
        $selected = $this->activeAlertFor($employee, 'PENSIUN', $effectiveDate, 90);
        $sibling = $this->activeAlertFor($employee, 'PENSIUN', $effectiveDate, 180);

        $this->actingAs($user)->postJsonWithCsrf(route('ews.followup.update', $selected), [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'SK pensiun future siap diterapkan.',
            'no_sk' => 'SK-PENSIUN-FUTURE-RETRY',
            'tanggal_sk' => $effectiveDate,
            'file_sk' => UploadedFile::fake()->createWithContent('sk-pensiun-future.pdf', 'SK pensiun retry'),
        ])->assertOk();

        $realNotifications = app(NotificationService::class);
        $flakyNotifications = new class($realNotifications) extends NotificationService
        {
            public int $calls = 0;

            public bool $shouldFail = true;

            public function __construct(private readonly NotificationService $inner) {}

            public function createForEmployee(Employee $employee, string $type, string $title, string $body, ?array $data = null): ?SimpegNotification
            {
                $this->calls++;
                if ($this->shouldFail) {
                    throw new \RuntimeException('Paksa gagal intent lifecycle scheduler.');
                }

                return $this->inner->createForEmployee($employee, $type, $title, $body, $data);
            }
        };
        $this->app->instance(NotificationService::class, $flakyNotifications);
        $service = app(EmployeeStatusTransitionService::class);

        $this->assertSame(1, $service->applyDue($effectiveDate));
        $this->assertFalse($employee->refresh()->isActive());
        $this->assertTrue(EmployeeStatusTransition::query()->sole()->is_applied);
        $this->assertNull($selected->refresh()->lifecycle_notified_at);
        $this->assertNull($sibling->refresh()->lifecycle_notified_at);
        $this->assertSame(0, SimpegNotification::query()->where('type', 'status_pegawai.dinonaktifkan')->count());
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('employee_status_histories', 1);
        $this->assertSame(1, AuditLog::query()->where('auditable_type', 'Employee')->count());
        $this->assertCount(1, Storage::disk(Document::STORAGE_DISK)->allFiles());

        $flakyNotifications->shouldFail = false;

        $this->assertSame(0, $service->applyDue($effectiveDate));
        $this->assertNotNull($selected->refresh()->lifecycle_notified_at);
        $this->assertNotNull($sibling->refresh()->lifecycle_notified_at);
        $this->assertSame(1, SimpegNotification::query()->where('type', 'status_pegawai.dinonaktifkan')->count());
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('employee_status_histories', 1);
        $this->assertSame(1, AuditLog::query()->where('auditable_type', 'Employee')->count());
        $this->assertCount(1, Storage::disk(Document::STORAGE_DISK)->allFiles());

        $this->assertSame(0, $service->applyDue($effectiveDate));
        $this->assertSame(1, SimpegNotification::query()->where('type', 'status_pegawai.dinonaktifkan')->count());
    }

    public function test_lifecycle_superseded_marker_uses_explicit_group_when_two_groups_share_handled_second(): void
    {
        $this->travelTo(now()->startOfSecond());
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $firstDate = now('Asia/Makassar')->addDays(30)->toDateString();
        $secondDate = now('Asia/Makassar')->addDays(60)->toDateString();
        $firstSource = $this->activeAlertFor($employee, 'PENSIUN', $firstDate, 90);
        $firstSibling = $this->activeAlertFor($employee, 'PENSIUN', $firstDate, 180);

        $this->actingAs($user)->postJsonWithCsrf(route('ews.followup.update', $firstSource), [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'Grup pensiun pertama.',
            'no_sk' => 'SK-PENSIUN-GRUP-001',
            'tanggal_sk' => $firstDate,
            'file_sk' => UploadedFile::fake()->createWithContent('sk-pensiun-grup-1.pdf', 'SK pensiun grup 1'),
        ])->assertOk();

        $secondSource = $this->activeAlertFor($employee, 'PENSIUN', $secondDate, 90);
        $secondSibling = $this->activeAlertFor($employee, 'PENSIUN', $secondDate, 180);
        $this->postJsonWithCsrf(route('ews.followup.update', $secondSource), [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'Grup pensiun kedua.',
            'no_sk' => 'SK-PENSIUN-GRUP-002',
            'tanggal_sk' => $secondDate,
            'file_sk' => UploadedFile::fake()->createWithContent('sk-pensiun-grup-2.pdf', 'SK pensiun grup 2'),
        ])->assertOk();

        $firstTransition = EmployeeStatusTransition::query()
            ->where('source_ews_alert_id', $firstSource->id)
            ->sole();
        $firstTransition->forceFill([
            'is_applied' => true,
            'applied_at' => now(),
        ])->saveOrFail();

        $this->assertSame(0, app(EmployeeStatusTransitionService::class)->applyDue($firstDate));
        $this->assertNull($firstSource->refresh()->lifecycle_notified_at);
        $this->assertNull($firstSibling->refresh()->lifecycle_notified_at);
        $this->assertNotNull($firstSource->lifecycle_notification_superseded_at);
        $this->assertNotNull($firstSibling->lifecycle_notification_superseded_at);
        $this->assertNull($secondSource->refresh()->lifecycle_notified_at);
        $this->assertNull($secondSibling->refresh()->lifecycle_notified_at);
        $this->assertNull($secondSource->lifecycle_notification_superseded_at);
        $this->assertNull($secondSibling->lifecycle_notification_superseded_at);
        $this->assertSame($firstSource->id, $firstSource->followup_group_id);
        $this->assertSame($firstSource->id, $firstSibling->followup_group_id);
        $this->assertSame($secondSource->id, $secondSource->followup_group_id);
        $this->assertSame($secondSource->id, $secondSibling->followup_group_id);
        $this->assertNotSame($firstSource->followup_group_id, $secondSource->followup_group_id);
    }

    public function test_pension_followup_rolls_back_when_lifecycle_audit_fails(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake('public');
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $alert = $this->activeAlertFor($employee, 'PENSIUN', now()->subDay()->toDateString(), 90);

        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->auditable_type === 'Employee') {
                throw new \RuntimeException('Paksa gagal audit lifecycle EWS.');
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->postWithCsrf(route('ews.followup.update', $alert), [
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
                'handled_note' => 'SK pensiun telah diterbitkan.',
                'no_sk' => 'SK-PENSIUN-AUDIT-GAGAL',
                'tanggal_sk' => '2026-08-27',
                'file_sk' => UploadedFile::fake()->create('sk-pensiun.pdf', 128, 'application/pdf'),
            ]);
            $this->fail('Kegagalan audit lifecycle wajib diteruskan.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Paksa gagal audit lifecycle EWS.', $exception->getMessage());
        }

        $this->assertTrue($employee->refresh()->isActive());
        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_ACTIVE, $alert->refresh()->followup_status);
        $this->assertDatabaseMissing('employee_status_histories', ['employee_id' => $employee->id]);
        $this->assertDatabaseMissing('documents', ['employee_id' => $employee->id]);
        $this->assertSame([], Storage::disk(Document::STORAGE_DISK)->allFiles('sk'));
    }

    public function test_pension_followup_double_submit_does_not_duplicate_lifecycle_side_effects(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake('public');
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $alert = $this->activeAlertFor($employee, 'PENSIUN', now()->subDay()->toDateString(), 90);
        $payload = [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'SK pensiun telah diterbitkan.',
            'no_sk' => 'SK-PENSIUN-IDEMPOTEN',
            'tanggal_sk' => '2026-08-27',
            'file_sk' => UploadedFile::fake()->create('sk-pensiun.pdf', 128, 'application/pdf'),
        ];

        $this->actingAs($user)->postJsonWithCsrf(route('ews.followup.update', $alert), $payload)->assertOk();
        $payload['file_sk'] = UploadedFile::fake()->create('sk-pensiun-retry.pdf', 128, 'application/pdf');
        $this->postJsonWithCsrf(route('ews.followup.update', $alert), $payload)
            ->assertUnprocessable();

        $this->assertSame(1, EmployeeStatusHistory::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_type', 'Employee')
            ->where('auditable_id', $employee->id)
            ->count());
        $this->assertSame(1, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'status_pegawai.dinonaktifkan')
            ->count());
        $this->assertSame(1, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'ews.followup.pensiun')
            ->count());
    }

    public function test_closed_pension_sibling_cannot_recover_selected_alert_notification_intents(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $selected = $this->activeAlertFor($employee, 'PENSIUN', now()->subDay()->toDateString(), 90);
        $sibling = $this->activeAlertFor($employee, 'PENSIUN', now()->subDay()->toDateString(), 180);
        $payload = [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'SK pensiun telah diterbitkan.',
            'no_sk' => 'SK-PENSIUN-OWNER',
            'tanggal_sk' => '2026-08-28',
            'file_sk' => UploadedFile::fake()->create('sk-pensiun.pdf', 128, 'application/pdf'),
        ];

        $this->actingAs($user)->postJsonWithCsrf(route('ews.followup.update', $selected), $payload)->assertOk();

        $this->assertNotNull($selected->refresh()->lifecycle_notified_at);
        $this->assertNotNull($selected->followup_notified_at);
        $this->assertNotNull($sibling->refresh()->lifecycle_notified_at);
        $this->assertNotNull($sibling->followup_notified_at);

        $payload['file_sk'] = UploadedFile::fake()->create('sk-pensiun-sibling.pdf', 128, 'application/pdf');
        $this->postJsonWithCsrf(route('ews.followup.update', $sibling), $payload)->assertUnprocessable();

        $this->assertSame(1, EmployeeStatusHistory::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(1, Document::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(1, SimpegNotification::query()->where('type', 'status_pegawai.dinonaktifkan')->count());
        $this->assertSame(1, SimpegNotification::query()->where('type', 'ews.followup.pensiun')->count());
        $this->assertCount(1, Storage::disk(Document::STORAGE_DISK)->allFiles());
    }

    public function test_pension_followup_rejects_stale_inactive_employee_before_document_side_effect(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->superAdmin()->create();
        $mutasi = RefStatusPegawai::query()->where('kode', 'MUTASI')->firstOrFail();
        $employee = Employee::factory()->create([
            'status_pegawai_id' => $mutasi->id,
            'status_aktif' => $mutasi->nama,
            'status_note' => 'Status nonaktif lain tidak boleh ditimpa.',
        ]);
        $alert = $this->activeAlertFor($employee, 'PENSIUN', now()->subDay()->toDateString(), 90);

        $this->actingAs($user)->postJsonWithCsrf(route('ews.followup.update', $alert), [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'Alert stale.',
            'no_sk' => 'SK-PENSIUN-STALE',
            'tanggal_sk' => '2026-08-28',
            'file_sk' => UploadedFile::fake()->create('sk-pensiun.pdf', 128, 'application/pdf'),
        ])->assertUnprocessable();

        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_ACTIVE, $alert->refresh()->followup_status);
        $this->assertSame($mutasi->id, $employee->refresh()->status_pegawai_id);
        $this->assertSame('Status nonaktif lain tidak boleh ditimpa.', $employee->status_note);
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame([], Storage::disk(Document::STORAGE_DISK)->allFiles());
    }

    public function test_pension_same_status_different_date_is_rejected_without_side_effects(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->superAdmin()->create();
        $pensiun = RefStatusPegawai::query()->where('kode', 'PENSIUN')->firstOrFail();
        $employee = Employee::factory()->create([
            'status_pegawai_id' => $pensiun->id,
            'status_aktif' => $pensiun->nama,
            'status_tanggal' => '2026-08-01',
            'status_keterangan' => 'Pensiun sudah berlaku.',
            'status_note' => 'Catatan lama.',
        ]);
        $alert = $this->activeAlertFor($employee, 'PENSIUN', now()->subDay()->toDateString(), 90);

        $this->actingAs($user)->postJsonWithCsrf(route('ews.followup.update', $alert), [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'Retry tanggal berbeda.',
            'no_sk' => 'SK-PENSIUN-NOOP',
            'tanggal_sk' => '2026-08-28',
            'file_sk' => UploadedFile::fake()->create('sk-pensiun.pdf', 128, 'application/pdf'),
        ])->assertUnprocessable();

        $employee->refresh();
        $this->assertSame('2026-08-01', $employee->status_tanggal?->toDateString());
        $this->assertSame('Pensiun sudah berlaku.', $employee->status_keterangan);
        $this->assertSame('Catatan lama.', $employee->status_note);
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame([], Storage::disk(Document::STORAGE_DISK)->allFiles());
    }

    public function test_partial_notification_failure_can_recover_missing_intent_exactly_once(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $alert = $this->activeAlertFor($employee, 'PENSIUN', now()->subDay()->toDateString(), 90);
        $sibling = $this->activeAlertFor($employee, 'PENSIUN', now()->subDay()->toDateString(), 180);
        $realNotifications = app(NotificationService::class);
        $flakyNotifications = new class($realNotifications) extends NotificationService
        {
            public int $calls = 0;

            public ?int $failOnCall = 2;

            public function __construct(private readonly NotificationService $inner) {}

            public function createForEmployee(Employee $employee, string $type, string $title, string $body, ?array $data = null): ?SimpegNotification
            {
                $this->calls++;
                if ($this->calls === $this->failOnCall) {
                    throw new \RuntimeException('Paksa gagal di antara dua intent notifikasi.');
                }

                return $this->inner->createForEmployee($employee, $type, $title, $body, $data);
            }
        };
        $this->app->instance(NotificationService::class, $flakyNotifications);
        $payload = [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'SK pensiun telah diterbitkan.',
            'no_sk' => 'SK-PENSIUN-RECOVERY',
            'tanggal_sk' => '2026-08-28',
            'file_sk' => UploadedFile::fake()->create('sk-pensiun.pdf', 128, 'application/pdf'),
        ];

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->postJsonWithCsrf(route('ews.followup.update', $alert), $payload);
            $this->fail('Kegagalan intent kedua wajib diteruskan agar dapat di-retry.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Paksa gagal di antara dua intent notifikasi.', $exception->getMessage());
        }

        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_HANDLED, $alert->refresh()->followup_status);
        $this->assertNotNull($alert->lifecycle_notified_at);
        $this->assertNull($alert->followup_notified_at);
        $this->assertNotNull($sibling->refresh()->lifecycle_notified_at);
        $this->assertNotNull($sibling->followup_notified_at);
        $this->assertSame(1, SimpegNotification::query()->where('type', 'status_pegawai.dinonaktifkan')->count());
        $this->assertSame(0, SimpegNotification::query()->where('type', 'ews.followup.pensiun')->count());

        $flakyNotifications->failOnCall = null;
        $this->actingAs($user)->postJsonWithCsrf(route('ews.followup.update', $alert), [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'SK pensiun telah diterbitkan.',
        ])->assertOk();

        $this->assertNotNull($alert->refresh()->followup_notified_at);
        $this->assertSame(1, EmployeeStatusHistory::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(1, AuditLog::query()->where('auditable_type', 'Employee')->where('auditable_id', $employee->id)->count());
        $this->assertSame(1, Document::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(1, SimpegNotification::query()->where('type', 'status_pegawai.dinonaktifkan')->count());
        $this->assertSame(1, SimpegNotification::query()->where('type', 'ews.followup.pensiun')->count());
        $this->assertCount(1, Storage::disk(Document::STORAGE_DISK)->allFiles());
    }

    public function test_recovery_tidak_mengirim_notifikasi_pensiun_lama_setelah_pegawai_dipulihkan(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $alert = $this->activeAlertFor($employee, 'PENSIUN', now()->subDay()->toDateString(), 90);
        $realNotifications = app(NotificationService::class);
        $flakyNotifications = new class($realNotifications) extends NotificationService
        {
            public bool $shouldFail = true;

            public function __construct(private readonly NotificationService $inner) {}

            public function createForEmployee(Employee $employee, string $type, string $title, string $body, ?array $data = null): ?SimpegNotification
            {
                if ($this->shouldFail) {
                    throw new \RuntimeException('Paksa gagal intent lifecycle sebelum restore.');
                }

                return $this->inner->createForEmployee($employee, $type, $title, $body, $data);
            }
        };
        $this->app->instance(NotificationService::class, $flakyNotifications);
        $payload = [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'SK pensiun sebelum pemulihan.',
            'no_sk' => 'SK-PENSIUN-SUPERSEDED',
            'tanggal_sk' => now('Asia/Makassar')->toDateString(),
            'file_sk' => UploadedFile::fake()->create('sk-pensiun.pdf', 128, 'application/pdf'),
        ];

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->postJsonWithCsrf(route('ews.followup.update', $alert), $payload);
            $this->fail('Kegagalan intent lifecycle wajib diteruskan untuk recovery.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Paksa gagal intent lifecycle sebelum restore.', $exception->getMessage());
        }

        $this->assertFalse($employee->refresh()->isActive());
        $this->assertNull($alert->refresh()->lifecycle_notified_at);
        $flakyNotifications->shouldFail = false;
        $restoreRequest = Request::create('/pegawai/restore', 'POST', [
            'tanggal_efektif' => now('Asia/Makassar')->toDateString(),
            'alasan' => 'Pemulihan resmi sebelum retry notifikasi EWS.',
        ], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'SIMPEG-EWS-Recovery-Test/1.0',
        ]);
        $restoreRequest->setUserResolver(static fn (): User => $user);
        app(RestoreEmployeeAction::class)->execute($employee->fresh(), $restoreRequest);
        $this->assertTrue($employee->refresh()->isActive());

        $this->postJsonWithCsrf(route('ews.followup.update', $alert), [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'SK pensiun sebelum pemulihan.',
        ])->assertOk();

        $this->assertNull($alert->refresh()->lifecycle_notified_at);
        $this->assertNotNull($alert->lifecycle_notification_superseded_at);
        $this->assertSame(0, SimpegNotification::query()->where('type', 'status_pegawai.dinonaktifkan')->count());
        $this->assertSame(1, SimpegNotification::query()->where('type', 'status_pegawai.diubah')->count());
        $this->assertSame(1, SimpegNotification::query()->where('type', 'ews.followup.pensiun')->count());
    }

    public function test_recovery_alert_lama_tidak_mengirim_ulang_setelah_pensiun_generasi_baru(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $oldAlert = $this->activeAlertFor($employee, 'PENSIUN', now()->subDay()->toDateString(), 90);
        $realNotifications = app(NotificationService::class);
        $flakyNotifications = new class($realNotifications) extends NotificationService
        {
            public bool $shouldFail = true;

            public function __construct(private readonly NotificationService $inner) {}

            public function createForEmployee(Employee $employee, string $type, string $title, string $body, ?array $data = null): ?SimpegNotification
            {
                if ($this->shouldFail) {
                    throw new \RuntimeException('Paksa gagal intent lifecycle generasi lama.');
                }

                return $this->inner->createForEmployee($employee, $type, $title, $body, $data);
            }
        };
        $this->app->instance(NotificationService::class, $flakyNotifications);

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->postJsonWithCsrf(route('ews.followup.update', $oldAlert), [
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
                'handled_note' => 'Pensiun generasi lama.',
                'no_sk' => 'SK-PENSIUN-LAMA',
                'tanggal_sk' => now('Asia/Makassar')->toDateString(),
                'file_sk' => UploadedFile::fake()->create('sk-pensiun-lama.pdf', 128, 'application/pdf'),
            ]);
            $this->fail('Kegagalan intent lifecycle generasi lama wajib diteruskan.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Paksa gagal intent lifecycle generasi lama.', $exception->getMessage());
        }

        $oldHistoryId = EmployeeStatusHistory::query()->where('employee_id', $employee->id)->where('is_latest', true)->value('id');
        $this->assertSame($oldHistoryId, $oldAlert->refresh()->lifecycle_status_history_id);
        $flakyNotifications->shouldFail = false;
        $restoreRequest = Request::create('/pegawai/restore', 'POST', [
            'tanggal_efektif' => now('Asia/Makassar')->toDateString(),
            'alasan' => 'Pemulihan sebelum pensiun generasi baru.',
        ], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'SIMPEG-EWS-Generation-Test/1.0',
        ]);
        $restoreRequest->setUserResolver(static fn (): User => $user);
        app(RestoreEmployeeAction::class)->execute($employee->fresh(), $restoreRequest);

        $newAlert = $this->activeAlertFor($employee->fresh(), 'PENSIUN', now()->toDateString(), 180);
        $this->postJsonWithCsrf(route('ews.followup.update', $newAlert), [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'Pensiun generasi baru.',
            'no_sk' => 'SK-PENSIUN-BARU',
            'tanggal_sk' => now('Asia/Makassar')->toDateString(),
            'file_sk' => UploadedFile::fake()->create('sk-pensiun-baru.pdf', 128, 'application/pdf'),
        ])->assertOk();
        $newHistoryId = EmployeeStatusHistory::query()->where('employee_id', $employee->id)->where('is_latest', true)->value('id');
        $this->assertNotSame($oldHistoryId, $newHistoryId);
        $this->assertSame($newHistoryId, $newAlert->refresh()->lifecycle_status_history_id);
        $this->assertSame(1, SimpegNotification::query()->where('type', 'status_pegawai.dinonaktifkan')->count());

        $this->postJsonWithCsrf(route('ews.followup.update', $oldAlert), [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'Retry pensiun generasi lama.',
        ])->assertOk();

        $this->assertNull($oldAlert->refresh()->lifecycle_notified_at);
        $this->assertNotNull($oldAlert->lifecycle_notification_superseded_at);
        $this->assertNotNull($newAlert->refresh()->lifecycle_notified_at);
        $this->assertSame(1, SimpegNotification::query()->where('type', 'status_pegawai.dinonaktifkan')->count());
    }

    public function test_scheduler_recovery_tidak_mengirim_notifikasi_pensiun_lama_setelah_pegawai_dipulihkan(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $effectiveDate = now('Asia/Makassar')->addDays(30)->toDateString();
        $alert = $this->activeAlertFor($employee, 'PENSIUN', $effectiveDate, 90);
        $this->actingAs($user)->postJsonWithCsrf(route('ews.followup.update', $alert), [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'SK pensiun terjadwal sebelum pemulihan.',
            'no_sk' => 'SK-PENSIUN-SCHEDULED-SUPERSEDED',
            'tanggal_sk' => $effectiveDate,
            'file_sk' => UploadedFile::fake()->createWithContent('sk-pensiun.pdf', 'SK pensiun terjadwal'),
        ])->assertOk();

        $realNotifications = app(NotificationService::class);
        $flakyNotifications = new class($realNotifications) extends NotificationService
        {
            public bool $shouldFail = true;

            public function __construct(private readonly NotificationService $inner) {}

            public function createForEmployee(Employee $employee, string $type, string $title, string $body, ?array $data = null): ?SimpegNotification
            {
                if ($this->shouldFail) {
                    throw new \RuntimeException('Paksa gagal intent lifecycle scheduler sebelum restore.');
                }

                return $this->inner->createForEmployee($employee, $type, $title, $body, $data);
            }
        };
        $this->app->instance(NotificationService::class, $flakyNotifications);
        $service = app(EmployeeStatusTransitionService::class);
        $this->assertSame(1, $service->applyDue($effectiveDate));
        $this->assertFalse($employee->refresh()->isActive());
        $this->assertNull($alert->refresh()->lifecycle_notified_at);

        $flakyNotifications->shouldFail = false;
        $restoreRequest = Request::create('/pegawai/restore', 'POST', [
            'tanggal_efektif' => now('Asia/Makassar')->toDateString(),
            'alasan' => 'Pemulihan resmi sebelum retry scheduler EWS.',
        ], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'SIMPEG-EWS-Scheduler-Recovery-Test/1.0',
        ]);
        $restoreRequest->setUserResolver(static fn (): User => $user);
        app(RestoreEmployeeAction::class)->execute($employee->fresh(), $restoreRequest);
        $this->assertTrue($employee->refresh()->isActive());

        $this->assertSame(0, $service->applyDue($effectiveDate));
        $this->assertNull($alert->refresh()->lifecycle_notified_at);
        $this->assertNotNull($alert->lifecycle_notification_superseded_at);
        $this->assertSame(0, SimpegNotification::query()->where('type', 'status_pegawai.dinonaktifkan')->count());
        $this->assertSame(1, SimpegNotification::query()->where('type', 'status_pegawai.diubah')->count());
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
            'title' => 'Tindak Lanjut EWS: Ditangani',
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
