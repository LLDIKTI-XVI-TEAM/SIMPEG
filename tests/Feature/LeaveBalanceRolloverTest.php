<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceService;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression test untuk rollover saldo cuti tahunan berbasis ledger.
 * Fokusnya menjaga idempotensi CLI/scheduler, cap 12/18/24, dan pemisahan penangguhan dinas dari status Ditangguhkan biasa.
 */
class LeaveBalanceRolloverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        Carbon::setTestNow('2027-01-01 00:05:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_rollover_normal_membawa_maksimal_enam_hari_ke_tahun_target(): void
    {
        $employee = $this->employeeWithAppointment();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 10,
            'sisa' => 10,
        ]));

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $balance->sisa_n2);
        $this->assertSame(6, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(18, $balance->sisa);
        $this->assertSame(4, $balance->hangus);

        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => 'rollover_applied',
            'amount' => 0,
            'source_year' => 2026,
            'dedup_key' => "{$employee->id}:2027:rollover_applied",
        ]);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => 'carry_over_granted',
            'amount' => 6,
            'source_year' => 2026,
            'dedup_key' => "{$employee->id}:2027:carry_over_granted:2026",
        ]);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => 'carry_over_expired',
            'amount' => 0,
            'source_year' => 2026,
            'dedup_key' => "{$employee->id}:2027:carry_over_expired:2026",
        ]);

        $audit = AuditLog::where('event', 'LEAVE_ROLLOVER_APPLIED')->firstOrFail();
        $this->assertSame('LeaveBalance', $audit->auditable_type);
        $this->assertSame($employee->id, $audit->new_values['employee_id']);
        $this->assertSame(2027, $audit->new_values['tahun']);
        $this->assertSame(2026, $audit->new_values['tahun_sumber']);
        $this->assertSame(10, $audit->old_values['old_balance']);
        $this->assertSame(18, $audit->new_values['new_balance']);
        $this->assertSame(8, $audit->new_values['delta']);
    }

    public function test_rollover_dua_tahun_tanpa_cuti_tahunan_mengizinkan_total_dua_puluh_empat_hari(): void
    {
        $employee = $this->employeeWithAppointment();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
            'carry_over' => 6,
            'sisa' => 18,
        ]));

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(6, $balance->sisa_n2);
        $this->assertSame(6, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(24, $balance->sisa);
        $this->assertSame(6, $balance->hangus);
    }

    public function test_rollover_cuti_tahunan_sebagian_mematahkan_cap_dua_tahun(): void
    {
        $employee = $this->employeeWithAppointment();
        $jenisTahunan = RefJenisCuti::where('code', 'tahunan')->firstOrFail();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 10,
            'carry_over' => 6,
            'terpakai_tahun_berjalan' => 2,
            'terpakai' => 2,
            'sisa' => 16,
        ]));
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisTahunan->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-07',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Cuti tahunan sebagian.',
            'status' => 'disetujui',
        ]);

        $service->rolloverYear(2026);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $balance->sisa_n2);
        $this->assertSame(0, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(12, $balance->sisa);
        $this->assertSame(16, $balance->hangus);

        $service->rolloverYear(2026);

        $this->assertSame(1, LeaveBalanceLedger::where('employee_id', $employee->id)
            ->where('event_type', 'rollover_applied')->count());
        $this->assertSame(1, LeaveBalanceLedger::where('employee_id', $employee->id)
            ->where('event_type', 'annual_entitlement_granted')->count());
        $this->assertSame(1, LeaveBalanceLedger::where('employee_id', $employee->id)
            ->where('event_type', 'carry_over_expired')->count());
        $this->assertSame(0, LeaveBalanceLedger::where('employee_id', $employee->id)
            ->where('event_type', 'carry_over_granted')->count());
        $this->assertSame(1, AuditLog::where('event', 'LEAVE_ROLLOVER_APPLIED')
            ->where('auditable_id', $balance->id)->count());

        $expired = LeaveBalanceLedger::where('employee_id', $employee->id)
            ->where('event_type', 'carry_over_expired')->firstOrFail();
        $this->assertSame(2026, $expired->source_year);
        $this->assertSame(16, $expired->metadata['expired_days']);
        $this->assertSame(2027, $expired->metadata['target_year']);

        $audit = AuditLog::where('event', 'LEAVE_ROLLOVER_APPLIED')
            ->where('auditable_id', $balance->id)->firstOrFail();
        $this->assertSame($employee->id, $audit->new_values['employee_id']);
        $this->assertSame(2027, $audit->new_values['tahun']);
        $this->assertSame(2026, $audit->new_values['tahun_sumber']);
        $this->assertSame(0, $audit->new_values['carry_over']);
        $this->assertSame(16, $audit->new_values['hangus']);
    }

    /**
     * @return array<string, array{0:array<int, int>}>
     */
    public static function approvedAnnualBucketProvider(): array
    {
        return [
            'N-2' => [[2024]],
            'N-1' => [[2025]],
            'current' => [[2026]],
            'kombinasi N-2, N-1, dan current' => [[2024, 2025, 2026]],
        ];
    }

    #[DataProvider('approvedAnnualBucketProvider')]
    public function test_approval_dari_bucket_mana_pun_menggagalkan_carry_ordinary(array $deductionSourceYears): void
    {
        $employee = $this->employeeWithAppointment();
        $jenisTahunan = RefJenisCuti::where('code', 'tahunan')->firstOrFail();
        $sourceBalance = LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 7,
            'carry_over' => 6,
            'sisa' => 13,
        ]));
        $request = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisTahunan->id,
            'tanggal_mulai' => '2026-06-15',
            'tanggal_selesai' => '2026-06-15',
            'jumlah_hari_kerja' => count($deductionSourceYears),
            'alasan' => 'Cuti tahunan memakai bucket sumber teruji.',
            'status' => 'disetujui',
        ]);

        foreach ($deductionSourceYears as $deductionSourceYear) {
            LeaveBalanceLedger::create([
                'employee_id' => $employee->id,
                'leave_request_id' => $request->id,
                'leave_balance_id' => $sourceBalance->id,
                'tahun' => 2026,
                'event_type' => 'leave_deducted',
                'amount' => -1,
                'source_year' => $deductionSourceYear,
                'reason' => 'Fixture pemotongan dari bucket sumber.',
                'dedup_key' => "leave_deducted:rule1:{$request->id}:{$deductionSourceYear}",
                'metadata' => ['bucket' => $deductionSourceYear === 2026 ? 'current' : ($deductionSourceYear === 2025 ? 'n1' : 'n2')],
                'occurred_at' => Carbon::parse('2026-06-15 17:00:00'),
            ]);
        }

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $target->sisa_n2);
        $this->assertSame(0, $target->sisa_n1);
        $this->assertSame(12, $target->sisa);
        $this->assertSame(13, $target->hangus);
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => 'carry_over_granted',
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function nonApprovedAnnualStatusProvider(): array
    {
        return [
            'pending approval' => ['menunggu_approval'],
            'generic postpone' => ['ditangguhkan'],
            'needs changes' => ['perlu_perubahan'],
            'not approved' => ['tidak_disetujui'],
        ];
    }

    #[DataProvider('nonApprovedAnnualStatusProvider')]
    public function test_status_selain_disetujui_tidak_menggagalkan_carry_ordinary(string $status): void
    {
        $employee = $this->employeeWithAppointment();
        $jenisTahunan = RefJenisCuti::where('code', 'tahunan')->firstOrFail();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 7,
            'sisa' => 7,
        ]));
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisTahunan->id,
            'tanggal_mulai' => '2026-09-14',
            'tanggal_selesai' => '2026-09-14',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Fixture status non-final Rule 1.',
            'status' => $status,
        ]);

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(6, $target->sisa_n1);
        $this->assertSame(18, $target->sisa);
        $this->assertSame(1, $target->hangus);
    }

    public function test_approval_di_luar_half_open_source_year_tidak_menggagalkan_rule_satu(): void
    {
        $employee = $this->employeeWithAppointment();
        $jenisTahunan = RefJenisCuti::where('code', 'tahunan')->firstOrFail();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 6,
            'sisa' => 6,
        ]));

        foreach (['2025-12-31', '2027-01-01'] as $tanggalMulai) {
            LeaveRequest::create([
                'employee_id' => $employee->id,
                'jenis_cuti_id' => $jenisTahunan->id,
                'tanggal_mulai' => $tanggalMulai,
                'tanggal_selesai' => $tanggalMulai,
                'jumlah_hari_kerja' => 1,
                'alasan' => 'Fixture batas tahun Rule 1.',
                'status' => 'disetujui',
            ]);
        }

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(6, $target->sisa_n1);
        $this->assertSame(18, $target->sisa);
        $this->assertSame(0, $target->hangus);
    }

    public function test_tahun_rule_satu_ditentukan_oleh_tanggal_mulai(): void
    {
        $employee = $this->employeeWithAppointment();
        $jenisTahunan = RefJenisCuti::where('code', 'tahunan')->firstOrFail();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 6,
            'sisa' => 6,
        ]));
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisTahunan->id,
            'tanggal_mulai' => '2026-12-31',
            'tanggal_selesai' => '2026-12-31',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Fixture akhir tahun sumber.',
            'status' => 'disetujui',
        ]);

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $target->sisa_n1);
        $this->assertSame(12, $target->sisa);
        $this->assertSame(6, $target->hangus);
    }

    public function test_approval_tahun_sebelumnya_mematahkan_rule_dua_tetapi_rule_satu_tahun_sumber_tetap_berlaku(): void
    {
        $employee = $this->employeeWithAppointment();
        $jenisTahunan = RefJenisCuti::where('code', 'tahunan')->firstOrFail();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 7,
            'carry_over' => 6,
            'sisa' => 13,
        ]));
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisTahunan->id,
            'tanggal_mulai' => '2025-04-07',
            'tanggal_selesai' => '2025-04-07',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Approval pada tahun sebelum source year.',
            'status' => 'disetujui',
        ]);

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $target->sisa_n2);
        $this->assertSame(6, $target->sisa_n1);
        $this->assertSame(18, $target->sisa);
        $this->assertSame(7, $target->hangus);
    }

    public function test_carry_statutory_tetap_diproses_saat_approval_menggagalkan_ordinary_carry(): void
    {
        $employee = $this->employeeWithAppointment();
        $jenisTahunan = RefJenisCuti::where('code', 'tahunan')->firstOrFail();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 7,
            'sisa' => 7,
        ]));
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisTahunan->id,
            'tanggal_mulai' => '2026-05-11',
            'tanggal_selesai' => '2026-05-11',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Approval yang mematahkan ordinary carry.',
            'status' => 'disetujui',
        ]);
        $service->recordDutyPostponement($employee, 2026, 4, 'Penugasan mendesak kantor', null);

        $service->rolloverYear(2026);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $target->sisa_n2);
        $this->assertSame(4, $target->sisa_n1);
        $this->assertSame(16, $target->sisa);
        $this->assertSame(7, $target->hangus);
        $carry = LeaveBalanceLedger::where('employee_id', $employee->id)
            ->where('tahun', 2027)
            ->where('event_type', 'carry_over_granted')
            ->firstOrFail();
        $this->assertSame(4, $carry->amount);
        $this->assertSame(4, $carry->metadata['duty_postponed_carried']);
    }

    public function test_rollover_memperbarui_ringkasan_target_yang_sudah_ada(): void
    {
        $employee = $this->employeeWithAppointment();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 5,
            'sisa' => 5,
        ]));
        LeaveBalance::create($this->balancePayload($employee, 2027, [
            'sisa_tahun_berjalan' => 12,
            'sisa' => 12,
        ]));

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(5, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(17, $balance->sisa);
    }

    public function test_rollover_target_yang_sudah_terpakai_tidak_mengembalikan_hari_yang_sudah_dipotong(): void
    {
        $employee = $this->employeeWithAppointment();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 5,
            'sisa' => 5,
        ]));
        LeaveBalance::create($this->balancePayload($employee, 2027, [
            'terpakai' => 3,
            'terpakai_tahun_berjalan' => 3,
            'sisa_tahun_berjalan' => 9,
            'sisa' => 9,
        ]));

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(5, $balance->sisa_n1);
        $this->assertSame(9, $balance->sisa_tahun_berjalan);
        $this->assertSame(14, $balance->sisa);
        $this->assertSame(3, $balance->terpakai_tahun_berjalan);
    }

    public function test_rollover_terlambat_melewati_target_yang_sudah_dibuka_admin_tanpa_mutasi(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 6,
            'sisa' => 6,
        ]));
        $service->setOpeningBalance($employee, 2027, [
            'n2' => 1,
            'n1' => 2,
            'current' => 10,
        ], 'Pembukaan admin sebelum rollover terlambat.', $actor);
        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $summaryBefore = $this->summaryPayload($target);
        $ledgerCount = LeaveBalanceLedger::count();
        $auditCount = AuditLog::count();

        $service->rolloverYear(2026);

        $this->assertSame($summaryBefore, $this->summaryPayload($target->fresh()));
        $this->assertSame($ledgerCount, LeaveBalanceLedger::count());
        $this->assertSame($auditCount, AuditLog::count());
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
        ]);
    }

    public function test_rollover_mengecek_marker_setelah_employee_lock_dan_retry_tidak_menulis_ulang(): void
    {
        $employee = $this->employeeWithAppointment();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 5,
            'sisa' => 5,
        ]));
        $service->rolloverYear(2026);
        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $summaryBefore = $this->summaryPayload($target);
        $ledgerCount = LeaveBalanceLedger::count();
        $auditCount = AuditLog::count();
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $service->rolloverYear(2026);

        $employeeLockIndex = collect($queries)->search(
            fn (string $sql): bool => str_contains($sql, 'from "employees"') && str_contains($sql, 'where "employees"."id"'),
        );
        $rolloverCheckIndex = collect($queries)->search(
            fn (string $sql): bool => str_contains($sql, 'from "leave_balance_ledger"') && str_contains($sql, '"dedup_key"'),
        );
        $this->assertIsInt($employeeLockIndex);
        $this->assertIsInt($rolloverCheckIndex);
        $this->assertLessThan($rolloverCheckIndex, $employeeLockIndex);
        $this->assertSame($summaryBefore, $this->summaryPayload($target->fresh()));
        $this->assertSame($ledgerCount, LeaveBalanceLedger::count());
        $this->assertSame($auditCount, AuditLog::count());
    }

    public function test_rollover_terlambat_mempertahankan_koreksi_target_yang_diinisialisasi_sistem(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 5,
            'sisa' => 5,
        ]));
        $this->assertSame(12, $service->availableFor($employee, 2027, Carbon::parse('2027-01-01')));
        $service->adjustBalance($employee, 2027, 'current', 3, 'Koreksi sebelum rollover terlambat.', $actor);

        $service->rolloverYear(2026);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $target->sisa_n2);
        $this->assertSame(5, $target->sisa_n1);
        $this->assertSame(15, $target->sisa_tahun_berjalan);
        $this->assertSame(20, $target->sisa);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_MANUAL_ADJUSTMENT,
            'amount' => 3,
        ]);
    }

    public function test_penangguhan_dinas_dibawa_satu_tahun_dan_rollover_idempotent(): void
    {
        $employee = $this->employeeWithAppointment();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 6,
            'sisa' => 6,
        ]));

        $service->recordDutyPostponement($employee, 2026, 8, 'Penugasan mendesak kantor', null);
        $service->recordDutyPostponement($employee, 2026, 8, 'Penugasan mendesak kantor', null);
        $service->rolloverYear(2026);
        $service->rolloverYear(2026);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $balance->sisa_n2);
        $this->assertSame(12, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(24, $balance->sisa);
        $this->assertSame(2, $balance->hangus);

        $this->assertSame(1, LeaveBalanceLedger::where('event_type', 'duty_postponement_recorded')->count());
        $this->assertSame(5, LeaveBalanceLedger::where('employee_id', $employee->id)->count());
        $this->assertSame(24, LeaveBalanceLedger::where('employee_id', $employee->id)->where('tahun', 2027)->sum('amount'));
    }

    public function test_penangguhan_dinas_mencatat_aktor_pencatat(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();

        app(LeaveBalanceService::class)->recordDutyPostponement($employee, 2026, 4, 'Penugasan mendesak kantor', null, $actor);

        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'event_type' => 'duty_postponement_recorded',
            'amount' => 0,
            'created_by' => $actor->id,
        ]);
        $ledger = LeaveBalanceLedger::where('event_type', 'duty_postponement_recorded')->firstOrFail();
        $this->assertSame(4, $ledger->metadata['postponed_days']);
    }

    public function test_penangguhan_dinas_hangus_setelah_satu_tahun_dan_tidak_menjadi_n_dua(): void
    {
        $employee = $this->employeeWithAppointment();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 0,
            'sisa' => 0,
        ]));

        $service->recordDutyPostponement($employee, 2026, 8, 'Penugasan mendesak kantor', null);
        $service->rolloverYear(2026);
        $service->rolloverYear(2027);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2028)->firstOrFail();
        $this->assertSame(0, $balance->sisa_n2);
        $this->assertSame(6, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(18, $balance->sisa);
        $this->assertSame(14, $balance->hangus);
    }

    public function test_cuti_besar_mencegah_jatah_tahunan_otomatis_di_tahun_yang_sama(): void
    {
        $employee = $this->employeeWithAppointment('2024-01-01');
        $cutiBesar = RefJenisCuti::where('code', 'besar')->firstOrFail();
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $cutiBesar->id,
            'tanggal_mulai' => '2026-08-03',
            'tanggal_selesai' => '2026-08-31',
            'jumlah_hari_kerja' => 20,
            'alasan' => 'Cuti besar.',
            'status' => 'disetujui',
        ]);

        $available = app(LeaveBalanceService::class)->availableFor($employee, 2026, Carbon::parse('2026-08-03'));

        $this->assertSame(0, $available);
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'event_type' => 'annual_entitlement_granted',
        ]);
    }

    public function test_final_cuti_besar_gagal_jika_cuti_tahunan_sudah_terpotong_di_tahun_sama(): void
    {
        $employee = $this->employeeWithAppointment();
        $cutiBesar = RefJenisCuti::where('code', 'besar')->firstOrFail();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 10,
            'terpakai_tahun_berjalan' => 2,
            'terpakai' => 2,
            'sisa' => 10,
        ]));
        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => 'leave_deducted',
            'amount' => -2,
            'source_year' => 2026,
            'reason' => 'Pemotongan cuti tahunan sebelum cuti besar.',
            'dedup_key' => "leave_deducted:test:{$employee->id}:2026",
            'occurred_at' => Carbon::parse('2026-07-01'),
        ]);

        $this->expectException(ValidationException::class);
        app(LeaveBalanceService::class)->assertCutiBesarCanBeFinallyApproved($employee->id, 2026);
    }

    public function test_final_cuti_besar_gagal_jika_cuti_tahunan_tahun_sama_memakai_carry_over(): void
    {
        $employee = $this->employeeWithAppointment();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2026, [
            'carry_over' => 6,
            'terpakai' => 3,
            'sisa_n1' => 3,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'sisa' => 15,
        ]));
        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => 'leave_deducted',
            'amount' => -3,
            'source_year' => 2025,
            'reason' => 'Pemotongan cuti tahunan dari carry-over sebelum cuti besar.',
            'dedup_key' => "leave_deducted:test:{$employee->id}:2025",
            'occurred_at' => Carbon::parse('2026-02-01'),
        ]);

        $this->expectException(ValidationException::class);
        app(LeaveBalanceService::class)->assertCutiBesarCanBeFinallyApproved($employee->id, 2026);
    }

    public function test_command_rollover_memakai_tahun_sumber_dan_scheduler_berjalan_jam_nol_nol_lima(): void
    {
        $employee = $this->employeeWithAppointment();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 3,
            'sisa' => 3,
        ]));

        $this->artisan('cuti:rollover 2026')
            ->expectsOutput('Rollover saldo cuti tahun 2026 ke 2027 selesai.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'sisa_n1' => 3,
            'sisa_tahun_berjalan' => 12,
            'sisa' => 15,
        ]);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => 'annual_entitlement_granted',
            'amount' => 12,
            'source_year' => 2027,
            'dedup_key' => "{$employee->id}:2027:annual_entitlement_granted",
        ]);

        $event = collect(Schedule::events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'cuti:rollover'));

        $this->assertNotNull($event);
        $this->assertSame('5 0 1 1 *', $event->expression);
        $this->assertSame(config('app.timezone'), $event->timezone);
    }

    public function test_command_rollover_tanpa_argumen_memakai_tahun_sebelumnya(): void
    {
        $employee = $this->employeeWithAppointment();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 2,
            'sisa' => 2,
        ]));

        $this->artisan('cuti:rollover')
            ->expectsOutput('Rollover saldo cuti tahun 2026 ke 2027 selesai.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'sisa_n1' => 2,
            'sisa' => 14,
        ]);
    }

    public function test_command_rollover_menolak_tahun_tidak_valid_sebelum_query_database(): void
    {
        $this->artisan('cuti:rollover bukan-tahun')
            ->expectsOutput('Tahun sumber rollover harus berupa tahun 4 digit, contoh: 2026.')
            ->assertExitCode(1);
    }

    private function employeeWithAppointment(string $tmt = '2020-01-01'): Employee
    {
        $employee = Employee::factory()->create();
        $employee->appointment()->create([
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => $tmt,
            'no_sk' => 'SK-TEST',
            'tanggal_sk' => $tmt,
        ]);

        return $employee;
    }

    /**
     * @param  array<string, int>  $overrides
     * @return array<string, mixed>
     */
    private function balancePayload(Employee $employee, int $tahun, array $overrides = []): array
    {
        return array_merge([
            'employee_id' => $employee->id,
            'tahun' => $tahun,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ], $overrides);
    }

    /** @return array<string, int> */
    private function summaryPayload(LeaveBalance $balance): array
    {
        return $balance->only([
            'jatah_awal',
            'carry_over',
            'terpakai',
            'sisa',
            'sisa_n2',
            'sisa_n1',
            'sisa_tahun_berjalan',
            'terpakai_tahun_berjalan',
            'hangus',
        ]);
    }
}
