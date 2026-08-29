<?php

namespace Tests\Feature;

use App\Actions\Employees\ChangeEmployeeStatusAction;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeStatusTransition;
use App\Models\RefStatusPegawai;
use App\Models\SimpegNotification;
use App\Models\StorageRecoveryTask;
use App\Models\User;
use App\Services\Employees\EmployeeStatusActorContext;
use App\Services\Employees\EmployeeStatusLifecycleService;
use App\Services\Employees\EmployeeStatusTransitionService;
use App\Services\StorageRecoveryService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * K-STATUS-06: transisi status terjadwal (future effective date) — disimpan tanpa
 * mengubah snapshot, diterapkan oleh scheduler saat jatuh tempo, idempoten terhadap
 * retry/double worker.
 */
class EmployeeStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_future_status_change_tersimpan_tanpa_mengubah_snapshot(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $mutasi = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();

        $transition = app(EmployeeStatusTransitionService::class)->schedule(
            $employee,
            $mutasi,
            now('Asia/Makassar')->addDays(30)->toDateString(),
            EmployeeStatusTransition::KIND_STATUS,
            'Mutasi terjadwal',
            actorContext: $this->actorContext($employee, $mutasi, EmployeeStatusTransition::KIND_STATUS),
        );

        $this->assertFalse($transition->refresh()->is_applied);
        $employee->refresh();
        $this->assertSame('Aktif', $employee->status_aktif);
        $this->assertDatabaseCount('employee_status_histories', 0);
    }

    public function test_datetime_hari_ini_tidak_dianggap_transisi_masa_depan(): void
    {
        $todayAtNoon = now('Asia/Makassar')->startOfDay()->setHour(12)->toDateTimeString();

        $this->assertFalse(app(EmployeeStatusTransitionService::class)->isFuture($todayAtNoon));
    }

    public function test_datetime_beroffset_dikonversi_ke_tanggal_bisnis_wita_sebelum_dibandingkan(): void
    {
        $this->travelTo('2026-08-28 12:00:00');

        $this->assertFalse(app(EmployeeStatusTransitionService::class)->isFuture('2026-08-28T01:00:00Z'));
    }

    public function test_scheduler_menerapkan_transisi_yang_sudah_jatuh_tempo(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $mutasi = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $tanggalLalu = now('Asia/Makassar')->subDays(1)->toDateString();

        app(EmployeeStatusTransitionService::class)->schedule(
            $employee,
            $mutasi,
            $tanggalLalu,
            EmployeeStatusTransition::KIND_STATUS,
            'Mutasi dilakukan.',
            actorContext: $this->actorContext($employee, $mutasi, EmployeeStatusTransition::KIND_STATUS),
        );

        Artisan::call('employees:apply-status-transitions');

        $employee->refresh();
        $this->assertSame('Mutasi', $employee->status_aktif);
        $this->assertSame($mutasi->id, $employee->status_pegawai_id);
        $this->assertDatabaseHas('employee_status_transitions', [
            'employee_id' => $employee->id,
            'is_applied' => true,
        ]);

        // Scheduler diminta JANGAN berproduksi history ganda pada run berikutnya.
        Artisan::call('employees:apply-status-transitions');
        $this->assertSame(1, EmployeeStatusHistory::query()
            ->where('employee_id', $employee->id)
            ->count());
        $this->assertSame(1, EmployeeStatusTransition::query()
            ->where('employee_id', $employee->id)
            ->where('kind', 'status')
            ->where('is_applied', true)
            ->count());
    }

    public function test_scheduler_tidak_menerapkan_transisi_belum_jatuh_tempo(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $mutasi = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();

        app(EmployeeStatusTransitionService::class)->schedule(
            $employee,
            $mutasi,
            now('Asia/Makassar')->addDays(10)->toDateString(),
            EmployeeStatusTransition::KIND_STATUS,
            'Belum jatuh tempo.',
            actorContext: $this->actorContext($employee, $mutasi, EmployeeStatusTransition::KIND_STATUS),
        );

        Artisan::call('employees:apply-status-transitions');

        $employee->refresh();
        $this->assertSame('Aktif', $employee->status_aktif);
        $this->assertDatabaseHas('employee_status_transitions', [
            'employee_id' => $employee->id,
            'is_applied' => false,
        ]);
    }

    public function test_schedule_deactivate_rechecks_locked_source_state_before_creating_transition(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $nonaktif = RefStatusPegawai::where('kode', 'NONAKTIF')->firstOrFail();
        $mutasi = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();

        Employee::query()->whereKey($employee->id)->update([
            'status_pegawai_id' => $mutasi->id,
            'status_aktif' => $mutasi->nama,
        ]);

        try {
            app(EmployeeStatusTransitionService::class)->schedule(
                $employee,
                $nonaktif,
                now('Asia/Makassar')->addDay()->toDateString(),
                EmployeeStatusTransition::KIND_DEACTIVATE,
                'Model pemanggil sudah stale.',
                actorContext: $this->actorContext($employee, $nonaktif, EmployeeStatusTransition::KIND_DEACTIVATE),
            );
            $this->fail('Schedule deactivate wajib menolak source state terkunci yang sudah nonaktif.');
        } catch (ValidationException) {
            // Expected: validasi memakai row employee terkunci, bukan model stale.
        }

        $this->assertDatabaseCount('employee_status_transitions', 0);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_schedule_restore_rechecks_locked_source_state_before_creating_transition(): void
    {
        $nonaktif = RefStatusPegawai::where('kode', 'NONAKTIF')->firstOrFail();
        $aktif = RefStatusPegawai::where('kode', 'AKTIF')->firstOrFail();
        $employee = Employee::factory()->create([
            'status_pegawai_id' => $nonaktif->id,
            'status_aktif' => $nonaktif->nama,
        ]);

        Employee::query()->whereKey($employee->id)->update([
            'status_pegawai_id' => $aktif->id,
            'status_aktif' => $aktif->nama,
        ]);

        try {
            app(EmployeeStatusTransitionService::class)->schedule(
                $employee,
                $aktif,
                now('Asia/Makassar')->addDay()->toDateString(),
                EmployeeStatusTransition::KIND_RESTORE,
                'Model pemanggil sudah stale.',
                actorContext: $this->actorContext($employee, $aktif, EmployeeStatusTransition::KIND_RESTORE),
            );
            $this->fail('Schedule restore wajib menolak source state terkunci yang sudah aktif.');
        } catch (ValidationException) {
            // Expected: validasi memakai row employee terkunci, bukan model stale.
        }

        $this->assertDatabaseCount('employee_status_transitions', 0);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_future_generic_same_target_with_attachment_has_zero_side_effects(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $admin = User::factory()->superAdmin()->create();
        $mutasi = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $employee = Employee::factory()->create([
            'status_pegawai_id' => $mutasi->id,
            'status_aktif' => $mutasi->nama,
        ]);
        $this->actingAs($admin);

        try {
            app(ChangeEmployeeStatusAction::class)->execute(
                $employee,
                [
                    'status_pegawai_id' => $mutasi->id,
                    'tanggal' => now('Asia/Makassar')->addDay()->toDateString(),
                    'keterangan' => 'Target sama tidak boleh membuat jadwal.',
                ],
                request(),
                UploadedFile::fake()->create('sk-status-sama.pdf', 128, 'application/pdf'),
            );
            $this->fail('Target status yang sama wajib menjadi no-op sebelum document factory.');
        } catch (ValidationException) {
            // Expected: no-op diterjemahkan menjadi validation error oleh jalur mutasi.
        }

        $this->assertDatabaseCount('employee_status_transitions', 0);
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame([], Storage::disk(Document::STORAGE_DISK)->allFiles());
    }

    public function test_scheduler_same_target_different_date_is_noop_without_side_effects(): void
    {
        $mutasi = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $employee = Employee::factory()->create([
            'status_pegawai_id' => $mutasi->id,
            'status_aktif' => $mutasi->nama,
            'status_tanggal' => '2026-08-01',
            'status_keterangan' => 'Status yang sudah berlaku.',
            'status_note' => 'Catatan lama harus tetap.',
        ]);

        try {
            app(EmployeeStatusTransitionService::class)->schedule(
                $employee,
                $mutasi,
                '2026-08-02',
                EmployeeStatusTransition::KIND_STATUS,
                'Alasan retry berbeda.',
                actorContext: $this->actorContext($employee, $mutasi, EmployeeStatusTransition::KIND_STATUS),
            );
            $this->fail('Target status sama tidak boleh membuat transisi yang hanya akan menjadi no-op.');
        } catch (ValidationException) {
            // Expected: no-op dihentikan saat create, sebelum ada side effect jadwal.
        }

        $employee->refresh();
        $this->assertDatabaseCount('employee_status_transitions', 0);
        $this->assertSame('2026-08-01', $employee->status_tanggal?->toDateString());
        $this->assertSame('Status yang sudah berlaku.', $employee->status_keterangan);
        $this->assertSame('Catatan lama harus tetap.', $employee->status_note);
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_apply_due_menandai_transisi_noop_tanpa_menghitung_mutasi_atau_side_effect(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $this->actingAs($actor);
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $target = RefStatusPegawai::query()->where('kode', 'MUTASI')->firstOrFail();
        $tanggalEfektif = now('Asia/Makassar')->subDay()->toDateString();
        $service = app(EmployeeStatusTransitionService::class);
        $transition = $service->schedule(
            $employee,
            $target,
            $tanggalEfektif,
            EmployeeStatusTransition::KIND_STATUS,
            'Target sudah diterapkan writer lain.',
            actorContext: $this->actorContext($employee, $target, EmployeeStatusTransition::KIND_STATUS, $actor),
        );

        // Writer lain mencapai target sesudah jadwal dibuat tetapi sebelum scheduler.
        Employee::query()->whereKey($employee->id)->update([
            'status_pegawai_id' => $target->id,
            'status_aktif' => $target->nama,
        ]);

        $this->assertSame(0, $service->applyDue($tanggalEfektif));
        $this->assertTrue($transition->refresh()->is_applied);
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertSame(0, AuditLog::query()
            ->where('auditable_type', 'Employee')
            ->where('auditable_id', $employee->id)
            ->count());
        $this->assertSame(0, SimpegNotification::query()->where('user_id', $employee->id)->count());
    }

    public function test_due_deactivate_rechecks_state_and_rejects_employee_already_inactive_by_other_status(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin);
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $nonaktif = RefStatusPegawai::where('kode', 'NONAKTIF')->firstOrFail();
        $mutasi = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $transition = app(EmployeeStatusTransitionService::class)->schedule(
            $employee,
            $nonaktif,
            '2026-08-02',
            EmployeeStatusTransition::KIND_DEACTIVATE,
            'Deaktivasi terjadwal.',
            actorContext: $this->actorContext($employee, $nonaktif, EmployeeStatusTransition::KIND_DEACTIVATE, $admin),
        );

        Employee::query()->whereKey($employee->id)->update([
            'status_pegawai_id' => $mutasi->id,
            'status_aktif' => $mutasi->nama,
            'status_tanggal' => '2026-08-01',
        ]);

        $this->assertSame(0, app(EmployeeStatusTransitionService::class)->applyDue('2026-08-03'));
        $this->assertFalse($transition->refresh()->is_applied);
        $this->assertSame($mutasi->id, $employee->refresh()->status_pegawai_id);
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_due_restore_rechecks_state_and_rejects_employee_already_active(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin);
        $nonaktif = RefStatusPegawai::where('kode', 'NONAKTIF')->firstOrFail();
        $aktif = RefStatusPegawai::where('kode', 'AKTIF')->firstOrFail();
        $employee = Employee::factory()->create([
            'status_pegawai_id' => $nonaktif->id,
            'status_aktif' => $nonaktif->nama,
        ]);
        $transition = app(EmployeeStatusTransitionService::class)->schedule(
            $employee,
            $aktif,
            '2026-08-02',
            EmployeeStatusTransition::KIND_RESTORE,
            'Restore terjadwal.',
            actorContext: $this->actorContext($employee, $aktif, EmployeeStatusTransition::KIND_RESTORE, $admin),
        );

        Employee::query()->whereKey($employee->id)->update([
            'status_pegawai_id' => $aktif->id,
            'status_aktif' => $aktif->nama,
            'status_tanggal' => '2026-08-01',
        ]);

        $this->assertSame(0, app(EmployeeStatusTransitionService::class)->applyDue('2026-08-03'));
        $this->assertFalse($transition->refresh()->is_applied);
        $this->assertSame($aktif->id, $employee->refresh()->status_pegawai_id);
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_future_deactivate_tersimpan_tanpa_notifikasi_history(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);

        app(EmployeeStatusTransitionService::class)->schedule(
            $employee,
            RefStatusPegawai::where('kode', 'NONAKTIF')->firstOrFail(),
            now('Asia/Makassar')->addDays(5)->toDateString(),
            EmployeeStatusTransition::KIND_DEACTIVATE,
            'Pensiun terjadwal.',
            actorContext: $this->actorContext($employee, RefStatusPegawai::where('kode', 'NONAKTIF')->firstOrFail(), EmployeeStatusTransition::KIND_DEACTIVATE),
        );

        $employee->refresh();
        $this->assertSame('Aktif', $employee->status_aktif);
        $this->assertDatabaseHas('employee_status_transitions', [
            'employee_id' => $employee->id,
            'kind' => 'deactivate',
            'is_applied' => false,
        ]);
    }

    public function test_future_status_change_dengan_berkas_memakai_satu_dokumen_sampai_retry_scheduler(): void
    {
        Storage::fake(Document::STORAGE_DISK);

        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $mutasi = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $tanggalEfektif = now('Asia/Makassar')->addDay()->toDateString();
        $request = Request::create('/scheduled-status-transition', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'SIMPEG-Transition-Test/1.0',
        ]);
        $request->setUserResolver(static fn (): User => $admin);
        $this->actingAs($admin);

        app(ChangeEmployeeStatusAction::class)->execute(
            $employee,
            [
                'status_pegawai_id' => $mutasi->id,
                'tanggal' => $tanggalEfektif,
                'keterangan' => 'Mutasi terjadwal dengan SK.',
            ],
            $request,
            UploadedFile::fake()->createWithContent('sk-mutasi.pdf', 'isi SK mutasi terjadwal'),
        );

        $transition = EmployeeStatusTransition::query()->sole();
        $document = Document::query()->sole();

        $this->assertFalse($transition->is_applied);
        $this->assertSame($document->id, $transition->document_id);
        $this->assertSame($employee->id, $document->employee_id);
        $this->assertSame('sk_status_pegawai', $document->jenis_dokumen);
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
        $this->assertTrue($employee->refresh()->isActive());
        $this->assertDatabaseCount('employee_status_histories', 0);

        $service = app(EmployeeStatusTransitionService::class);
        $this->travelTo(Carbon::parse($tanggalEfektif, 'Asia/Makassar')->startOfDay());
        $this->assertSame(1, $service->applyDue($tanggalEfektif));

        $employee->refresh();
        $history = EmployeeStatusHistory::query()->where('employee_id', $employee->id)->sole();
        $audit = AuditLog::query()
            ->where('auditable_type', 'Employee')
            ->where('auditable_id', $employee->id)
            ->sole();

        $this->assertSame($document->file_path, $employee->status_berkas_path);
        $this->assertSame($document->nomor_dokumen, $employee->status_nomor_berkas);
        $this->assertSame($document->file_path, $history->file_sk);
        $this->assertSame($document->nomor_dokumen, $history->nomor_berkas);
        $this->assertStringNotContainsString(
            $document->file_path,
            json_encode([$audit->old_values, $audit->new_values], JSON_THROW_ON_ERROR),
        );

        $this->assertSame(0, $service->applyDue($tanggalEfektif));
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

    public function test_kegagalan_persistensi_jadwal_membersihkan_berkas_privat_yang_belum_sah(): void
    {
        Storage::fake(Document::STORAGE_DISK);

        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $mutasi = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $request = Request::create('/scheduled-status-transition', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'SIMPEG-Transition-Test/1.0',
        ]);
        $request->setUserResolver(static fn (): User => $admin);
        $this->actingAs($admin);

        Document::creating(static function (): never {
            throw new RuntimeException('Paksa gagal menyimpan metadata dokumen.');
        });

        try {
            app(ChangeEmployeeStatusAction::class)->execute(
                $employee,
                [
                    'status_pegawai_id' => $mutasi->id,
                    'tanggal' => now('Asia/Makassar')->addDay()->toDateString(),
                    'keterangan' => 'Mutasi terjadwal dengan kegagalan metadata.',
                ],
                $request,
                UploadedFile::fake()->create('sk-mutasi.pdf', 200, 'application/pdf'),
            );

            $this->fail('Kegagalan metadata dokumen seharusnya diteruskan ke caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Paksa gagal menyimpan metadata dokumen.', $exception->getMessage());
        }

        $this->assertDatabaseCount('employee_status_transitions', 0);
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame([], Storage::disk(Document::STORAGE_DISK)->allFiles());
    }

    public function test_apply_due_menghabiskan_seratus_transisi_dalam_satu_invocation(): void
    {
        $status = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $tanggalEfektif = now('Asia/Makassar')->subDay()->toDateString();
        $service = app(EmployeeStatusTransitionService::class);

        Employee::factory()->count(100)->create(['status_aktif' => 'Aktif'])
            ->each(fn (Employee $employee) => $service->schedule(
                $employee,
                $status,
                $tanggalEfektif,
                EmployeeStatusTransition::KIND_STATUS,
                'Batch terjadwal.',
                actorContext: $this->actorContext($employee, $status, EmployeeStatusTransition::KIND_STATUS),
            ));

        $this->assertSame(100, $service->applyDue($tanggalEfektif));
        $this->assertSame(100, EmployeeStatusTransition::query()->where('is_applied', true)->count());
        $this->assertDatabaseCount('employee_status_histories', 100);
    }

    public function test_apply_due_tetap_melanjutkan_row_berikutnya_setelah_satu_row_gagal(): void
    {
        $status = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $service = app(EmployeeStatusTransitionService::class);
        $employeeGagal = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $employeeBerhasil = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $tanggalGagal = now('Asia/Makassar')->subDays(2)->toDateString();
        $tanggalBerhasil = now('Asia/Makassar')->subDay()->toDateString();

        EmployeeStatusTransition::query()->create([
            'employee_id' => $employeeGagal->id,
            'status_pegawai_id' => $status->id,
            'tanggal_efektif' => $tanggalGagal,
            'kind' => 'kind_tidak_dikenal',
            'keterangan' => 'Transisi rusak untuk menguji isolasi kegagalan.',
        ]);
        $service->schedule(
            $employeeBerhasil,
            $status,
            $tanggalBerhasil,
            EmployeeStatusTransition::KIND_STATUS,
            'Transisi valid setelah row gagal.',
            actorContext: $this->actorContext($employeeBerhasil, $status, EmployeeStatusTransition::KIND_STATUS),
        );

        $this->assertSame(1, $service->applyDue(now('Asia/Makassar')->toDateString()));
        $this->assertFalse(EmployeeStatusTransition::query()
            ->where('employee_id', $employeeGagal->id)
            ->sole()
            ->is_applied);
        $this->assertTrue(EmployeeStatusTransition::query()
            ->where('employee_id', $employeeBerhasil->id)
            ->sole()
            ->is_applied);
    }

    public function test_command_mengembalikan_failure_bila_transisi_due_tetap_pending(): void
    {
        $status = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $employeeGagal = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $employeeBerhasil = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $asOf = now('Asia/Makassar')->toDateString();

        EmployeeStatusTransition::query()->create([
            'employee_id' => $employeeGagal->id,
            'status_pegawai_id' => $status->id,
            'tanggal_efektif' => now('Asia/Makassar')->subDay()->toDateString(),
            'kind' => 'kind_tidak_dikenal',
            'keterangan' => 'Transisi rusak untuk menguji exit command.',
        ]);
        app(EmployeeStatusTransitionService::class)->schedule(
            $employeeBerhasil,
            $status,
            $asOf,
            EmployeeStatusTransition::KIND_STATUS,
            'Transisi valid tetap harus diterapkan.',
            actorContext: $this->actorContext($employeeBerhasil, $status, EmployeeStatusTransition::KIND_STATUS),
        );

        $exitCode = Artisan::call('employees:apply-status-transitions', ['--as-of' => $asOf]);
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('diterapkan: 1', $output);
        $this->assertStringContainsString('masih pending: 1', $output);
        $this->assertFalse(EmployeeStatusTransition::query()
            ->where('employee_id', $employeeGagal->id)
            ->sole()
            ->is_applied);
        $this->assertTrue(EmployeeStatusTransition::query()
            ->where('employee_id', $employeeBerhasil->id)
            ->sole()
            ->is_applied);
    }

    public function test_command_tetap_success_bila_hanya_ada_transisi_yang_belum_jatuh_tempo(): void
    {
        $status = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $asOf = now('Asia/Makassar')->toDateString();
        $effectiveDate = now('Asia/Makassar')->addDay()->toDateString();

        app(EmployeeStatusTransitionService::class)->schedule(
            $employee,
            $status,
            $effectiveDate,
            EmployeeStatusTransition::KIND_STATUS,
            'Transisi masa depan tidak boleh membuat command gagal.',
            actorContext: $this->actorContext($employee, $status, EmployeeStatusTransition::KIND_STATUS),
        );

        $exitCode = Artisan::call('employees:apply-status-transitions', ['--as-of' => $asOf]);
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('diterapkan: 0', $output);
        $this->assertStringNotContainsString('masih pending', $output);
        $this->assertFalse(EmployeeStatusTransition::query()->sole()->is_applied);
    }

    public function test_scheduler_tidak_mencatat_private_path_dari_exception_ke_log(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $status = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $tanggalEfektif = now('Asia/Makassar')->subDay()->toDateString();
        $privatePath = 'private/rahasia.pdf';

        $transition = EmployeeStatusTransition::query()->create([
            'employee_id' => $employee->id,
            'status_pegawai_id' => $status->id,
            'tanggal_efektif' => $tanggalEfektif,
            // Kind invalid menjadi stimulus exception nyata yang membawa private path.
            'kind' => $privatePath,
            'keterangan' => 'Uji sanitasi log scheduler.',
        ]);

        Event::fake([MessageLogged::class]);

        $this->assertSame(0, app(EmployeeStatusTransitionService::class)->applyDue($tanggalEfektif));
        Event::assertDispatched(MessageLogged::class, function (MessageLogged $event) use ($transition, $privatePath): bool {
            return $event->level === 'error'
                && $event->message === 'Transisi status terjadwal gagal diterapkan'
                && $event->context['transition_id'] === $transition->id
                && $event->context['employee_id'] === $transition->employee_id
                && ($event->context['error_type'] ?? null) === RuntimeException::class
                && ! str_contains(json_encode($event->context, JSON_THROW_ON_ERROR), $privatePath);
        });
    }

    public function test_scheduler_memulihkan_auth_kosong_setelah_menerapkan_jadwal_dengan_aktor(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $status = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $tanggalEfektif = now('Asia/Makassar')->subDay()->toDateString();
        $service = app(EmployeeStatusTransitionService::class);

        Auth::login($admin);
        $service->schedule(
            $employee,
            $status,
            $tanggalEfektif,
            EmployeeStatusTransition::KIND_STATUS,
            'Jadwal dengan aktor administratif.',
            actorContext: $this->actorContext($employee, $status, EmployeeStatusTransition::KIND_STATUS, $admin),
        );
        Auth::logout();

        $this->assertSame(1, $service->applyDue($tanggalEfektif));
        $this->assertNull(Auth::user());
        $this->assertSame('Mutasi', $employee->refresh()->status_aktif);
    }

    public function test_service_menolak_target_status_berbeda_pada_pegawai_dan_tanggal_pending_yang_sama(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $mutasi = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $pensiun = RefStatusPegawai::where('kode', 'PENSIUN')->firstOrFail();
        $tanggalEfektif = now('Asia/Makassar')->addDay()->toDateString();
        $service = app(EmployeeStatusTransitionService::class);

        $service->schedule(
            $employee,
            $mutasi,
            $tanggalEfektif,
            EmployeeStatusTransition::KIND_STATUS,
            actorContext: $this->actorContext($employee, $mutasi, EmployeeStatusTransition::KIND_STATUS),
        );

        try {
            $service->schedule(
                $employee,
                $pensiun,
                $tanggalEfektif,
                EmployeeStatusTransition::KIND_STATUS,
                actorContext: $this->actorContext($employee, $pensiun, EmployeeStatusTransition::KIND_STATUS),
            );
            $this->fail('Service harus menolak lebih dari satu transisi pending per pegawai dan tanggal.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Perubahan status pada tanggal tersebut sudah dijadwalkan.'],
                $exception->errors()['tanggal_efektif'] ?? [],
            );
        }
    }

    public function test_database_menolak_target_status_berbeda_pada_pegawai_dan_tanggal_pending_yang_sama(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $mutasi = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $pensiun = RefStatusPegawai::where('kode', 'PENSIUN')->firstOrFail();
        $tanggalEfektif = now('Asia/Makassar')->addDay()->toDateString();

        EmployeeStatusTransition::query()->create([
            'employee_id' => $employee->id,
            'status_pegawai_id' => $mutasi->id,
            'tanggal_efektif' => $tanggalEfektif,
            'kind' => EmployeeStatusTransition::KIND_STATUS,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        EmployeeStatusTransition::query()->create([
            'employee_id' => $employee->id,
            'status_pegawai_id' => $pensiun->id,
            'tanggal_efektif' => $tanggalEfektif,
            'kind' => EmployeeStatusTransition::KIND_STATUS,
        ]);
    }

    public function test_hardening_migration_fail_closed_dan_mempertahankan_semua_jadwal_duplikat(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $mutasi = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $pensiun = RefStatusPegawai::where('kode', 'PENSIUN')->firstOrFail();
        $tanggalEfektif = now('Asia/Makassar')->addDay()->toDateString();
        $migration = require database_path('migrations/2026_08_27_000003_harden_employee_status_transitions.php');
        $migration->down();

        EmployeeStatusTransition::query()->create([
            'employee_id' => $employee->id,
            'status_pegawai_id' => $mutasi->id,
            'tanggal_efektif' => $tanggalEfektif,
            'kind' => EmployeeStatusTransition::KIND_STATUS,
        ]);
        $duplicate = EmployeeStatusTransition::query()->create([
            'employee_id' => $employee->id,
            'status_pegawai_id' => $pensiun->id,
            'tanggal_efektif' => $tanggalEfektif,
            'kind' => EmployeeStatusTransition::KIND_STATUS,
        ]);

        try {
            try {
                $migration->up();
                $this->fail('Migration harus berhenti sebelum mengubah atau memilih jadwal duplikat.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('jadwal pending duplikat', $exception->getMessage());
            }

            $this->assertSame(2, EmployeeStatusTransition::query()
                ->where('employee_id', $employee->id)
                ->whereDate('tanggal_efektif', $tanggalEfektif)
                ->count());
        } finally {
            DB::table('employee_status_transitions')->where('id', $duplicate->id)->delete();
            $migration->up();
        }
    }

    public function test_pending_employee_berbeda_atau_tanggal_berbeda_tetap_valid(): void
    {
        $employeePertama = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $employeeKedua = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $status = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $tanggalPertama = now('Asia/Makassar')->addDay()->toDateString();
        $tanggalKedua = now('Asia/Makassar')->addDays(2)->toDateString();
        $service = app(EmployeeStatusTransitionService::class);

        $service->schedule($employeePertama, $status, $tanggalPertama, EmployeeStatusTransition::KIND_STATUS, actorContext: $this->actorContext($employeePertama, $status, EmployeeStatusTransition::KIND_STATUS));
        $service->schedule($employeeKedua, $status, $tanggalPertama, EmployeeStatusTransition::KIND_STATUS, actorContext: $this->actorContext($employeeKedua, $status, EmployeeStatusTransition::KIND_STATUS));
        $service->schedule($employeePertama, $status, $tanggalKedua, EmployeeStatusTransition::KIND_STATUS, actorContext: $this->actorContext($employeePertama, $status, EmployeeStatusTransition::KIND_STATUS));

        $this->assertDatabaseCount('employee_status_transitions', 3);
    }

    public function test_schedule_menolak_dokumen_status_milik_pegawai_lain(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $pemilikLain = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $status = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $document = Document::query()->create([
            'employee_id' => $pemilikLain->id,
            'jenis_dokumen' => 'sk_status_pegawai',
            'nama_dokumen' => 'SK pegawai lain',
            'file_path' => 'pegawai-lain/sk-status.pdf',
        ]);

        $this->expectException(ValidationException::class);

        app(EmployeeStatusTransitionService::class)->schedule(
            $employee,
            $status,
            now('Asia/Makassar')->addDay()->toDateString(),
            EmployeeStatusTransition::KIND_STATUS,
            document: $document,
            actorContext: $this->actorContext($employee, $status, EmployeeStatusTransition::KIND_STATUS),
        );
    }

    public function test_schedule_menolak_dokumen_dengan_kategori_bukan_sk_status_pegawai(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $status = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $document = Document::query()->create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Dokumen kategori lain',
            'file_path' => 'pegawai/dokumen-lain.pdf',
        ]);

        $this->expectException(ValidationException::class);

        app(EmployeeStatusTransitionService::class)->schedule(
            $employee,
            $status,
            now('Asia/Makassar')->addDay()->toDateString(),
            EmployeeStatusTransition::KIND_STATUS,
            document: $document,
            actorContext: $this->actorContext($employee, $status, EmployeeStatusTransition::KIND_STATUS),
        );
    }

    public function test_schedule_menolak_metadata_dokumen_tanpa_file_privat(): void
    {
        Storage::fake(Document::STORAGE_DISK);

        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $status = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $document = Document::query()->create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_status_pegawai',
            'nama_dokumen' => 'Metadata tanpa file privat',
            'file_path' => 'pegawai/sk-status-hilang.pdf',
        ]);

        $this->expectException(ValidationException::class);

        app(EmployeeStatusTransitionService::class)->schedule(
            $employee,
            $status,
            now('Asia/Makassar')->addDay()->toDateString(),
            EmployeeStatusTransition::KIND_STATUS,
            document: $document,
            actorContext: $this->actorContext($employee, $status, EmployeeStatusTransition::KIND_STATUS),
        );
    }

    /** Membuat provenance eksplisit untuk caller service pada test non-HTTP. */
    private function actorContext(
        Employee $employee,
        RefStatusPegawai $targetStatus,
        string $kind,
        ?User $actor = null,
    ): EmployeeStatusActorContext {
        $actor ??= User::factory()->superAdmin()->create();
        $request = Request::create('/internal/status-schedule', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'SIMPEG-Transition-Test/1.0',
        ]);
        $request->setUserResolver(static fn (): User => $actor);
        $intent = match ($kind) {
            EmployeeStatusTransition::KIND_DEACTIVATE => EmployeeStatusLifecycleService::INTENT_DEACTIVATE,
            EmployeeStatusTransition::KIND_RESTORE => EmployeeStatusLifecycleService::INTENT_RESTORE,
            default => EmployeeStatusLifecycleService::INTENT_GENERIC,
        };

        return EmployeeStatusActorContext::capture(
            $request,
            app(EmployeeStatusLifecycleService::class)->authorizationPermission($employee, $targetStatus, $intent),
            $kind,
        );
    }
}
