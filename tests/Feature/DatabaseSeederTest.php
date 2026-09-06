<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageExternalApprovalStep;
use App\Models\LeaveUsageRecord;
use App\Models\User;
use App\Queries\Cuti\CurrentApprovalChainPreviewQuery;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PhaseSevenBrowserQaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_expected_demo_and_approval_users(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 9);
        $this->assertDatabaseHas('users', [
            'keycloak_username' => 'demo-klabat',
            'role' => 'super_admin',
        ]);
        $this->assertDatabaseHas('users', [
            'keycloak_username' => 'demo-klabat-kabag',
            'role' => 'kepala_bagian',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'merlina.rahman@example.com',
            'role' => 'admin_kepegawaian',
        ]);
        $this->assertDatabaseCount('leave_balances', 0);
        $this->assertFileDoesNotExist(database_path('seeders/LeaveBalance2026Seeder.php'));
    }

    public function test_phase_seven_browser_fixture_mencatat_pengajuan_disetujui_sebagai_fakta_tunggal(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(PhaseSevenBrowserQaSeeder::class);

        $employee = User::query()
            ->where('keycloak_username', 'demo-klabat-pegawai')
            ->firstOrFail();
        $admin = User::query()
            ->where('keycloak_username', 'demo-klabat-kepeg')
            ->firstOrFail();
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
        $projection = LeaveBalance::query()
            ->where('employee_id', $employee->employee_id)
            ->where('tahun', 2026)
            ->sole();

        $this->assertSame(1, LeaveUsageRecord::query()
            ->where('employee_id', $employee->employee_id)
            ->where('source_type', LeaveUsageRecord::SOURCE_APPROVED_REQUEST)
            ->count());
        $this->assertSame(2, $approvedFact->workdays);
        $this->assertSame($admin->id, $approvedFact->recorded_by);
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

        $auditedIds = collect([$approvedFact->id, $projection->id]);
        $humanAudits = AuditLog::query()
            ->whereIn('auditable_id', $auditedIds)
            ->get();

        $this->assertNotEmpty($humanAudits);
        $this->assertContains($approvedFact->id, $humanAudits->pluck('auditable_id')->all());
        $this->assertSame([$admin->id], $humanAudits->pluck('user_id')->unique()->values()->all());

        $counts = [
            'facts' => LeaveUsageRecord::query()->where('employee_id', $employee->employee_id)->count(),
            'ledger' => LeaveBalanceLedger::query()->where('employee_id', $employee->employee_id)->count(),
            'audit' => AuditLog::query()->count(),
        ];

        $this->seed(PhaseSevenBrowserQaSeeder::class);

        $this->assertSame($approvedFact->id, LeaveUsageRecord::query()
            ->where('leave_request_id', $approvedRequest->id)
            ->where('source_type', LeaveUsageRecord::SOURCE_APPROVED_REQUEST)
            ->sole()
            ->id);
        $this->assertSame($counts, [
            'facts' => LeaveUsageRecord::query()->where('employee_id', $employee->employee_id)->count(),
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
            'leave_balances',
            'leave_balance_ledger',
            'audit_logs',
        ] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
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
            ->where('keycloak_username', 'demo-klabat-pegawai')
            ->firstOrFail()
            ->employee()
            ->firstOrFail();
        $approver = User::query()
            ->where('keycloak_username', 'demo-klabat-kabag')
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
}
