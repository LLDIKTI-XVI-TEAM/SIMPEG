<?php

namespace Tests\Feature;

use App\Actions\Employees\DeactivateEmployeeAction;
use App\Actions\Employees\RestoreEmployeeAction;
use App\Http\Requests\Employee\DeactivateEmployeeRequest;
use App\Http\Requests\Employee\RestoreEmployeeRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\RefStatusPegawai;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Mockery\Expectation;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class EmployeeDeactivateRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    private function nonaktifStatus(): RefStatusPegawai
    {
        return RefStatusPegawai::query()->where('kode', 'NONAKTIF')->firstOrFail();
    }

    private function aktifStatus(): RefStatusPegawai
    {
        return RefStatusPegawai::query()->where('kode', 'AKTIF')->firstOrFail();
    }

    public function test_employee_deactivate_and_restore_permissions_are_seeded(): void
    {
        $this->assertDatabaseHas('permissions', [
            'name' => 'employees.deactivate',
            'module' => 'employees',
        ]);
        $this->assertDatabaseHas('permissions', [
            'name' => 'employees.restore',
            'module' => 'employees',
        ]);

        $admin = User::factory()->adminKepegawaian()->create();

        $this->assertTrue($admin->hasPermission('employees.deactivate'));
        // K-STATUS-04: Admin Kepegawaian juga memegang employees.restore; gate tetap
        // mengevaluasi role EFEKTIF sehingga simulasi tidak dibypass oleh role asli.
        $this->assertTrue($admin->hasPermission('employees.restore'));
    }

    public function test_admin_kepegawaian_can_deactivate_employee_via_api_and_audit_is_written(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Nonaktif API',
            'status_aktif' => 'Aktif',
        ]);

        $this->actingAs($user);
        $response = $this->deleteJsonWithCsrf("/api/v1/pegawai/{$employee->id}", [
            'tanggal_efektif' => '2026-08-01',
            'alasan' => 'Kontrak kerja berakhir.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Data pegawai berhasil dinonaktifkan.');
        $response->assertJsonPath('status_transition.state', 'applied');
        $response->assertJsonPath('status_transition.effective_date', '2026-08-01');

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'status_aktif' => 'Non-Aktif',
            'status_pegawai_id' => $this->nonaktifStatus()->id,
            'status_note' => DeactivateEmployeeRequest::DEFAULT_NOTE,
        ]);
        // Kontrak US-2.9: tanggal efektif + alasan administrasi wajib tercatat di riwayat.
        $this->assertDatabaseHas('employee_status_histories', [
            'employee_id' => $employee->id,
            'status_pegawai_id' => $this->nonaktifStatus()->id,
            'status_nama' => $this->nonaktifStatus()->nama,
            'keterangan' => 'Kontrak kerja berakhir.',
            'tanggal_efektif' => '2026-08-01 00:00:00',
            'is_latest' => true,
        ]);
        // Event audit eksplisit perubahan status — lifecycle soft delete sudah tidak ada.
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_deactivation_requires_tanggal_efektif_and_alasan(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);

        $this->actingAs($user);
        // Tanpa payload kontrak sama sekali → validasi menolak (tanggal + alasan wajib).
        $this->deleteJsonWithCsrf("/api/v1/pegawai/{$employee->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tanggal_efektif', 'alasan']);

    }

    public function test_future_deactivate_mengembalikan_outcome_scheduled_tanpa_mengubah_snapshot(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $effectiveDate = now('Asia/Makassar')->addDay()->toDateString();

        $response = $this->actingAs($user)
            ->withSession(['_token' => 'test-token'])
            ->deleteJson("/api/v1/pegawai/{$employee->id}", [
                '_token' => 'test-token',
                'tanggal_efektif' => $effectiveDate,
                'alasan' => 'Penonaktifan terjadwal.',
            ]);

        $response->assertOk();
        $response->assertJsonPath(
            'message',
            "Penonaktifan pegawai berhasil dijadwalkan untuk tanggal {$effectiveDate}.",
        );
        $response->assertJsonPath('status_transition.state', 'scheduled');
        $response->assertJsonPath('status_transition.effective_date', $effectiveDate);
        $this->assertTrue($employee->refresh()->isActive());
        $this->assertDatabaseHas('employee_status_transitions', [
            'employee_id' => $employee->id,
            'kind' => 'deactivate',
            'is_applied' => false,
        ]);
    }

    public function test_admin_kepegawaian_can_deactivate_employee_with_custom_note(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);

        $this->actingAs($user);
        $this
            ->withSession(['_token' => 'test-token'])
            ->deleteJson("/api/v1/pegawai/{$employee->id}", [
                '_token' => 'test-token',
                'tanggal_efektif' => now()->toDateString(),
                'alasan' => 'Alasan administrasi resmi.',
                'status_note' => 'Harap hubungi bagian kepegawaian.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'status_aktif' => 'Non-Aktif',
            'status_note' => 'Harap hubungi bagian kepegawaian.',
        ]);
    }

    public function test_super_admin_can_deactivate_employee_via_api(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);

        $this->actingAs($user)
            ->deleteJsonWithCsrf("/api/v1/pegawai/{$employee->id}", [
                'tanggal_efektif' => now()->toDateString(),
                'alasan' => 'Alasan administrasi resmi.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'status_aktif' => 'Non-Aktif',
        ]);
    }

    public function test_admin_kepegawaian_melihat_aksi_nonaktifkan_di_daftar_pegawai(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->get(route('data-pegawai'))
            ->assertOk()
            ->assertSee('Nonaktifkan', false)
            ->assertDontSee('Tampilkan Pegawai Non-Aktif', false)
            ->assertDontSee('show_nonaktif', false)
            ->assertDontSee('data-backup', false)
            ->assertDontSee('data-nonaktif', false)
            ->assertSee('aria-label="\'Nonaktifkan pegawai \' + p.nama_lengkap"', false)
            ->assertSee('Biarkan kosong untuk pesan bawaan', false)
            ->assertSeeText('AKUN ANDA TELAH DI NONAKTIFKAN, SILAHKAN HUBUNGI ADMIN!!');
    }

    public function test_tombol_nonaktifkan_tidak_tampil_tanpa_permission(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.deactivate')->value('id');
        $role->permissions()->detach($permissionId);
        $employee = Employee::factory()->create();

        $this->actingAs($user)
            ->get(route('data-pegawai'))
            ->assertOk()
            ->assertDontSee('aria-label="Nonaktifkan pegawai', false);
    }

    public function test_super_admin_can_restore_employee_with_contract_and_audit_is_written(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Restore API',
            'status_aktif' => 'Non-Aktif',
            'status_pegawai_id' => $this->nonaktifStatus()->id,
            'status_note' => 'Pesan nonaktif lama',
        ]);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/restore", [
            'tanggal_efektif' => '2026-08-10',
            'alasan' => 'Masa sanksi administratif berakhir.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Data pegawai berhasil diaktifkan kembali.');
        $response->assertJsonPath('status_transition.state', 'applied');
        $response->assertJsonPath('status_transition.effective_date', '2026-08-10');
        $response->assertJsonPath('employee.id', $employee->id);
        $response->assertJsonMissingPath('employee.nik');
        $response->assertJsonMissingPath('employee.no_kk');
        $response->assertJsonMissingPath('employee.keycloak_id');

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'status_aktif' => 'Aktif',
            'status_pegawai_id' => $this->aktifStatus()->id,
            'status_note' => null,
            'status_tanggal' => '2026-08-10 00:00:00',
        ]);
        // Kontrak US-2.10: tanggal efektif + alasan wajib tercatat pada riwayat status.
        $this->assertDatabaseHas('employee_status_histories', [
            'employee_id' => $employee->id,
            'status_pegawai_id' => $this->aktifStatus()->id,
            'status_nama' => $this->aktifStatus()->nama,
            'keterangan' => 'Masa sanksi administratif berakhir.',
            'tanggal_efektif' => '2026-08-10 00:00:00',
            'is_latest' => true,
        ]);
        // Event audit eksplisit perubahan status, bukan RESTORE lifecycle soft delete.
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_admin_kepegawaian_dapat_restore_employee_dengan_permission(): void
    {
        // K-STATUS-04: reaktivasi boleh Admin Kepegawaian selama role efektif memegang
        // employees.restore. Route web & API kini membuka gate tersebut.
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Nonaktif Admin',
            'status_aktif' => 'Non-Aktif',
            'status_pegawai_id' => $this->nonaktifStatus()->id,
        ]);

        $this->actingAs($user);
        // Route web: diizinkan Admin Kepegawaian ber-permission.
        $this->withSession(['_token' => 'test-token'])
            ->post(route('pegawai.restore', ['id' => $employee->id]), [
                '_token' => 'test-token',
                'tanggal_efektif' => now()->toDateString(),
                'alasan' => 'Alasan valid.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $employee->refresh();
        $this->assertTrue($employee->isActive());

        // Reset ke nonaktif untuk menguji jalur API.
        $employee->update([
            'status_aktif' => 'Nonaktif',
            'status_pegawai_id' => $this->nonaktifStatus()->id,
        ]);

        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/restore", [
            'tanggal_efektif' => now()->toDateString(),
            'alasan' => 'Alasan valid.',
        ])->assertOk();

        $this->assertTrue($employee->refresh()->isActive());
    }

    /** Kontrak permission-driven: FormRequest menerima role ber-permission employees.restore. */
    public function test_form_request_restore_menerima_pimpinan_yang_diberi_permission(): void
    {
        $role = Role::query()->where('name', 'pimpinan')->firstOrFail();
        $permission = Permission::query()->where('name', 'employees.restore')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $user = User::factory()->create(['role' => 'pimpinan']);
        $request = RestoreEmployeeRequest::create('/pegawai/restore', 'POST');
        $request->setUserResolver(static fn (): User => $user);

        $this->assertTrue($user->hasPermission('employees.restore'));
        $this->assertTrue($request->authorize());
    }

    /** Saat simulasi, permission tetap dievaluasi dari role efektif (konsisten kontrak RBAC). */
    public function test_form_request_restore_mengikuti_permission_role_efektif_saat_simulasi(): void
    {
        $role = Role::query()->where('name', 'pimpinan')->firstOrFail();
        $permission = Permission::query()->where('name', 'employees.restore')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $user = User::factory()->superAdmin()->create([
            'temporary_role' => 'pimpinan',
            'temporary_role_started_at' => now(),
        ]);
        $request = RestoreEmployeeRequest::create('/pegawai/restore', 'POST');
        $request->setUserResolver(static fn (): User => $user);

        $this->assertSame('pimpinan', $user->getEffectiveRole());
        $this->assertTrue($user->hasPermission('employees.restore'));
        $this->assertTrue($request->authorize());
    }

    public function test_restore_action_immediate_menerima_pimpinan_ber_permission(): void
    {
        $this->assertRestoreActionAcceptsPimpinan(now('Asia/Makassar')->toDateString());
    }

    public function test_restore_action_future_menjadwalkan_pimpinan_ber_permission(): void
    {
        $this->assertRestoreActionAcceptsPimpinan(now('Asia/Makassar')->addDay()->toDateString());
        $this->assertDatabaseCount('employee_status_transitions', 1);
    }

    public function test_restore_requires_tanggal_efektif_and_alasan(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create([
            'status_aktif' => 'Non-Aktif',
            'status_pegawai_id' => $this->nonaktifStatus()->id,
        ]);

        $this->actingAs($user);
        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/restore")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tanggal_efektif', 'alasan']);
    }

    public function test_bulk_destroy_and_bulk_restore_routes_are_removed(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        // Jalur massal dihapus dari scope: tidak ada lagi jalur produksi yang
        // memutasi status tanpa riwayat/tanggal efektif/alasan.
        $this->withSession(['_token' => 'test-token'])
            ->post('/pegawai/bulk-destroy', ['_token' => 'test-token', 'ids' => []])
            ->assertNotFound();

        $this->withSession(['_token' => 'test-token'])
            ->post('/pegawai/bulk-restore', ['_token' => 'test-token', 'ids' => []])
            ->assertNotFound();

        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $this->withSession(['_token' => 'test-token'])
            ->post('/pegawai/bulk-destroy', ['_token' => 'test-token', 'ids' => [$employee->id]])
            ->assertNotFound();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'status_aktif' => 'Aktif',
        ]);
    }

    public function test_api_employee_list_can_filter_nonaktif_by_status(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $nonaktifStatus = $this->nonaktifStatus();
        Employee::factory()->create(['nama_lengkap' => 'Pegawai Aktif Filter']);
        Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Nonaktif Filter',
            'status_aktif' => 'Non-Aktif',
            'status_pegawai_id' => $nonaktifStatus->id,
        ]);

        // Default daftar hanya menampilkan pegawai aktif.
        $this->actingAs($user)
            ->getJson('/api/v1/pegawai')
            ->assertOk()
            ->assertJsonMissing(['nama_lengkap' => 'Pegawai Nonaktif Filter'])
            ->assertJsonFragment(['nama_lengkap' => 'Pegawai Aktif Filter']);

        // Filter status menampilkan pegawai nonaktif.
        $this->getJson('/api/v1/pegawai?status_pegawai_id='.$nonaktifStatus->id)
            ->assertOk()
            ->assertJsonPath('employees.data.0.nama_lengkap', 'Pegawai Nonaktif Filter')
            ->assertJsonMissing(['nama_lengkap' => 'Pegawai Aktif Filter']);

        // Filter status_pegawai_id=all tetap hanya menampilkan pegawai aktif secara default.
        $this->getJson('/api/v1/pegawai?status_pegawai_id=all')
            ->assertOk()
            ->assertJsonFragment(['nama_lengkap' => 'Pegawai Aktif Filter'])
            ->assertJsonMissing(['nama_lengkap' => 'Pegawai Nonaktif Filter']);
    }

    public function test_cannot_deactivate_already_inactive_employee(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'status_aktif' => 'Non-Aktif',
            'status_pegawai_id' => $this->nonaktifStatus()->id,
        ]);

        $this->actingAs($user);
        $response = $this->deleteJsonWithCsrf("/api/v1/pegawai/{$employee->id}", [
            'tanggal_efektif' => now()->toDateString(),
            'alasan' => 'Alasan administrasi resmi.',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_deactivate_and_restore_requests_stay_fail_closed_without_local_bypass(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['services.simpeg.disable_employee_api_auth' => false]);

        $withoutFlag = [new DeactivateEmployeeRequest, new RestoreEmployeeRequest];

        foreach ($withoutFlag as $request) {
            $request->setUserResolver(fn () => null);
            $this->assertFalse($request->authorize());
        }

        // Flag hanya berlaku di environment local; production tetap menegakkan role dan permission.
        $this->app->detectEnvironment(fn () => 'production');
        config(['services.simpeg.disable_employee_api_auth' => true]);

        $inProduction = [new DeactivateEmployeeRequest, new RestoreEmployeeRequest];

        foreach ($inProduction as $request) {
            $request->setUserResolver(fn () => null);
            $this->assertFalse($request->authorize());
        }
    }

    public function test_restore_mengirim_notifikasi_ke_pegawai(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create([
            'status_aktif' => 'Non-Aktif',
            'status_pegawai_id' => $this->nonaktifStatus()->id,
        ]);

        $this->actingAs($user);
        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/restore", [
            'tanggal_efektif' => now()->toDateString(),
            'alasan' => 'Masa sanksi berakhir.',
        ])->assertOk();

        // Notifikasi in-app reaktivasi harus tercatat di database.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'status_pegawai.diubah',
        ]);
    }

    public function test_restore_notification_failure_tidak_membatalkan_reaktivasi(): void
    {
        // Pastikan kegagalan notifikasi (fire-and-forget) tidak me-rollback
        // perubahan status yang sudah tersimpan di transaksi utama.
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create([
            'status_aktif' => 'Non-Aktif',
            'status_pegawai_id' => $this->nonaktifStatus()->id,
        ]);

        $sentinel = 'ALASAN-RAHASIA-SQL bindings [pegawai@example.test]';

        // Paksa dependency eksternal gagal dengan payload sensitif untuk membuktikan
        // log best-effort hanya menyimpan metadata diagnosis yang diizinkan.
        $this->mock(NotificationService::class, function (MockInterface $mock) use ($sentinel): void {
            /** @var Expectation $expectation */
            $expectation = $mock->shouldReceive('createForEmployee');
            $expectation->once()->andThrow(new \RuntimeException($sentinel));
        });

        /** @var MockInterface&LoggerInterface $logSpy */
        $logSpy = Log::spy();

        $this->actingAs($user);
        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/restore", [
            'tanggal_efektif' => now()->toDateString(),
            'alasan' => 'Masa sanksi berakhir.',
        ])->assertOk();

        // Status tetap aktif meskipun notifikasi gagal.
        $this->assertTrue($employee->refresh()->isActive());
        /** @var Expectation $logExpectation */
        $logExpectation = $logSpy->shouldHaveReceived('error');
        $logExpectation->once()
            ->with(
                'Notification failed after employee status lifecycle',
                \Mockery::on(function (array $context) use ($employee, $sentinel): bool {
                    $serialized = json_encode($context, JSON_THROW_ON_ERROR);
                    $keys = array_keys($context);
                    sort($keys);

                    return ($context['employee_id'] ?? null) === $employee->id
                        && ($context['event'] ?? null) === 'status_pegawai.diubah'
                        && ($context['error_type'] ?? null) === \RuntimeException::class
                        && $keys === ['employee_id', 'error_type', 'event']
                        && ! str_contains($serialized, $sentinel);
                }),
            );
    }

    public function test_deactivate_notification_failure_tidak_membocorkan_payload_ke_log(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();
        $sentinel = 'NOTE-RAHASIA-SQL bindings [deactivate@example.test]';

        $this->mock(NotificationService::class, function (MockInterface $mock) use ($sentinel): void {
            /** @var Expectation $expectation */
            $expectation = $mock->shouldReceive('createForEmployee');
            $expectation->once()->andThrow(new \RuntimeException($sentinel));
        });
        /** @var MockInterface&LoggerInterface $logSpy */
        $logSpy = Log::spy();

        $this->actingAs($user)->deleteJsonWithCsrf("/api/v1/pegawai/{$employee->id}", [
            'tanggal_efektif' => now()->toDateString(),
            'alasan' => 'Pensiun administratif.',
            'status_note' => 'Catatan akun khusus.',
        ])->assertOk();

        $this->assertFalse($employee->refresh()->isActive());
        /** @var Expectation $logExpectation */
        $logExpectation = $logSpy->shouldHaveReceived('error');
        $logExpectation->once()
            ->with(
                'Notification failed after employee status lifecycle',
                \Mockery::on(function (array $context) use ($employee, $sentinel): bool {
                    $keys = array_keys($context);
                    sort($keys);

                    return ($context['employee_id'] ?? null) === $employee->id
                        && ($context['event'] ?? null) === 'status_pegawai.dinonaktifkan'
                        && ($context['error_type'] ?? null) === \RuntimeException::class
                        && $keys === ['employee_id', 'error_type', 'event']
                        && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $sentinel);
                }),
            );
    }

    public function test_deactivate_authorize_menggunakan_effective_role(): void
    {
        // Super Admin simulasi ke pegawai biasa TIDAK boleh menonaktifkan karena
        // role efektif pegawai tidak memiliki permission employees.deactivate.
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);

        // Aktifkan simulasi ke role pegawai (yang tidak punya employees.deactivate).
        $user->temporary_role = 'pegawai';
        $user->temporary_role_started_at = now();
        $user->save();

        $this->actingAs($user->refresh());
        $response = $this->deleteJsonWithCsrf("/api/v1/pegawai/{$employee->id}", [
            'tanggal_efektif' => now()->toDateString(),
            'alasan' => 'Alasan administrasi.',
        ]);

        // 403: effective role pegawai tidak punya employees.deactivate.
        $response->assertForbidden();
    }

    public function test_future_restore_menyimpan_transisi_terjadwal_tanpa_mengubah_snapshot(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create([
            'status_aktif' => 'Non-Aktif',
            'status_pegawai_id' => $this->nonaktifStatus()->id,
        ]);

        $effectiveDate = now('Asia/Makassar')->addDays(5)->toDateString();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/restore", [
            'tanggal_efektif' => $effectiveDate,
            'alasan' => 'Pemulihan terjadwal.',
        ]);

        $response->assertOk();
        $response->assertJsonPath(
            'message',
            "Pengaktifan kembali pegawai berhasil dijadwalkan untuk tanggal {$effectiveDate}.",
        );
        $response->assertJsonPath('status_transition.state', 'scheduled');
        $response->assertJsonPath('status_transition.effective_date', $effectiveDate);
        $response->assertJsonPath('employee.status_aktif', 'Non-Aktif');

        // Snapshot tetap nonaktif.
        $employee->refresh();
        $this->assertFalse($employee->isActive());

        // Transisi terjadwal tersimpan.
        $this->assertDatabaseHas('employee_status_transitions', [
            'employee_id' => $employee->id,
            'kind' => 'restore',
            'is_applied' => false,
        ]);
    }

    public function test_deactivate_rechecks_locked_state_instead_of_stale_model(): void
    {
        $user = User::factory()->superAdmin()->create();
        $mutasi = RefStatusPegawai::query()->where('kode', 'MUTASI')->firstOrFail();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $this->assertTrue($employee->isActive());

        Employee::query()->whereKey($employee->id)->update([
            'status_pegawai_id' => $mutasi->id,
            'status_aktif' => $mutasi->nama,
        ]);

        $request = Request::create('/internal/deactivate', 'DELETE', [
            'tanggal_efektif' => '2026-08-28',
            'alasan' => 'Request memakai model stale.',
        ]);
        $request->setUserResolver(static fn (): User => $user);
        $this->actingAs($user);

        try {
            app(DeactivateEmployeeAction::class)->execute($employee, $request);
            $this->fail('State nonaktif terbaru wajib ditolak setelah employee lock.');
        } catch (ValidationException) {
            // Expected: precondition lifecycle dibaca ulang dari row yang terkunci.
        }

        $this->assertSame($mutasi->id, $employee->refresh()->status_pegawai_id);
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertSame(0, AuditLog::query()->where('auditable_type', 'Employee')->count());
    }

    private function assertRestoreActionAcceptsPimpinan(string $effectiveDate): void
    {
        $role = Role::query()->where('name', 'pimpinan')->firstOrFail();
        $permission = Permission::query()->where('name', 'employees.restore')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $user = User::factory()->create(['role' => 'pimpinan']);
        $employee = Employee::factory()->create([
            'status_aktif' => 'Non-Aktif',
            'status_pegawai_id' => $this->nonaktifStatus()->id,
        ]);
        $request = Request::create('/pegawai/restore', 'POST', [
            'tanggal_efektif' => $effectiveDate,
            'alasan' => 'Reaktivasi oleh pimpinan ber-permission.',
        ], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'SIMPEG-Restore-Action-Test/1.0',
        ]);
        $request->setUserResolver(static fn (): User => $user);

        app(RestoreEmployeeAction::class)->execute($employee, $request);

        if ($effectiveDate <= now('Asia/Makassar')->toDateString()) {
            $this->assertTrue($employee->refresh()->isActive());
            $this->assertDatabaseHas('employee_status_histories', ['employee_id' => $employee->id]);
            $this->assertDatabaseHas('audit_logs', ['auditable_id' => $employee->id]);
        } else {
            // Tanggal efektif masa depan: dijadwalkan, belum diaplikasikan.
            $this->assertFalse($employee->refresh()->isActive());
            $this->assertDatabaseCount('employee_status_histories', 0);
        }
    }

    private function postJsonWithCsrf(string $uri, array $payload = []): TestResponse
    {
        return $this
            ->withSession(['_token' => 'test-token'])
            ->postJson($uri, array_merge(['_token' => 'test-token'], $payload));
    }

    private function deleteJsonWithCsrf(string $uri, array $payload = []): TestResponse
    {
        return $this
            ->withSession(['_token' => 'test-token'])
            ->deleteJson($uri, array_merge(['_token' => 'test-token'], $payload));
    }
}
