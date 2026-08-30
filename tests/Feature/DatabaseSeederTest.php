<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageExternalApprovalStep;
use App\Models\LeaveUsageReconciliationMembership;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\LeaveUsageRecord;
use App\Models\User;
use App\Queries\Cuti\CurrentApprovalChainPreviewQuery;
use App\Services\Cuti\LeaveUsageReconciliationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PhaseSevenBrowserQaSeeder;
use Database\Seeders\SsoRoleMappedAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_expected_approval_and_mapped_users(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Jumlah user bervariasi tergantung seeders yang aktif (demo + mapping).
        // Cek keberadaan user penting, bukan hitungan eksak.
        $this->assertDatabaseHas('users', [
            'email' => 'merlina.rahman@example.com',
            'role' => 'admin_kepegawaian',
        ]);

        foreach (SsoRoleMappedAccountSeeder::ROLE_MAPPING as $email => $role) {
            $this->assertDatabaseHas('users', [
                'email' => $email,
                'role' => $role,
            ]);
        }

        // Jika demo_users masih ada (gabungan), pastikan juga ter-seed.
        foreach ((array) config('services.keycloak.demo_users', []) as $demoUser) {
            $this->assertDatabaseHas('users', [
                'keycloak_username' => $demoUser['username'],
                'role' => $demoUser['role'],
            ]);
        }
    }

    public function test_database_seeder_creates_sso_role_mapped_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (SsoRoleMappedAccountSeeder::ROLE_MAPPING as $email => $role) {
            $this->assertDatabaseHas('users', [
                'email' => $email,
                'role' => $role,
            ]);
            $this->assertDatabaseHas('employees', [
                'email' => $email,
                'status_aktif' => 'Aktif',
            ]);
        }
    }

    public function test_seeder_does_not_reactivate_existing_employee(): void
    {
        $email = collect(SsoRoleMappedAccountSeeder::ROLE_MAPPING)->keys()->first();

        // Pegawai existing berstatus Pensiun — seeder ulang tidak boleh
        // menghidupkannya kembali hanya agar login SSO lulus.
        $employee = Employee::factory()->create([
            'email' => $email,
            'status_aktif' => 'Pensiun',
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'status_aktif' => 'Pensiun',
        ]);
    }

    public function test_seeder_does_not_move_user_to_another_employee(): void
    {
        $email = collect(SsoRoleMappedAccountSeeder::ROLE_MAPPING)->keys()->first();

        $pegawaiAsal = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Asal',
        ]);

        // User sudah terhubung ke pegawai asal; seeder yang menemukan placeholder
        // baru untuk email yang sama tidak boleh memindahkan akun ke pegawai itu.
        User::factory()->create([
            'email' => $email,
            'employee_id' => $pegawaiAsal->id,
            'role' => 'pegawai',
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => $email,
            'employee_id' => $pegawaiAsal->id,
            'role' => 'pegawai',
        ]);
    }

    public function test_seeder_preserves_existing_user_name_on_reseed(): void
    {
        $email = collect(SsoRoleMappedAccountSeeder::ROLE_MAPPING)->keys()->first();
        $customName = 'Nama Kustom Yang Sudah Ada';

        // User existing dengan nama yang sudah ditetapkan (bukan derived dari email).
        User::factory()->create([
            'email' => $email,
            'name' => $customName,
            'role' => 'pegawai',
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => $email,
            'name' => $customName,
        ]);
        $this->assertSame(
            $customName,
            User::where('email', $email)->first()->name,
            'Seeder ulang tidak boleh menimpa nama user existing.'
        );
    }

    /** Seeder memakai kontrak kanonis Issue #6: user milik pegawai di-resolve via employee_id, email internal tetap. */
    public function test_seeder_resolves_user_via_employee_id_with_different_internal_email(): void
    {
        $email = collect(SsoRoleMappedAccountSeeder::ROLE_MAPPING)->keys()->first();

        // Pegawai kanonis memegang email mapping pada email_pribadi; user internalnya
        // memakai email kantor yang berbeda.
        $employee = Employee::factory()->create([
            'email_pribadi' => $email,
        ]);

        $user = User::factory()->create([
            'email' => 'dayen-internal@lldikti.go.id',
            'employee_id' => $employee->id,
            'role' => 'pimpinan',
            'name' => 'Nama Internal Asli',
        ]);

        $this->seed(DatabaseSeeder::class);

        $user->refresh();
        // User yang sama dipakai ulang: email internal, role, dan nama tidak ditimpa.
        $this->assertSame('dayen-internal@lldikti.go.id', $user->email);
        $this->assertSame('pimpinan', $user->role);
        $this->assertSame('Nama Internal Asli', $user->name);
        $this->assertSame($employee->id, $user->employee_id);

        // Tidak ada user duplikat untuk pegawai yang sama.
        $this->assertSame(1, User::where('employee_id', $employee->id)->count());
    }

    /**
     * Seeder menolak pencocokan pegawai yang ambigu (lebih dari satu pegawai aktif cocok)
     * sama seperti kontrak callback: tidak memilih arbitrer, tidak membuat/mengikat user.
     */
    public function test_seeder_skips_ambiguous_employee_match(): void
    {
        $email = collect(SsoRoleMappedAccountSeeder::ROLE_MAPPING)->keys()->first();

        $employeeA = Employee::factory()->create([
            'nama_lengkap' => 'Ambigu A',
            'email' => $email,
        ]);
        $employeeB = Employee::factory()->create([
            'nama_lengkap' => 'Ambigu B',
        ]);
        // Kolom legacy email tidak unik: data lama/impor bisa menimbulkan kecocokan kedua.
        DB::table('employees')->where('id', $employeeB->id)->update(['email' => $email]);

        $this->seed(DatabaseSeeder::class);

        // Seeder tidak membuat/mengikat user untuk email ambigu itu.
        $this->assertSame(0, User::where('email', $email)->count());
        $this->assertSame(0, User::where('employee_id', $employeeA->id)->count());
        $this->assertSame(0, User::where('employee_id', $employeeB->id)->count());
    }

    /**
     * Seeder mendeteksi konflik identitas yang akan ditolak callback: user milik pegawai
     * dan user pemilik email mapping adalah dua akun berbeda → mapping dilewati tanpa
     * mutasi apa pun (fixture tidak dipaksakan jadi sumber identity_conflict).
     */
    public function test_seeder_skips_mapping_when_employee_user_conflicts_with_email_user(): void
    {
        $email = collect(SsoRoleMappedAccountSeeder::ROLE_MAPPING)->keys()->first();

        $employee = Employee::factory()->create([
            'email_pribadi' => $email,
        ]);

        $userByEmployee = User::factory()->create([
            'email' => 'internal-konflik@lldikti.go.id',
            'employee_id' => $employee->id,
            'role' => 'pimpinan',
            'name' => 'User Pegawai Asli',
        ]);
        $userByEmail = User::factory()->create([
            'email' => $email,
            'role' => 'pegawai',
            'name' => 'User Email Asli',
        ]);

        $this->seed(DatabaseSeeder::class);

        // Kedua user sama sekali tidak berubah (mapping untuk email itu dilewati).
        $userByEmployee->refresh();
        $this->assertSame('internal-konflik@lldikti.go.id', $userByEmployee->email);
        $this->assertSame('pimpinan', $userByEmployee->role);

        $userByEmail->refresh();
        $this->assertSame($email, $userByEmail->email);
        $this->assertSame('pegawai', $userByEmail->role);
        $this->assertNull($userByEmail->employee_id);
        $this->assertNull($userByEmployee->refresh()->keycloak_id);
    }

    public function test_phase_seven_browser_fixture_builds_projection_from_explicit_reconciliation_facts(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(PhaseSevenBrowserQaSeeder::class);

        $employee = User::query()
            ->where('email', $this->ssoEmailForRole('pegawai'))
            ->firstOrFail();
        $admin = User::query()
            ->where('email', $this->ssoEmailForRole('admin_kepegawaian'))
            ->firstOrFail();
        $set = LeaveUsageReconciliationSet::query()
            ->where('employee_id', $employee->employee_id)
            ->where('status', LeaveUsageReconciliationSet::STATUS_ACTIVE)
            ->sole();
        $facts = LeaveUsageRecord::query()
            ->where('reconciliation_set_id', $set->id)
            ->where('source_type', LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION)
            ->where('record_status', LeaveUsageRecord::STATUS_ACTIVE)
            ->orderBy('usage_year')
            ->get();
        $approvedRequest = LeaveRequest::query()
            ->where('employee_id', $employee->employee_id)
            ->where('status', 'disetujui')
            ->where('alasan', '[QA Phase 7 Browser] disetujui')
            ->sole();
        $approvedFact = LeaveUsageRecord::query()
            ->where('employee_id', $employee->employee_id)
            ->where('leave_request_id', $approvedRequest->id)
            ->where('source_type', LeaveUsageRecord::SOURCE_APPROVED_REQUEST)
            ->where('record_status', LeaveUsageRecord::STATUS_ACTIVE)
            ->sole();
        $membership = LeaveUsageReconciliationMembership::query()
            ->where('reconciliation_set_id', $set->id)
            ->where('itemized_usage_record_id', $approvedFact->id)
            ->sole();
        $projection = LeaveBalance::query()
            ->where('employee_id', $employee->employee_id)
            ->where('tahun', 2026)
            ->sole();

        $this->assertSame(1, LeaveUsageReconciliationSet::query()
            ->where('employee_id', $employee->employee_id)
            ->where('status', LeaveUsageReconciliationSet::STATUS_ACTIVE)
            ->count());
        $this->assertSame(2026, $set->balance_year);
        $this->assertSame([
            2024 => 12,
            2025 => 12,
            2026 => 2,
        ], $facts->mapWithKeys(fn (LeaveUsageRecord $fact): array => [
            $fact->usage_year => $fact->workdays,
        ])->all());
        $this->assertSame(4, LeaveUsageRecord::query()
            ->where('employee_id', $employee->employee_id)
            ->count());
        $this->assertSame(1, LeaveUsageRecord::query()
            ->where('employee_id', $employee->employee_id)
            ->where('source_type', LeaveUsageRecord::SOURCE_APPROVED_REQUEST)
            ->count());
        $this->assertSame(2, $approvedFact->workdays);
        $this->assertSame($admin->id, $approvedFact->recorded_by);
        $this->assertSame($facts->where('usage_year', 2026)->sole()->id, $membership->annual_reconciliation_record_id);
        $this->assertSame(2, $membership->included_workdays);
        $this->assertSame(1, LeaveUsageReconciliationMembership::query()
            ->where('reconciliation_set_id', $set->id)
            ->count());
        $this->assertSame([
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 2,
            'sisa' => 10,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 10,
            'terpakai_tahun_berjalan' => 2,
            'hangus' => 0,
        ], collect($projection->only([
            'tahun',
            'jatah_awal',
            'carry_over',
            'terpakai',
            'sisa',
            'sisa_n2',
            'sisa_n1',
            'sisa_tahun_berjalan',
            'terpakai_tahun_berjalan',
            'hangus',
        ]))->map(fn (mixed $value): int => (int) $value)->all());
        $this->assertSame(0, LeaveBalanceLedger::query()
            ->where('employee_id', $employee->employee_id)
            ->whereIn('event_type', [
                LeaveBalanceLedger::EVENT_LEAVE_DEDUCTED,
                LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET,
                LeaveBalanceLedger::EVENT_MANUAL_ADJUSTMENT,
            ])
            ->count());

        $auditedIds = $facts->pluck('id')
            ->push($approvedFact->id)
            ->push($set->id)
            ->push($projection->id);
        $humanAudits = AuditLog::query()
            ->whereIn('auditable_id', $auditedIds)
            ->get();

        $this->assertNotEmpty($humanAudits);
        $this->assertContains($approvedFact->id, $humanAudits->pluck('auditable_id')->all());
        $this->assertSame([$admin->id], $humanAudits->pluck('user_id')->unique()->values()->all());

        $counts = [
            'sets' => LeaveUsageReconciliationSet::query()->where('employee_id', $employee->employee_id)->count(),
            'facts' => LeaveUsageRecord::query()->where('employee_id', $employee->employee_id)->count(),
            'memberships' => LeaveUsageReconciliationMembership::query()->where('reconciliation_set_id', $set->id)->count(),
            'ledger' => LeaveBalanceLedger::query()->where('employee_id', $employee->employee_id)->count(),
            'audit' => AuditLog::query()->count(),
        ];

        $this->seed(PhaseSevenBrowserQaSeeder::class);

        $this->assertSame($set->id, LeaveUsageReconciliationSet::query()
            ->where('employee_id', $employee->employee_id)
            ->where('status', LeaveUsageReconciliationSet::STATUS_ACTIVE)
            ->sole()
            ->id);
        $this->assertSame($approvedFact->id, LeaveUsageRecord::query()
            ->where('leave_request_id', $approvedRequest->id)
            ->where('source_type', LeaveUsageRecord::SOURCE_APPROVED_REQUEST)
            ->sole()
            ->id);
        $this->assertSame($membership->id, LeaveUsageReconciliationMembership::query()
            ->where('reconciliation_set_id', $set->id)
            ->where('itemized_usage_record_id', $approvedFact->id)
            ->sole()
            ->id);
        $this->assertSame($counts, [
            'sets' => LeaveUsageReconciliationSet::query()->where('employee_id', $employee->employee_id)->count(),
            'facts' => LeaveUsageRecord::query()->where('employee_id', $employee->employee_id)->count(),
            'memberships' => LeaveUsageReconciliationMembership::query()->where('reconciliation_set_id', $set->id)->count(),
            'ledger' => LeaveBalanceLedger::query()->where('employee_id', $employee->employee_id)->count(),
            'audit' => AuditLog::query()->count(),
        ]);
    }

    public function test_phase_seven_browser_fixture_menolak_tahun_di_luar_2026_sebelum_mutasi(): void
    {
        Carbon::setTestNow(Carbon::create(2027, 1, 1, 0, 0, 0, config('app.timezone')));
        $exception = null;

        try {
            $this->seed(PhaseSevenBrowserQaSeeder::class);
        } catch (\RuntimeException $caught) {
            $exception = $caught;
        } finally {
            Carbon::setTestNow();
        }

        $this->assertNotNull($exception);
        $this->assertSame(
            'PhaseSevenBrowserQaSeeder hanya mendukung tahun saldo 2026; tahun aplikasi saat ini 2027.',
            $exception->getMessage(),
        );

        foreach ([
            'users',
            'employees',
            'leave_approval_chains',
            'leave_requests',
            'leave_usage_records',
            'leave_usage_reconciliation_sets',
            'leave_usage_reconciliation_memberships',
            'leave_balances',
            'leave_balance_ledger',
            'audit_logs',
        ] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_phase_seven_browser_fixture_memperbaiki_snapshot_legacy_tanpa_membership_secara_idempoten(): void
    {
        $this->seed(DatabaseSeeder::class);

        $employeeUser = User::query()->where('email', $this->ssoEmailForRole('pegawai'))->firstOrFail();
        $employee = $employeeUser->employee()->firstOrFail();
        $admin = User::query()->where('email', $this->ssoEmailForRole('admin_kepegawaian'))->firstOrFail();
        Appointment::query()->updateOrCreate(
            ['employee_id' => $employee->id],
            [
                'jenis_pengangkatan' => 'PNS',
                'tmt_pengangkatan' => '2020-01-01',
            ],
        );
        $legacySet = app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            2026,
            [2024 => 12, 2025 => 12, 2026 => 2],
            CarbonImmutable::create(2026, 12, 31, 12, 0, 0, config('app.timezone')),
            'Snapshot legacy tanpa membership pengajuan disetujui.',
            $admin,
        );
        $legacyFacts = $legacySet->records()->orderBy('usage_year')->get()->keyBy('usage_year');
        $approvedRequest = LeaveRequest::unguarded(fn (): LeaveRequest => LeaveRequest::query()->create([
            'id' => '70000000-0000-4000-8000-000000000014',
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $legacyFacts->get(2026)->leave_type_id,
            'tanggal_mulai' => '2026-11-02',
            'tanggal_selesai' => '2026-11-03',
            'jumlah_hari_kerja' => 2,
            'alasan' => '[QA Phase 7 Browser] disetujui',
            'status' => 'disetujui',
        ]));

        $this->assertDatabaseCount('leave_usage_reconciliation_memberships', 0);
        $this->assertDatabaseMissing('leave_usage_records', [
            'leave_request_id' => $approvedRequest->id,
            'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
        ]);

        $this->seed(PhaseSevenBrowserQaSeeder::class);

        $activeSet = LeaveUsageReconciliationSet::query()
            ->where('employee_id', $employee->id)
            ->where('status', LeaveUsageReconciliationSet::STATUS_ACTIVE)
            ->sole();
        $activeFacts = $activeSet->records()
            ->where('source_type', LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION)
            ->where('record_status', LeaveUsageRecord::STATUS_ACTIVE)
            ->orderBy('usage_year')
            ->get();
        $approvedFact = LeaveUsageRecord::query()
            ->where('leave_request_id', $approvedRequest->id)
            ->where('source_type', LeaveUsageRecord::SOURCE_APPROVED_REQUEST)
            ->where('record_status', LeaveUsageRecord::STATUS_ACTIVE)
            ->sole();
        $projection = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2026)
            ->sole();

        $this->assertSame(2, $projection->terpakai);
        $this->assertSame(10, $projection->sisa);
        $this->assertSame(LeaveUsageReconciliationSet::STATUS_SUPERSEDED, $legacySet->fresh()->status);
        $this->assertSame($legacySet->id, $activeSet->replaces_id);
        $this->assertSame([2024 => 12, 2025 => 12, 2026 => 2], $activeFacts
            ->mapWithKeys(fn (LeaveUsageRecord $fact): array => [$fact->usage_year => $fact->workdays])
            ->all());

        foreach ($activeFacts as $fact) {
            $this->assertSame($legacyFacts->get($fact->usage_year)->id, $fact->replaces_id);
        }

        $membership = LeaveUsageReconciliationMembership::query()
            ->where('reconciliation_set_id', $activeSet->id)
            ->where('itemized_usage_record_id', $approvedFact->id)
            ->sole();
        $this->assertSame($activeFacts->where('usage_year', 2026)->sole()->id, $membership->annual_reconciliation_record_id);
        $this->assertSame(2, $membership->included_workdays);
        $this->assertSame($admin->id, $approvedFact->recorded_by);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $activeSet->id,
            'user_id' => $admin->id,
        ]);

        $ids = [
            'set' => $activeSet->id,
            'approved_fact' => $approvedFact->id,
            'membership' => $membership->id,
        ];
        $counts = [
            'sets' => LeaveUsageReconciliationSet::query()->where('employee_id', $employee->id)->count(),
            'facts' => LeaveUsageRecord::query()->where('employee_id', $employee->id)->count(),
            'memberships' => LeaveUsageReconciliationMembership::query()->count(),
            'ledger' => LeaveBalanceLedger::query()->where('employee_id', $employee->id)->count(),
            'audit' => AuditLog::query()->count(),
        ];

        $this->seed(PhaseSevenBrowserQaSeeder::class);

        $this->assertSame($ids, [
            'set' => LeaveUsageReconciliationSet::query()
                ->where('employee_id', $employee->id)
                ->where('status', LeaveUsageReconciliationSet::STATUS_ACTIVE)
                ->sole()->id,
            'approved_fact' => LeaveUsageRecord::query()
                ->where('leave_request_id', $approvedRequest->id)
                ->where('source_type', LeaveUsageRecord::SOURCE_APPROVED_REQUEST)
                ->sole()->id,
            'membership' => LeaveUsageReconciliationMembership::query()->sole()->id,
        ]);
        $this->assertSame($counts, [
            'sets' => LeaveUsageReconciliationSet::query()->where('employee_id', $employee->id)->count(),
            'facts' => LeaveUsageRecord::query()->where('employee_id', $employee->id)->count(),
            'memberships' => LeaveUsageReconciliationMembership::query()->count(),
            'ledger' => LeaveBalanceLedger::query()->where('employee_id', $employee->id)->count(),
            'audit' => AuditLog::query()->count(),
        ]);
    }

    public function test_phase_seven_browser_fixture_menyediakan_preview_chain_valid_dan_invalid(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(PhaseSevenBrowserQaSeeder::class);

        $validEmployee = Employee::query()
            ->where('email', 'qa-phase7-cuti-manual@example.test')
            ->sole();
        $invalidEmployee = Employee::query()
            ->where('email', 'qa-phase7-chain-invalid@example.test')
            ->sole();
        $this->assertSame(1, Employee::query()
            ->where('email', 'qa-phase7-approver-nonaktif@example.test')
            ->count());
        $inactiveApprover = Employee::query()
            ->with('statusPegawai')
            ->where('email', 'qa-phase7-approver-nonaktif@example.test')
            ->sole();
        $preview = app(CurrentApprovalChainPreviewQuery::class);

        $this->assertSame('NONAKTIF', $inactiveApprover->statusPegawai?->kode);
        $this->assertSame('Nonaktif', $inactiveApprover->statusPegawai?->kelompok);
        $this->assertSame([
            'available' => true,
            'valid' => true,
        ], collect($preview->forEmployee($validEmployee->id))->only(['available', 'valid'])->all());
        $invalidPreview = $preview->forEmployee($invalidEmployee->id);
        $this->assertTrue($invalidPreview['available']);
        $this->assertFalse($invalidPreview['valid']);
        $this->assertContains('Approver pada salah satu tahap chain tidak aktif.', $invalidPreview['warnings']);
    }

    public function test_phase_seven_browser_fixture_menyediakan_penugasan_kepala_bagian_aktual_untuk_demo_pegawai(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(PhaseSevenBrowserQaSeeder::class);

        $employee = User::query()
            ->where('email', $this->ssoEmailForRole('pegawai'))
            ->firstOrFail()
            ->employee()
            ->firstOrFail();
        $approver = User::query()
            ->where('email', $this->ssoEmailForRole('kepala_bagian'))
            ->firstOrFail()
            ->employee()
            ->firstOrFail();

        $this->assertSame($approver->id, $employee->currentSupervisor()?->kepala_bagian_id);
    }

    public function test_phase_seven_browser_fixture_menyimpan_fakta_manual_snapshot_secara_idempoten_tanpa_approval_ulang(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(PhaseSevenBrowserQaSeeder::class);

        $employee = Employee::query()
            ->where('email', 'qa-phase7-cuti-manual@example.test')
            ->sole();
        $manualFact = LeaveUsageRecord::query()
            ->with('externalApprovalSteps.approverEmployee.statusPegawai')
            ->where('employee_id', $employee->id)
            ->where('source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)
            ->where('administrative_note', '[QA Phase 7 Browser] Cuti manual dengan snapshot persetujuan.')
            ->sole();
        $steps = $manualFact->externalApprovalSteps;

        $this->assertNull($manualFact->approval_document_number);
        $this->assertSame([
            [1, 'verifier', 'simpeg_employee', 'verified'],
            [2, 'kepala_bagian', 'simpeg_employee', 'approved'],
            [3, 'pybmc', 'external_official', 'final_approved'],
        ], $steps->map(fn (LeaveUsageExternalApprovalStep $step): array => [
            $step->step_order,
            $step->step_type,
            $step->approver_source,
            $step->result_code,
        ])->all());
        $this->assertSame('Aktif', $steps->get(0)->approverEmployee->statusPegawai?->kelompok);
        $this->assertSame('Nonaktif', $steps->get(1)->approverEmployee->statusPegawai?->kelompok);
        $this->assertSame('Kepala Lembaga Arsip QA', $steps->get(2)->approver_name_snapshot);

        $counts = [
            'facts' => LeaveUsageRecord::query()
                ->where('employee_id', $employee->id)
                ->where('source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)
                ->count(),
            'steps' => DB::table('leave_usage_external_approval_steps')
                ->where('leave_usage_record_id', $manualFact->id)
                ->count(),
            'documents' => DB::table('leave_usage_documents')
                ->where('leave_usage_record_id', $manualFact->id)
                ->count(),
            'audits' => AuditLog::query()->where('auditable_id', $manualFact->id)->count(),
            'requests' => LeaveRequest::query()->where('employee_id', $employee->id)->count(),
            'reservations' => DB::table('leave_balance_reservation_events')->where('employee_id', $employee->id)->count(),
            'notifications' => DB::table('notifications')->count(),
            'jobs' => DB::table('jobs')->count(),
        ];

        $this->assertSame([
            'facts' => 1,
            'steps' => 3,
            'documents' => 0,
            'audits' => 1,
            'requests' => 0,
            'reservations' => 0,
            'notifications' => 0,
            'jobs' => 0,
        ], $counts);

        $this->seed(PhaseSevenBrowserQaSeeder::class);

        $this->assertSame($manualFact->id, LeaveUsageRecord::query()
            ->where('employee_id', $employee->id)
            ->where('source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)
            ->sole()
            ->id);
        $this->assertSame($counts, [
            'facts' => LeaveUsageRecord::query()
                ->where('employee_id', $employee->id)
                ->where('source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)
                ->count(),
            'steps' => DB::table('leave_usage_external_approval_steps')
                ->where('leave_usage_record_id', $manualFact->id)
                ->count(),
            'documents' => DB::table('leave_usage_documents')
                ->where('leave_usage_record_id', $manualFact->id)
                ->count(),
            'audits' => AuditLog::query()->where('auditable_id', $manualFact->id)->count(),
            'requests' => LeaveRequest::query()->where('employee_id', $employee->id)->count(),
            'reservations' => DB::table('leave_balance_reservation_events')->where('employee_id', $employee->id)->count(),
            'notifications' => DB::table('notifications')->count(),
            'jobs' => DB::table('jobs')->count(),
        ]);
    }

    /**
     * Email akun uji SSO untuk role persona — sumber tunggal fixture seeder.
     */
    private function ssoEmailForRole(string $role): string
    {
        $email = array_search($role, SsoRoleMappedAccountSeeder::ROLE_MAPPING, true);
        $this->assertIsString($email, "Fixture SSO untuk role '{$role}' tidak ditemukan.");

        return $email;
    }
}
