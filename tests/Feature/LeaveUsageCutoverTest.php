<?php

namespace Tests\Feature;

use App\Actions\Cuti\CorrectAnnualLeaveUsageAction;
use App\Actions\Cuti\ReconcileAnnualLeaveUsageAction;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageDocument;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\LeaveUsageRecord;
use App\Models\Permission;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\Role;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceReservationService;
use App\Services\Cuti\LeaveBalanceService;
use App\Services\Cuti\LeaveUsageReconciliationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveUsageCutoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-18 09:00:00');
        $this->seed(RbacSeeder::class);
        Storage::fake(LeaveUsageDocument::STORAGE_DISK);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_route_direct_write_legacy_tidak_terdaftar_dan_url_lama_tidak_dapat_dicapai(): void
    {
        $employee = Employee::factory()->create();

        $this->assertFalse(Route::has('cuti.saldo.opening-balance'));
        $this->assertFalse(Route::has('cuti.saldo.adjust'));
        $this->post("/dashboard/cuti/saldo/{$employee->id}/opening-balance")->assertNotFound();
        $this->post("/dashboard/cuti/saldo/{$employee->id}/adjust")->assertNotFound();
    }

    public function test_class_dan_method_public_direct_write_legacy_sudah_dihapus(): void
    {
        $this->assertFileDoesNotExist(app_path('Actions/Cuti/SetOpeningLeaveBalanceAction.php'));
        $this->assertFileDoesNotExist(app_path('Actions/Cuti/AdjustLeaveBalanceAction.php'));
        $this->assertFileDoesNotExist(app_path('Http/Requests/Cuti/OpeningLeaveBalanceRequest.php'));
        $this->assertFileDoesNotExist(app_path('Http/Requests/Cuti/AdjustLeaveBalanceRequest.php'));
        $this->assertFalse(method_exists(LeaveBalanceService::class, 'setOpeningBalance'));
        $this->assertFalse(method_exists(LeaveBalanceService::class, 'adjustBalance'));
        $this->assertFalse(method_exists(LeaveBalanceService::class, 'deductForFinalApproval'));
        $this->assertFalse(method_exists(LeaveBalanceService::class, 'rolloverYear'));
        $this->assertFalse(method_exists(LeaveBalanceService::class, 'rolloverLockedEmployee'));
    }

    public function test_permission_adjust_tetap_hilang_setelah_rbac_diseed_ulang(): void
    {
        $this->seed(RbacSeeder::class);

        $this->assertDatabaseMissing('permissions', ['name' => 'cuti.balance.adjust']);
    }

    public function test_migration_cutover_menghapus_permission_legacy_beserta_seluruh_pivot_upgrade(): void
    {
        $permission = Permission::query()->create([
            'name' => 'cuti.balance.adjust',
            'module' => 'cuti',
            'description' => 'Fixture permission direct-write lama.',
        ]);
        $roles = Role::query()->whereIn('name', ['admin_kepegawaian', 'super_admin'])->get();

        foreach ($roles as $role) {
            $role->permissions()->attach($permission);
        }

        $migration = require database_path('migrations/2026_08_18_000007_remove_legacy_leave_balance_adjust_permission.php');
        $migration->up();

        $this->assertDatabaseMissing('permissions', ['id' => $permission->id]);
        $this->assertDatabaseMissing('role_permissions', ['permission_id' => $permission->id]);

        $this->seed(RbacSeeder::class);
        $this->assertDatabaseMissing('permissions', ['name' => 'cuti.balance.adjust']);
    }

    public function test_halaman_administrasi_saldo_menerima_role_berhak_dengan_salah_satu_permission_workspace(): void
    {
        $this->get('/cuti/administrasi-saldo')->assertRedirect(route('login'));

        $reconcilePermission = Permission::query()->where('name', 'cuti.balance.reconcile')->sole();
        $manualPermission = Permission::query()->where('name', 'cuti.manual.manage')->sole();

        foreach (['kepala_bagian', 'pegawai'] as $role) {
            Role::query()->where('name', $role)->sole()->permissions()->syncWithoutDetaching([
                $reconcilePermission->id,
                $manualPermission->id,
            ]);
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/cuti/administrasi-saldo')
                ->assertForbidden();
        }

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/cuti/administrasi-saldo')
            ->assertOk()
            ->assertViewIs('admin.cuti.administrasi-saldo');

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get('/cuti/administrasi-saldo')
            ->assertForbidden();

        Role::query()->where('name', 'pimpinan')->sole()->permissions()->syncWithoutDetaching([$manualPermission->id]);
        $this->actingAs(User::factory()->pimpinan()->create())
            ->get('/cuti/administrasi-saldo')
            ->assertOk()
            ->assertViewIs('admin.cuti.administrasi-saldo');

        $adminRole = Role::query()->where('name', 'admin_kepegawaian')->sole();
        $adminRole->permissions()->detach([$reconcilePermission->id, $manualPermission->id]);

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get('/cuti/administrasi-saldo')
            ->assertForbidden();

        $adminRole->permissions()->attach($manualPermission);

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get('/cuti/administrasi-saldo')
            ->assertOk()
            ->assertViewIs('admin.cuti.administrasi-saldo');

        $adminRole->permissions()->syncWithoutDetaching([$reconcilePermission->id]);

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get('/cuti/administrasi-saldo')
            ->assertOk();
    }

    public function test_route_mutasi_rekonsiliasi_mengotorisasi_sebelum_lookup_uuid_target(): void
    {
        $employee = Employee::factory()->create();
        $fixtureActor = User::factory()->adminKepegawaian()->create();
        $existingSet = LeaveUsageReconciliationSet::query()->create([
            'employee_id' => $employee->id,
            'balance_year' => 2026,
            'reconciled_at' => '2026-08-18',
            'status' => LeaveUsageReconciliationSet::STATUS_ACTIVE,
            'administrative_note' => 'Fixture urutan otorisasi.',
            'recorded_by' => $fixtureActor->id,
        ]);
        $unknown = (string) Str::uuid();
        $requests = [
            [
                "/cuti/rekonsiliasi-tahunan/{$unknown}",
                "/cuti/rekonsiliasi-tahunan/{$employee->id}",
                fn (): array => $this->validPayload(),
            ],
            [
                "/cuti/rekonsiliasi-tahunan/{$unknown}/koreksi",
                "/cuti/rekonsiliasi-tahunan/{$existingSet->id}/koreksi",
                fn (): array => $this->validCorrectionPayload(),
            ],
        ];
        $before = $this->domainSnapshot($employee);

        foreach ($requests as [$unknownUrl, $existingUrl, $payload]) {
            $unknownResponse = $this->post($unknownUrl, $payload());
            $existingResponse = $this->post($existingUrl, $payload());
            $this->assertSame($existingResponse->getStatusCode(), $unknownResponse->getStatusCode());
            $unknownResponse->assertRedirect(route('login'));
            $existingResponse->assertRedirect(route('login'));
        }

        $permission = Permission::query()->where('name', 'cuti.balance.reconcile')->sole();

        foreach (['pimpinan', 'kepala_bagian', 'pegawai'] as $role) {
            Role::query()->where('name', $role)->sole()->permissions()->syncWithoutDetaching([$permission->id]);
            $actor = User::factory()->create(['role' => $role]);

            foreach ($requests as [$unknownUrl, $existingUrl, $payload]) {
                $unknownResponse = $this->actingAs($actor)->post($unknownUrl, $payload());
                $existingResponse = $this->actingAs($actor)->post($existingUrl, $payload());
                $this->assertSame($existingResponse->getStatusCode(), $unknownResponse->getStatusCode());
                $unknownResponse->assertForbidden();
                $existingResponse->assertForbidden();
            }
        }

        $adminRole = Role::query()->where('name', 'admin_kepegawaian')->sole();
        $adminRole->permissions()->detach($permission);
        $adminWithoutPermission = User::factory()->adminKepegawaian()->create();

        foreach ($requests as [$unknownUrl, $existingUrl, $payload]) {
            $unknownResponse = $this->actingAs($adminWithoutPermission)->post($unknownUrl, $payload());
            $existingResponse = $this->actingAs($adminWithoutPermission)->post($existingUrl, $payload());
            $this->assertSame($existingResponse->getStatusCode(), $unknownResponse->getStatusCode());
            $unknownResponse->assertForbidden();
            $existingResponse->assertForbidden();
        }

        $this->assertSame($before, $this->domainSnapshot($employee));
    }

    public function test_route_rekonsiliasi_menolak_parameter_malformed_dengan_404_untuk_admin_berwenang(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        // Constraint route menolak bentuk identifier sebelum middleware atau query database dijalankan.
        $this->post('/cuti/rekonsiliasi-tahunan/bukan-uuid', $this->validPayload())
            ->assertNotFound();
        $this->post('/cuti/rekonsiliasi-tahunan/bukan-uuid/koreksi', $this->validCorrectionPayload())
            ->assertNotFound();
        $this->actingAs($admin)
            ->post('/cuti/rekonsiliasi-tahunan/bukan-uuid', $this->validPayload())
            ->assertNotFound();
        $this->actingAs($admin)
            ->post('/cuti/rekonsiliasi-tahunan/bukan-uuid/koreksi', $this->validCorrectionPayload())
            ->assertNotFound();
    }

    public function test_action_rekonsiliasi_langsung_memakai_role_permission_sebelum_lookup_dan_storage(): void
    {
        $unknown = (string) Str::uuid();
        $permission = Permission::query()->where('name', 'cuti.balance.reconcile')->sole();

        foreach (['pimpinan', 'kepala_bagian', 'pegawai'] as $role) {
            Role::query()->where('name', $role)->sole()->permissions()->syncWithoutDetaching([$permission->id]);
            $this->assertReconciliationActionsReject(User::factory()->create(['role' => $role]), $unknown, $role);
        }

        Role::query()->where('name', 'admin_kepegawaian')->sole()->permissions()->detach($permission);
        $this->assertReconciliationActionsReject(
            User::factory()->adminKepegawaian()->create(),
            $unknown,
            'admin tanpa permission',
        );

        $this->assertDatabaseCount('leave_usage_reconciliation_sets', 0);
        $this->assertDatabaseCount('leave_usage_records', 0);
        $this->assertDatabaseCount('leave_balances', 0);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
        $this->assertDatabaseCount('leave_balance_reservation_events', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX));
    }

    public function test_rekonsiliasi_mewajibkan_n2_n1_current_dan_menerima_nilai_nol_eksplisit(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $this->annualType();

        foreach (['balance_year', 'usage_n2', 'usage_n1', 'usage_current', 'administrative_note'] as $field) {
            $payload = $this->validPayload();
            unset($payload[$field]);

            $this->actingAs($admin)
                ->post("/cuti/rekonsiliasi-tahunan/{$employee->id}", $payload)
                ->assertSessionHasErrors($field);
        }

        $this->actingAs($admin)
            ->post("/cuti/rekonsiliasi-tahunan/{$employee->id}", array_merge($this->validPayload(), [
                'reconciled_at' => '2000-01-01',
            ]))
            ->assertRedirect();

        $set = LeaveUsageReconciliationSet::query()->with('records')->sole();
        $this->assertSame('2026-08-18', $set->reconciled_at->toDateString());
        $this->assertSame(LeaveUsageReconciliationSet::STATUS_ACTIVE, $set->status);
        $this->assertSame([
            2024 => [
                'workdays' => 0,
                'effective_date' => '2024-12-31',
                'source_type' => LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION,
                'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            ],
            2025 => [
                'workdays' => 0,
                'effective_date' => '2025-12-31',
                'source_type' => LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION,
                'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            ],
            2026 => [
                'workdays' => 0,
                'effective_date' => '2026-08-18',
                'source_type' => LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION,
                'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            ],
        ], $set->records
            ->sortBy('usage_year')
            ->mapWithKeys(fn (LeaveUsageRecord $record): array => [
                $record->usage_year => [
                    'workdays' => $record->workdays,
                    'effective_date' => $record->effective_date->toDateString(),
                    'source_type' => $record->source_type,
                    'record_status' => $record->record_status,
                ],
            ])
            ->all());
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'event_type' => 'opening_balance_set',
        ]);
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'event_type' => 'manual_adjustment',
        ]);
    }

    public function test_tahun_rekonsiliasi_harus_tahun_server_asia_makassar_termasuk_batas_pergantian_tahun(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $this->annualType();

        foreach ([2025, 2027] as $invalidYear) {
            $this->actingAs($admin)
                ->post("/cuti/rekonsiliasi-tahunan/{$employee->id}", $this->validPayload([
                    'balance_year' => $invalidYear,
                ]))
                ->assertSessionHasErrors('balance_year');
        }

        Carbon::setTestNow(Carbon::parse('2026-12-31 16:30:00', 'UTC'));
        $this->assertSame(2027, now(config('app.timezone'))->year);

        try {
            app(ReconcileAnnualLeaveUsageAction::class)->execute(
                $employee->id,
                $this->validPayload(['balance_year' => 2026]),
                $admin,
                $this->requestFor($admin),
            );
            $this->fail('Action harus menolak tahun yang sudah historis pada batas waktu Makassar.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('balance_year', $exception->errors());
        }

        $response = $this->actingAs($admin)
            ->withSession(['last_activity_at' => now()->timestamp])
            ->post("/cuti/rekonsiliasi-tahunan/{$employee->id}", $this->validPayload([
                'balance_year' => 2026,
            ]));
        $response->assertSessionHasErrors('balance_year');

        $this->actingAs($admin)
            ->withSession(['last_activity_at' => now()->timestamp])
            ->post("/cuti/rekonsiliasi-tahunan/{$employee->id}", $this->validPayload([
                'balance_year' => 2027,
            ]))
            ->assertRedirect();

        $set = LeaveUsageReconciliationSet::query()->sole();
        $this->assertSame(2027, $set->balance_year);
        $this->assertSame('2027-01-01', $set->reconciled_at->toDateString());
    }

    /** Tahun baru WITA harus diterima action pencatatan walau timezone aplikasi masih UTC. */
    public function test_action_pencatatan_menerima_tahun_baru_wita_saat_timezone_aplikasi_utc(): void
    {
        $originalTimezones = $this->freezeUtcApplicationAtNewYearWita();

        try {
            $admin = User::factory()->adminKepegawaian()->create();
            $employee = $this->eligibleEmployee();
            $this->annualType();

            $set = app(ReconcileAnnualLeaveUsageAction::class)->execute(
                $employee->id,
                $this->validPayload(['balance_year' => 2026]),
                $admin,
                $this->requestFor($admin),
            );

            $this->assertSame(2026, $set->balance_year);
            $this->assertSame('2026-01-01', $set->reconciled_at->toDateString());
            $this->assertSame([2024, 2025, 2026], $set->records()->orderBy('usage_year')->pluck('usage_year')->all());
        } finally {
            $this->restoreTimezones($originalTimezones);
        }
    }

    /** Tahun baru WITA harus diterima action perbaikan beserta cutoff penggantinya. */
    public function test_action_perbaikan_menerima_tahun_baru_wita_saat_timezone_aplikasi_utc(): void
    {
        $originalTimezones = $this->freezeUtcApplicationAtNewYearWita();

        try {
            $admin = User::factory()->adminKepegawaian()->create();
            $employee = $this->eligibleEmployee();
            $this->annualType();
            $current = app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
                $employee,
                2026,
                [2024 => 0, 2025 => 0, 2026 => 0],
                Carbon::now('Asia/Makassar'),
                'Snapshot awal sebelum perbaikan tahun baru WITA.',
                $admin,
                $this->requestFor($admin),
            );

            $replacement = app(CorrectAnnualLeaveUsageAction::class)->execute(
                $current->id,
                $this->validCorrectionPayload([
                    'balance_year' => 2026,
                    'usage_current' => 1,
                ]),
                $this->validDocument('koreksi-tahun-baru-wita.pdf'),
                $admin,
                $this->requestFor($admin),
            );

            $this->assertSame(LeaveUsageReconciliationSet::STATUS_SUPERSEDED, $current->fresh()->status);
            $this->assertSame(LeaveUsageReconciliationSet::STATUS_ACTIVE, $replacement->status);
            $this->assertSame('2026-01-01', $replacement->reconciled_at->toDateString());
            $this->assertSame([0, 0, 1], $replacement->records()->orderBy('usage_year')->pluck('workdays')->all());
        } finally {
            $this->restoreTimezones($originalTimezones);
        }
    }

    public function test_action_koreksi_backdated_mempertahankan_tahun_set_dan_mereplay_hingga_tahun_aktual(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = $this->eligibleEmployee();
        $annual = $this->annualType();
        $current = app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            2026,
            [2024 => 0, 2025 => 0, 2026 => 0],
            Carbon::parse('2026-08-18 09:00:00', 'Asia/Makassar'),
            'Snapshot awal tahun saldo 2026.',
            $admin,
            $this->requestFor($admin),
        );
        $lateFact = LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $annual->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'usage_year' => 2026,
            'effective_date' => '2026-11-02',
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-03',
            'workdays' => 2,
            'administrative_note' => 'Fakta akhir tahun yang masuk koreksi backdated.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $admin->id,
        ]);
        $this->assertSame([
            2024 => [12, 0, 0, 12, 0, 0, 12, 0, 0],
            2025 => [12, 6, 0, 18, 0, 6, 12, 0, 6],
            2026 => [12, 12, 0, 24, 6, 6, 12, 0, 6],
        ], $this->balanceProjectionSnapshot($employee));
        $this->assertSame([
            'ledger_total' => 8,
            'balance_recalculated' => 3,
            'carry_over_expired' => 2,
            'usage_fact_recorded' => 3,
            'usage_fact_superseded' => 0,
            'balance_audit' => 3,
            'usage_fact_audit' => 3,
            'reconciliation_set_audit' => 1,
        ], $this->reconciliationEffectCounts($employee));
        Carbon::setTestNow('2027-02-03 10:00:00');

        $replacement = app(CorrectAnnualLeaveUsageAction::class)->execute(
            $current->id,
            $this->validCorrectionPayload([
                'balance_year' => 2026,
                'usage_n2' => 2,
                'usage_n1' => 3,
                'usage_current' => 4,
            ]),
            $this->validDocument('koreksi-backdated-2026.pdf'),
            $admin,
            $this->requestFor($admin),
        );

        $this->assertSame(LeaveUsageReconciliationSet::STATUS_SUPERSEDED, $current->fresh()->status);
        $this->assertSame(LeaveUsageReconciliationSet::STATUS_ACTIVE, $replacement->status);
        $this->assertSame(2026, $replacement->balance_year);
        $this->assertSame($current->id, $replacement->replaces_id);
        $this->assertSame('2027-02-03', $replacement->reconciled_at->toDateString());
        $this->assertSame([
            2024 => '2024-12-31',
            2025 => '2025-12-31',
            2026 => '2026-12-31',
        ], $replacement->records()
            ->orderBy('usage_year')
            ->get()
            ->mapWithKeys(fn (LeaveUsageRecord $record): array => [
                $record->usage_year => $record->effective_date->toDateString(),
            ])
            ->all());
        $this->assertDatabaseHas('leave_usage_reconciliation_memberships', [
            'reconciliation_set_id' => $replacement->id,
            'itemized_usage_record_id' => $lateFact->id,
            'included_workdays' => 2,
        ]);
        $projection2027 = LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2027)
            ->sole();
        $this->assertSame(12, $projection2027->jatah_awal);
        $this->assertSame(0, $projection2027->terpakai);
        $this->assertSame([
            2024 => [12, 0, 2, 10, 0, 0, 10, 2, 0],
            2025 => [12, 6, 3, 15, 0, 3, 12, 0, 4],
            2026 => [12, 6, 4, 14, 0, 2, 12, 0, 9],
            2027 => [12, 6, 0, 18, 0, 6, 12, 0, 8],
        ], $this->balanceProjectionSnapshot($employee));
        $this->assertSame([
            'ledger_total' => 21,
            'balance_recalculated' => 7,
            'carry_over_expired' => 5,
            'usage_fact_recorded' => 6,
            'usage_fact_superseded' => 3,
            'balance_audit' => 7,
            'usage_fact_audit' => 9,
            'reconciliation_set_audit' => 2,
        ], $this->reconciliationEffectCounts($employee));
    }

    public function test_http_koreksi_backdated_menerima_tahun_set_tetapi_menolak_mismatch_dan_tahun_masa_depan(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = $this->eligibleEmployee();
        $this->annualType();
        $current = app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            2026,
            [2024 => 0, 2025 => 0, 2026 => 0],
            Carbon::parse('2026-08-18 09:00:00', 'Asia/Makassar'),
            'Snapshot awal route koreksi backdated.',
            $admin,
            $this->requestFor($admin),
        );
        Carbon::setTestNow('2027-02-03 10:00:00');

        foreach ([2025, 2028] as $invalidYear) {
            $this->actingAs($admin)
                ->withSession(['last_activity_at' => now()->timestamp])
                ->post(
                    "/cuti/rekonsiliasi-tahunan/{$current->id}/koreksi",
                    $this->validCorrectionPayload(['balance_year' => $invalidYear]),
                )
                ->assertSessionHasErrors('balance_year');
        }

        $this->actingAs($admin)
            ->withSession(['last_activity_at' => now()->timestamp])
            ->post(
                "/cuti/rekonsiliasi-tahunan/{$current->id}/koreksi",
                $this->validCorrectionPayload([
                    'balance_year' => 2026,
                    'usage_current' => 1,
                ]),
            )
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $replacement = LeaveUsageReconciliationSet::query()
            ->whereBelongsTo($employee)
            ->where('status', LeaveUsageReconciliationSet::STATUS_ACTIVE)
            ->sole();
        $this->assertSame(2026, $replacement->balance_year);
        $this->assertSame('2027-02-03', $replacement->reconciled_at->toDateString());
    }

    /** Route pencatatan dan perbaikan harus memakai kontrak tahun WITA yang sama dengan action. */
    public function test_http_pencatatan_dan_perbaikan_memakai_tahun_baru_wita_saat_timezone_aplikasi_utc(): void
    {
        $originalTimezones = $this->freezeUtcApplicationAtNewYearWita();

        try {
            $admin = User::factory()->adminKepegawaian()->create();
            $employee = $this->eligibleEmployee();
            $this->annualType();

            $this->actingAs($admin)
                ->withSession(['last_activity_at' => now()->timestamp])
                ->post("/cuti/rekonsiliasi-tahunan/{$employee->id}", $this->validPayload([
                    'balance_year' => 2026,
                ]))
                ->assertSessionHasNoErrors()
                ->assertRedirect();

            $current = LeaveUsageReconciliationSet::query()
                ->where('employee_id', $employee->id)
                ->where('status', LeaveUsageReconciliationSet::STATUS_ACTIVE)
                ->sole();

            $this->actingAs($admin)
                ->withSession(['last_activity_at' => now()->timestamp])
                ->post(
                    "/cuti/rekonsiliasi-tahunan/{$current->id}/koreksi",
                    $this->validCorrectionPayload([
                        'balance_year' => 2026,
                        'usage_current' => 2,
                    ]),
                )
                ->assertSessionHasNoErrors()
                ->assertRedirect();

            $replacement = LeaveUsageReconciliationSet::query()
                ->where('employee_id', $employee->id)
                ->where('status', LeaveUsageReconciliationSet::STATUS_ACTIVE)
                ->sole();
            $this->assertSame(LeaveUsageReconciliationSet::STATUS_SUPERSEDED, $current->fresh()->status);
            $this->assertSame($current->id, $replacement->replaces_id);
            $this->assertSame('2026-01-01', $replacement->reconciled_at->toDateString());
        } finally {
            $this->restoreTimezones($originalTimezones);
        }
    }

    /** Audit replacement harus membawa seluruh projection sampai tahun bisnis WITA aktif. */
    public function test_audit_perbaikan_memuat_projection_tahun_baru_wita_saat_timezone_aplikasi_utc(): void
    {
        $originalTimezones = $this->freezeUtcApplicationAtNewYearWita();

        try {
            $admin = User::factory()->adminKepegawaian()->create();
            $employee = $this->eligibleEmployee();
            $this->annualType();
            $service = app(LeaveUsageReconciliationService::class);
            $current = $service->createAnnualReconciliationSet(
                $employee,
                2026,
                [2024 => 0, 2025 => 0, 2026 => 0],
                Carbon::now('Asia/Makassar'),
                'Snapshot awal untuk audit tahun baru WITA.',
                $admin,
                $this->requestFor($admin),
            );
            $replacement = $service->replaceAnnualReconciliationSet(
                $current,
                [2024 => 0, 2025 => 0, 2026 => 1],
                Carbon::now('Asia/Makassar'),
                'Snapshot pengganti untuk audit tahun baru WITA.',
                'Perbaikan audit pada batas pergantian tahun WITA.',
                $admin,
                $this->requestFor($admin),
            );

            $audit = AuditLog::query()
                ->where('auditable_type', 'LeaveUsageReconciliationSet')
                ->where('auditable_id', $replacement->id)
                ->where('event', 'UPDATE')
                ->sole();

            $this->assertSame(
                [2024, 2025, 2026],
                collect($audit->new_values['projection_before'])->pluck('tahun')->all(),
            );
            $this->assertSame(
                [2024, 2025, 2026],
                collect($audit->new_values['projection_after'])->pluck('tahun')->all(),
            );
        } finally {
            $this->restoreTimezones($originalTimezones);
        }
    }

    public function test_rekonsiliasi_membekukan_fact_sampai_cutoff_tanpa_double_count_projection(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = $this->eligibleEmployee();
        $annual = $this->annualType();
        $fact = LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $annual->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'usage_year' => 2026,
            'effective_date' => '2026-08-01',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-03',
            'workdays' => 2,
            'administrative_note' => 'Fakta itemized sebelum cutoff.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $admin->id,
        ]);
        $this->attachValidManualApprovalSnapshot($fact);

        $set = app(ReconcileAnnualLeaveUsageAction::class)->execute(
            $employee->id,
            $this->validPayload(['usage_current' => 2]),
            $admin,
            $this->requestFor($admin),
        );

        $this->assertDatabaseHas('leave_usage_reconciliation_memberships', [
            'reconciliation_set_id' => $set->id,
            'annual_reconciliation_record_id' => $set->records()->where('usage_year', 2026)->sole()->id,
            'itemized_usage_record_id' => $fact->id,
            'included_workdays' => 2,
        ]);
        $this->assertSame(2, LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2026)
            ->sole()
            ->terpakai);
    }

    public function test_koreksi_rekonsiliasi_mengganti_seluruh_set_dengan_satu_dokumen_privat_dan_audit_metadata(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = $this->eligibleEmployee();
        $this->annualType();
        $current = app(ReconcileAnnualLeaveUsageAction::class)->execute(
            $employee->id,
            $this->validPayload(['usage_n2' => 1, 'usage_n1' => 2, 'usage_current' => 3]),
            $admin,
            $this->requestFor($admin),
        );

        $replacement = app(CorrectAnnualLeaveUsageAction::class)->execute(
            $current->id,
            $this->validCorrectionPayload([
                'usage_n2' => 0,
                'usage_n1' => 1,
                'usage_current' => 4,
            ]),
            $this->validDocument('koreksi-rekonsiliasi.pdf'),
            $admin,
            $this->requestFor($admin),
        );

        $this->assertSame(LeaveUsageReconciliationSet::STATUS_SUPERSEDED, $current->fresh()->status);
        $this->assertSame($current->id, $replacement->replaces_id);
        $this->assertSame('Koreksi total berdasarkan rekap final.', $replacement->correction_reason);
        $this->assertSame(1, LeaveUsageReconciliationSet::query()->where('status', 'active')->count());
        $this->assertSame(3, $replacement->records()->where('record_status', 'active')->count());
        $this->assertSame(3, $current->records()->where('record_status', 'superseded')->count());
        $previousByYear = $current->records()->orderBy('usage_year')->get()->keyBy('usage_year');
        $this->assertSame([
            2024 => [
                'workdays' => 0,
                'source_type' => LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION,
                'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
                'replaces_id' => $previousByYear[2024]->id,
                'correction_reason' => 'Koreksi total berdasarkan rekap final.',
            ],
            2025 => [
                'workdays' => 1,
                'source_type' => LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION,
                'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
                'replaces_id' => $previousByYear[2025]->id,
                'correction_reason' => 'Koreksi total berdasarkan rekap final.',
            ],
            2026 => [
                'workdays' => 4,
                'source_type' => LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION,
                'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
                'replaces_id' => $previousByYear[2026]->id,
                'correction_reason' => 'Koreksi total berdasarkan rekap final.',
            ],
        ], $replacement->records()
            ->orderBy('usage_year')
            ->get()
            ->mapWithKeys(fn (LeaveUsageRecord $record): array => [
                $record->usage_year => [
                    'workdays' => $record->workdays,
                    'source_type' => $record->source_type,
                    'record_status' => $record->record_status,
                    'replaces_id' => $record->replaces_id,
                    'correction_reason' => $record->correction_reason,
                ],
            ])->all());
        $this->assertSame([
            2024 => 0,
            2025 => 1,
            2026 => 4,
        ], LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->orderBy('tahun')
            ->pluck('terpakai', 'tahun')
            ->all());
        $this->assertSame(3, LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_USAGE_FACT_SUPERSEDED)
            ->count());
        $this->assertGreaterThanOrEqual(2, LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->count());
        $document = LeaveUsageDocument::query()->sole();
        $this->assertNull($document->leave_usage_record_id);
        $this->assertSame($replacement->id, $document->leave_usage_reconciliation_set_id);
        $this->assertTrue(Storage::disk($document->disk)->exists($document->path));

        $audit = AuditLog::query()
            ->where('auditable_type', 'LeaveUsageReconciliationSet')
            ->where('auditable_id', $replacement->id)
            ->where('event', 'UPDATE')
            ->sole();
        $this->assertSame('koreksi-rekonsiliasi.pdf', $audit->new_values['document']['original_name']);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('admin_kepegawaian', $audit->new_values['actor_role']);
        $this->assertSame('Koreksi total berdasarkan rekap final.', $audit->new_values['reason']);
        $this->assertSame('198.51.100.10', $audit->ip_address);
        $this->assertSame('SIMPEG-Cutover-Test/1.0', $audit->user_agent);
        $this->assertNotEmpty($audit->new_values['projection_before']);
        $this->assertNotEmpty($audit->new_values['projection_after']);
        $this->assertArrayNotHasKey('path', $audit->new_values['document']);
        $this->assertArrayNotHasKey('disk', $audit->new_values['document']);
        $this->assertArrayNotHasKey('stored_name', $audit->new_values['document']);
    }

    public function test_http_koreksi_memvalidasi_alasan_dokumen_dan_tiga_total_lalu_mengganti_set(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = $this->eligibleEmployee();
        $this->annualType();
        $current = app(ReconcileAnnualLeaveUsageAction::class)->execute(
            $employee->id,
            $this->validPayload(),
            $admin,
            $this->requestFor($admin),
        );

        foreach (['correction_reason', 'dokumen', 'usage_n2', 'usage_n1', 'usage_current'] as $field) {
            $payload = $this->validCorrectionPayload();
            unset($payload[$field]);

            $this->actingAs($admin)
                ->post("/cuti/rekonsiliasi-tahunan/{$current->id}/koreksi", $payload)
                ->assertSessionHasErrors($field);
        }

        $response = $this->actingAs($admin)
            ->post("/cuti/rekonsiliasi-tahunan/{$current->id}/koreksi", $this->validCorrectionPayload([
                'usage_n2' => 0,
                'usage_n1' => 1,
                'usage_current' => 4,
            ]));

        $response->assertRedirect();
        $replacement = LeaveUsageReconciliationSet::query()
            ->where('status', LeaveUsageReconciliationSet::STATUS_ACTIVE)
            ->sole();
        $this->assertSame($current->id, $replacement->replaces_id);
        $this->assertSame([0, 1, 4], $replacement->records()->orderBy('usage_year')->pluck('workdays')->all());
        $this->assertSame(1, $replacement->documents()->count());
    }

    public function test_kegagalan_audit_koreksi_merollback_set_fact_projection_ledger_dokumen_dan_file_baru(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = $this->eligibleEmployee();
        $this->annualType();
        $current = app(ReconcileAnnualLeaveUsageAction::class)->execute(
            $employee->id,
            $this->validPayload(),
            $admin,
            $this->requestFor($admin),
        );
        $before = $this->domainSnapshot($employee);
        $dispatcher = clone AuditLog::getEventDispatcher();
        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->auditable_type === 'LeaveUsageReconciliationSet' && $audit->event === 'UPDATE') {
                throw new \RuntimeException('Simulasi audit koreksi rekonsiliasi gagal.');
            }
        });

        try {
            app(CorrectAnnualLeaveUsageAction::class)->execute(
                $current->id,
                $this->validCorrectionPayload(['usage_current' => 1]),
                $this->validDocument('gagal-audit.pdf'),
                $admin,
                $this->requestFor($admin),
            );
            $this->fail('Kegagalan audit wajib membatalkan koreksi rekonsiliasi.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi audit koreksi rekonsiliasi gagal.', $exception->getMessage());
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertSame($before, $this->domainSnapshot($employee));
        $this->assertSame(LeaveUsageReconciliationSet::STATUS_ACTIVE, $current->fresh()->status);
        $this->assertDatabaseCount('leave_usage_documents', 0);
        $this->assertSame([], Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX));
    }

    public function test_read_saldo_tanpa_projection_fail_closed_dan_tidak_membuat_row_atau_ledger(): void
    {
        $employee = $this->eligibleEmployee();

        $this->assertSame(0, app(LeaveBalanceService::class)->availableFor($employee, 2026, Carbon::parse('2026-08-18')));
        $this->assertDatabaseCount('leave_balances', 0);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
    }

    public function test_preview_tanpa_projection_tidak_mengarang_hak_dua_belas_atau_koreksi_legacy(): void
    {
        $employee = $this->eligibleEmployee();
        LeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 0,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 0,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);

        $preview = app(LeaveBalanceService::class)->previewFor($employee, Carbon::parse('2026-08-18'));

        $this->assertSame(0, $preview['jatah_dasar']);
        $this->assertSame(0, $preview['saldo_aktual']);
        $this->assertSame(0, $preview['saldo_dapat_diajukan']);
        $this->assertSame(['n2' => 0, 'n1' => 0, 'current' => 0], $preview['bucket']);
        $this->assertArrayNotHasKey('koreksi_administratif', $preview);
        $this->assertDatabaseCount('leave_balances', 1);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
    }

    public function test_form_pengajuan_tidak_merender_key_atau_baris_koreksi_administratif_legacy(): void
    {
        $employee = $this->eligibleEmployee();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->actingAs($user)
            ->get(route('cuti.create'))
            ->assertOk()
            ->assertDontSee('koreksi_administratif', false)
            ->assertDontSee('Koreksi Administratif');
    }

    public function test_sidebar_administrasi_pemakaian_hanya_terlihat_saat_permission_tersedia(): void
    {
        $reconcilePermission = Permission::query()->where('name', 'cuti.balance.reconcile')->sole();
        $manualPermission = Permission::query()->where('name', 'cuti.manual.manage')->sole();
        $adminRole = Role::query()->where('name', 'admin_kepegawaian')->sole();
        $superRole = Role::query()->where('name', 'super_admin')->sole();
        $administrationUrl = route('cuti.saldo.administrasi');
        $superRole->permissions()->syncWithoutDetaching([
            $reconcilePermission->id,
            $manualPermission->id,
        ]);

        // Menu tanpa akses tetap terlihat dalam keadaan disabled; tautan tidak dirender.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Administrasi Pemakaian Cuti')
            ->assertSee('href="'.$administrationUrl.'"', false);

        $adminRole->permissions()->detach([$reconcilePermission->id, $manualPermission->id]);
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Administrasi Pemakaian Cuti')
            ->assertSee('aria-disabled="true"', false)
            ->assertDontSee('href="'.$administrationUrl.'"', false);

        $adminRole->permissions()->attach($manualPermission);
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Administrasi Pemakaian Cuti')
            ->assertSee('href="'.$administrationUrl.'"', false);

        $adminRole->permissions()->detach($manualPermission);
        $adminRole->permissions()->attach($reconcilePermission);
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Administrasi Pemakaian Cuti')
            ->assertSee('href="'.$administrationUrl.'"', false);
    }

    public function test_tautan_administrasi_saldo_di_rekap_mengikuti_permission_dan_role_yang_berhak(): void
    {
        $employee = Employee::factory()->create();
        LeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'sisa_tahun_berjalan' => 12,
            'sisa' => 12,
        ]);
        $reconcilePermission = Permission::query()->where('name', 'cuti.balance.reconcile')->sole();
        $manualPermission = Permission::query()->where('name', 'cuti.manual.manage')->sole();
        $adminRole = Role::query()->where('name', 'admin_kepegawaian')->sole();
        $superRole = Role::query()->where('name', 'super_admin')->sole();
        $pimpinanRole = Role::query()->where('name', 'pimpinan')->sole();
        $administrationUrl = route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'periode' => 2026,
        ]);

        $superRole->permissions()->syncWithoutDetaching([
            $reconcilePermission->id,
            $manualPermission->id,
        ]);
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('cuti.rekap'))
            ->assertOk()
            ->assertSee($administrationUrl);

        $pimpinanRole->permissions()->syncWithoutDetaching([
            $reconcilePermission->id,
            $manualPermission->id,
        ]);
        $this->actingAs(User::factory()->create(['role' => 'pimpinan']))
            ->get(route('cuti.rekap'))
            ->assertOk()
            ->assertSee($administrationUrl);

        $adminRole->permissions()->detach([$reconcilePermission->id, $manualPermission->id]);
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('cuti.rekap'))
            ->assertOk()
            ->assertDontSee($administrationUrl);

        $adminRole->permissions()->attach($manualPermission);
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('cuti.rekap'))
            ->assertOk()
            ->assertSee($administrationUrl);

        $adminRole->permissions()->detach($manualPermission);
        $adminRole->permissions()->attach($reconcilePermission);
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('cuti.rekap'))
            ->assertOk()
            ->assertSee($administrationUrl);
    }

    public function test_model_dan_database_menolak_event_direct_write_legacy(): void
    {
        $employee = Employee::factory()->create();
        $legacyEvents = ['leave_deducted', 'manual_adjustment', 'opening_balance_set'];

        foreach ($legacyEvents as $event) {
            $this->assertNotContains($event, LeaveBalanceLedger::eventTypes());

            try {
                LeaveBalanceLedger::query()->create([
                    'employee_id' => $employee->id,
                    'tahun' => 2026,
                    'event_type' => $event,
                    'amount' => 1,
                ]);
                $this->fail("Model seharusnya menolak event legacy {$event}.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }

            try {
                DB::transaction(function () use ($employee, $event): void {
                    DB::table('leave_balance_ledger')->insert([
                        'id' => (string) Str::uuid(),
                        'employee_id' => $employee->id,
                        'tahun' => 2026,
                        'event_type' => $event,
                        'amount' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
                $this->fail("Constraint database seharusnya menolak event legacy {$event}.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseCount('leave_balance_ledger', 0);
    }

    public function test_reservasi_tanpa_projection_ditolak_tanpa_membuat_row_ledger_atau_event(): void
    {
        $employee = $this->eligibleEmployee();
        $annual = $this->annualType();
        $request = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $annual->id,
            'tanggal_mulai' => '2026-08-20',
            'tanggal_selesai' => '2026-08-20',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Pengajuan tanpa projection.',
            'status' => 'menunggu_approval',
        ]);

        try {
            app(LeaveBalanceReservationService::class)->reserveForNewRequest($request);
            $this->fail('Reservasi tanpa projection seharusnya ditolak fail-closed.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
            $this->assertStringContainsString('Saldo cuti', implode(' ', $exception->errors()['tanggal_selesai'] ?? []));
        }

        $this->assertDatabaseCount('leave_balances', 0);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
        $this->assertDatabaseCount('leave_balance_reservation_events', 0);
    }

    private function assertReconciliationActionsReject(User $actor, string $unknown, string $label): void
    {
        $before = [
            'balances' => LeaveBalance::query()->count(),
            'ledger' => LeaveBalanceLedger::query()->count(),
            'reservations' => LeaveBalanceReservationEvent::query()->count(),
            'audit' => AuditLog::query()->count(),
        ];

        try {
            app(ReconcileAnnualLeaveUsageAction::class)->execute(
                'uuid-tidak-valid',
                [],
                $actor,
                $this->requestFor($actor),
            );
            $this->fail("Create reconciliation seharusnya menolak {$label}.");
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        try {
            app(CorrectAnnualLeaveUsageAction::class)->execute(
                'uuid-tidak-valid',
                [],
                UploadedFile::fake()->create("auth-{$actor->role}.exe", 20, 'application/octet-stream'),
                $actor,
                $this->requestFor($actor),
            );
            $this->fail("Correction reconciliation seharusnya menolak {$label}.");
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($before, [
            'balances' => LeaveBalance::query()->count(),
            'ledger' => LeaveBalanceLedger::query()->count(),
            'reservations' => LeaveBalanceReservationEvent::query()->count(),
            'audit' => AuditLog::query()->count(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'balance_year' => 2026,
            'usage_n2' => 0,
            'usage_n1' => 0,
            'usage_current' => 0,
            'administrative_note' => 'Rekonsiliasi pemakaian tahunan.',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function validCorrectionPayload(array $overrides = []): array
    {
        return array_merge($this->validPayload(), [
            'administrative_note' => 'Rekonsiliasi setelah koreksi.',
            'correction_reason' => 'Koreksi total berdasarkan rekap final.',
            'dokumen' => $this->validDocument('koreksi.pdf'),
        ], $overrides);
    }

    private function validDocument(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 20, 'application/pdf');
    }

    private function requestFor(User $actor): Request
    {
        $request = Request::create('/cuti/rekonsiliasi-tahunan', 'POST', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_USER_AGENT' => 'SIMPEG-Cutover-Test/1.0',
        ]);
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }

    private function annualType(): RefJenisCuti
    {
        return RefJenisCuti::query()->firstOrCreate(
            ['code' => 'tahunan'],
            [
                'nama' => 'Cuti Tahunan',
                'mengurangi_saldo_tahunan' => true,
                'khusus_pns' => false,
            ],
        );
    }

    private function eligibleEmployee(): Employee
    {
        $employee = Employee::factory()->create([
            // Fixture rekonsiliasi memakai PNS agar ceiling 24 tidak bergantung random factory PPPK.
            'jenis_pegawai_id' => RefJenisPegawai::query()->firstOrCreate(['nama' => 'PNS'])->id,
        ]);
        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-CUTOVER-001',
            'tanggal_sk' => '2020-01-01',
        ]);

        return $employee;
    }

    /** @return array{app:string,php:string} */
    private function freezeUtcApplicationAtNewYearWita(): array
    {
        $originalTimezones = [
            'app' => (string) config('app.timezone'),
            'php' => date_default_timezone_get(),
        ];

        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::create(2025, 12, 31, 16, 15, 0, 'UTC'));

        return $originalTimezones;
    }

    /** @param array{app:string,php:string} $timezones */
    private function restoreTimezones(array $timezones): void
    {
        config(['app.timezone' => $timezones['app']]);
        date_default_timezone_set($timezones['php']);
    }

    /** @return array<string, mixed> */
    private function domainSnapshot(Employee $employee): array
    {
        return [
            'sets' => LeaveUsageReconciliationSet::query()->where('employee_id', $employee->id)->orderBy('id')->get()->toArray(),
            'facts' => LeaveUsageRecord::query()->where('employee_id', $employee->id)->orderBy('id')->get()->toArray(),
            'memberships' => DB::table('leave_usage_reconciliation_memberships')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'balances' => LeaveBalance::query()->where('employee_id', $employee->id)->orderBy('tahun')->get()->toArray(),
            'ledger' => LeaveBalanceLedger::query()->where('employee_id', $employee->id)->orderBy('id')->get()->toArray(),
            'audit' => AuditLog::query()->orderBy('id')->get()->toArray(),
            'documents' => LeaveUsageDocument::query()->orderBy('id')->get()->toArray(),
            'reservations' => LeaveBalanceReservationEvent::query()->where('employee_id', $employee->id)->orderBy('id')->get()->toArray(),
        ];
    }

    /**
     * @return array<int, array{0:int,1:int,2:int,3:int,4:int,5:int,6:int,7:int,8:int}>
     */
    private function balanceProjectionSnapshot(Employee $employee): array
    {
        return LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->orderBy('tahun')
            ->get()
            ->mapWithKeys(fn (LeaveBalance $balance): array => [
                $balance->tahun => [
                    (int) $balance->jatah_awal,
                    (int) $balance->carry_over,
                    (int) $balance->terpakai,
                    (int) $balance->sisa,
                    (int) $balance->sisa_n2,
                    (int) $balance->sisa_n1,
                    (int) $balance->sisa_tahun_berjalan,
                    (int) $balance->terpakai_tahun_berjalan,
                    (int) $balance->hangus,
                ],
            ])
            ->all();
    }

    /** @return array<string, int> */
    private function reconciliationEffectCounts(Employee $employee): array
    {
        $balanceIds = LeaveBalance::query()->whereBelongsTo($employee)->pluck('id');
        $factIds = LeaveUsageRecord::query()->whereBelongsTo($employee)->pluck('id');
        $setIds = LeaveUsageReconciliationSet::query()->whereBelongsTo($employee)->pluck('id');
        $ledger = LeaveBalanceLedger::query()->where('employee_id', $employee->id);

        return [
            'ledger_total' => (clone $ledger)->count(),
            'balance_recalculated' => (clone $ledger)
                ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
                ->count(),
            'carry_over_expired' => (clone $ledger)
                ->where('event_type', LeaveBalanceLedger::EVENT_CARRY_OVER_EXPIRED)
                ->count(),
            'usage_fact_recorded' => (clone $ledger)
                ->where('event_type', LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED)
                ->count(),
            'usage_fact_superseded' => (clone $ledger)
                ->where('event_type', LeaveBalanceLedger::EVENT_USAGE_FACT_SUPERSEDED)
                ->count(),
            'balance_audit' => AuditLog::query()
                ->where('auditable_type', 'LeaveBalance')
                ->whereIn('auditable_id', $balanceIds)
                ->count(),
            'usage_fact_audit' => AuditLog::query()
                ->where('auditable_type', 'LeaveUsageRecord')
                ->whereIn('auditable_id', $factIds)
                ->count(),
            'reconciliation_set_audit' => AuditLog::query()
                ->where('auditable_type', 'LeaveUsageReconciliationSet')
                ->whereIn('auditable_id', $setIds)
                ->count(),
        ];
    }
}
