<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceRecalculationService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EmployeeAppointmentLeaveBalanceBoundedReplayTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
        Carbon::setTestNow('2026-08-25 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_koreksi_tmt_mengabaikan_fakta_tahunan_di_luar_horizon_material_aktif(): void
    {
        [$employee, $appointment, $actor] = $this->employeeWithAppointment('2023-01-01');
        app(LeaveBalanceRecalculationService::class)->recalculate(
            $employee,
            2024,
            $actor,
            'Membentuk projection material tiga tahun aktif.',
        );
        $annual = RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();
        LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $annual->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'usage_year' => 2010,
            'effective_date' => '2010-01-04',
            'start_date' => '2010-01-04',
            'end_date' => '2010-01-04',
            'workdays' => 1,
            'administrative_note' => 'Fakta historis di luar window projection aktif.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $actor->id,
        ]);
        $balanceIds = LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->pluck('id');
        $beforeLedger = LeaveBalanceLedger::query()->where('employee_id', $employee->id)->count();
        $beforeAudit = AuditLog::query()
            ->where('auditable_type', 'LeaveBalance')
            ->whereIn('auditable_id', $balanceIds)
            ->count();

        $this->assertSame([2024, 2025, 2026], LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->orderBy('tahun')
            ->pluck('tahun')
            ->all());
        $this->assertSame(0, LeaveUsageReconciliationSet::query()->whereBelongsTo($employee)->count());

        $response = $this->updateAppointment($employee, $actor, '2023-06-01');
        $response
            ->assertSessionMissing('error')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('2023-06-01', $appointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame([2024, 2025, 2026], LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->orderBy('tahun')
            ->pluck('tahun')
            ->all());
        $this->assertSame($beforeLedger, LeaveBalanceLedger::query()->where('employee_id', $employee->id)->count());
        $this->assertSame($beforeAudit, AuditLog::query()
            ->where('auditable_type', 'LeaveBalance')
            ->whereIn('auditable_id', $balanceIds)
            ->count());
    }

    public function test_koreksi_tmt_membangun_predecessor_virtual_tanpa_carry_sebelum_anniversary(): void
    {
        [$employee, $appointment, $actor] = $this->employeeWithAppointment('2020-01-01');
        app(LeaveBalanceRecalculationService::class)->recalculate(
            $employee,
            2022,
            $actor,
            'Membentuk projection sebelum koreksi TMT ke anniversary yang lebih baru.',
        );
        $predecessorBefore = LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2023)
            ->firstOrFail()
            ->only(['id', 'jatah_awal', 'carry_over', 'sisa_n2', 'sisa_n1', 'sisa_tahun_berjalan', 'sisa']);

        $this->updateAppointment($employee, $actor, '2022-06-01')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $projection = LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2024)
            ->firstOrFail();

        $this->assertSame('2022-06-01', $appointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame($predecessorBefore, LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2023)
            ->firstOrFail()
            ->only(array_keys($predecessorBefore)));
        $this->assertSame(0, $projection->sisa_n2);
        $this->assertSame(6, $projection->sisa_n1);
        $this->assertSame(12, $projection->sisa_tahun_berjalan);
        $this->assertSame(18, $projection->sisa);
    }

    public function test_koreksi_tmt_mundur_membangun_horizon_virtual_saat_row_predecessor_tidak_ada(): void
    {
        [$employee, $appointment, $actor] = $this->employeeWithAppointment('2024-01-01');
        $projection = LeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 0,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 0,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 0,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);
        $ledgerBefore = LeaveBalanceLedger::query()->where('employee_id', $employee->id)->count();
        $auditBefore = AuditLog::query()
            ->where('auditable_type', 'LeaveBalance')
            ->where('auditable_id', $projection->id)
            ->count();

        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2025,
        ]);

        $this->updateAppointment($employee, $actor, '2022-06-01')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $projection->refresh();
        $this->assertSame('2022-06-01', $appointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame(6, $projection->sisa_n2);
        $this->assertSame(6, $projection->sisa_n1);
        $this->assertSame(12, $projection->sisa_tahun_berjalan);
        $this->assertSame(24, $projection->sisa);
        $this->assertSame([2026], LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->orderBy('tahun')
            ->pluck('tahun')
            ->all());
        $this->assertSame($ledgerBefore + 2, LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2026)
            ->count());
        $this->assertSame([
            LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED,
            LeaveBalanceLedger::EVENT_CARRY_OVER_EXPIRED,
        ], LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2026)
            ->orderBy('event_type')
            ->pluck('event_type')
            ->sort()
            ->values()
            ->all());
        $this->assertSame($auditBefore + 1, AuditLog::query()
            ->where('auditable_type', 'LeaveBalance')
            ->where('auditable_id', $projection->id)
            ->count());
    }

    public function test_koreksi_tmt_mundur_mengganti_predecessor_material_nonzero_yang_stale_dengan_replay_virtual(): void
    {
        [$employee, $appointment, $actor] = $this->employeeWithAppointment('2022-06-01');
        app(LeaveBalanceRecalculationService::class)->recalculate(
            $employee,
            2023,
            $actor,
            'Membentuk predecessor material berdasarkan TMT lama.',
        );
        $historical = LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2023)
            ->sole();
        $historicalBefore = $historical->getAttributes();
        $historicalLedgerBefore = LeaveBalanceLedger::query()
            ->where('leave_balance_id', $historical->id)
            ->count();
        $historicalAuditBefore = AuditLog::query()
            ->where('auditable_type', 'LeaveBalance')
            ->where('auditable_id', $historical->id)
            ->count();

        $this->assertSame(12, $historical->jatah_awal);
        $this->assertSame(0, $historical->sisa_n2);
        $this->assertSame(0, $historical->sisa_n1);
        $this->assertSame(12, $historical->sisa_tahun_berjalan);

        $this->updateAppointment($employee, $actor, '2020-01-01')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $projection = LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2024)
            ->sole();
        $this->assertSame('2020-01-01', $appointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame(6, $projection->sisa_n2);
        $this->assertSame(6, $projection->sisa_n1);
        $this->assertSame(12, $projection->carry_over);
        $this->assertSame(12, $projection->sisa_tahun_berjalan);
        $this->assertSame(24, $projection->sisa);
        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2021,
        ]);
        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2022,
        ]);
        $this->assertSame($historicalBefore, $historical->fresh()->getAttributes());
        $this->assertSame($historicalLedgerBefore, LeaveBalanceLedger::query()
            ->where('leave_balance_id', $historical->id)
            ->count());
        $this->assertSame($historicalAuditBefore, AuditLog::query()
            ->where('auditable_type', 'LeaveBalance')
            ->where('auditable_id', $historical->id)
            ->count());
    }

    public function test_koreksi_tmt_mundur_membangun_predecessor_virtual_dari_hak_dan_fakta_tanpa_mengubah_row_historis(): void
    {
        [$employee, $appointment, $actor] = $this->employeeWithAppointment('2024-01-01');
        app(LeaveBalanceRecalculationService::class)->recalculate(
            $employee,
            2023,
            $actor,
            'Membentuk row historis nol berdasarkan TMT lama.',
        );
        $annual = RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();
        LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $annual->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'usage_year' => 2023,
            'effective_date' => '2023-10-02',
            'start_date' => '2023-10-02',
            'end_date' => '2023-10-11',
            'workdays' => 8,
            'administrative_note' => 'Fakta FIFO predecessor virtual setelah TMT dimundurkan.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $actor->id,
        ]);
        $historical = LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2023)
            ->sole();
        $historicalBefore = $historical->getAttributes();
        $historicalLedgerBefore = LeaveBalanceLedger::query()
            ->where('leave_balance_id', $historical->id)
            ->count();
        $historicalAuditBefore = AuditLog::query()
            ->where('auditable_type', 'LeaveBalance')
            ->where('auditable_id', $historical->id)
            ->count();

        $this->updateAppointment($employee, $actor, '2022-06-01')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $projection = LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2024)
            ->sole();
        $this->assertSame('2022-06-01', $appointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame(0, $projection->sisa_n2);
        $this->assertSame(4, $projection->sisa_n1);
        $this->assertSame(12, $projection->sisa_tahun_berjalan);
        $this->assertSame(16, $projection->sisa);
        $this->assertSame($historicalBefore, $historical->fresh()->getAttributes());
        $this->assertSame($historicalLedgerBefore, LeaveBalanceLedger::query()
            ->where('leave_balance_id', $historical->id)
            ->count());
        $this->assertSame($historicalAuditBefore, AuditLog::query()
            ->where('auditable_type', 'LeaveBalance')
            ->where('auditable_id', $historical->id)
            ->count());
    }

    public function test_koreksi_tmt_mundur_membawa_hak_terlindungi_dari_predecessor_virtual(): void
    {
        [$employee, $appointment, $actor] = $this->employeeWithAppointment('2024-01-01');
        app(LeaveBalanceRecalculationService::class)->recalculate(
            $employee,
            2023,
            $actor,
            'Membentuk row historis nol sebelum validasi hak terlindungi.',
        );
        $historical = LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2023)
            ->sole();
        LeaveBalanceLedger::query()->create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $historical->id,
            'tahun' => 2023,
            'event_type' => LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED,
            'amount' => 4,
            'source_year' => 2023,
            'reason' => 'Fixture hak terlindungi predecessor virtual.',
            'dedup_key' => "tmt-virtual-protected:{$employee->id}",
            'metadata' => [
                'protected_days' => 4,
                'protected_allocations' => ['n2' => 0, 'n1' => 0, 'current' => 4],
            ],
            'created_by' => $actor->id,
            'occurred_at' => now(),
        ]);
        $this->updateAppointment($employee, $actor, '2022-06-01')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $projection = LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2024)
            ->sole();
        $this->assertSame('2022-06-01', $appointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame(10, $projection->sisa_n1);
        $this->assertSame(12, $projection->sisa_tahun_berjalan);
        $this->assertSame(22, $projection->sisa);
    }

    public function test_koreksi_tmt_mundur_gagal_atomik_bila_fakta_melampaui_hak_predecessor_virtual(): void
    {
        [$employee, $appointment, $actor] = $this->employeeWithAppointment('2024-01-01');
        app(LeaveBalanceRecalculationService::class)->recalculate(
            $employee,
            2023,
            $actor,
            'Membentuk row historis nol sebelum fakta invalid.',
        );
        $annual = RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();
        LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $annual->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'usage_year' => 2023,
            'effective_date' => '2023-10-02',
            'start_date' => '2023-10-02',
            'end_date' => '2023-10-18',
            'workdays' => 13,
            'administrative_note' => 'Fakta melampaui hak predecessor virtual.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $actor->id,
        ]);
        $beforeBalances = LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->orderBy('tahun')
            ->get()
            ->map->getAttributes()
            ->all();
        $beforeLedger = LeaveBalanceLedger::query()->where('employee_id', $employee->id)->count();
        $beforeAudit = AuditLog::query()->count();

        $this->updateAppointment($employee, $actor, '2022-06-01')
            ->assertSessionHas('error')
            ->assertSessionHas('error', fn (mixed $message): bool => is_string($message)
                && str_contains($message, 'Pemakaian 13 hari melebihi hak cuti'));

        $this->assertSame('2024-01-01', $appointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame($beforeBalances, LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->orderBy('tahun')
            ->get()
            ->map->getAttributes()
            ->all());
        $this->assertSame($beforeLedger, LeaveBalanceLedger::query()->where('employee_id', $employee->id)->count());
        $this->assertSame($beforeAudit, AuditLog::query()->count());
    }

    public function test_koreksi_tmt_menolak_fakta_di_atas_hak_predecessor_virtual_secara_atomik(): void
    {
        [$employee, $appointment, $actor] = $this->employeeWithAppointment('2020-01-01');
        app(LeaveBalanceRecalculationService::class)->recalculate(
            $employee,
            2022,
            $actor,
            'Membentuk projection sebelum regresi fakta melampaui hak.',
        );
        $annual = RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();
        LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $annual->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'usage_year' => 2024,
            'effective_date' => '2024-06-03',
            'start_date' => '2024-06-03',
            'end_date' => '2024-07-01',
            'workdays' => 20,
            'administrative_note' => 'Fakta 20 hari untuk regresi predecessor virtual.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $actor->id,
        ]);
        $beforeBalances = LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->orderBy('tahun')
            ->get()
            ->map->getAttributes()
            ->all();
        $beforeLedger = LeaveBalanceLedger::query()->where('employee_id', $employee->id)->count();
        $beforeAudit = AuditLog::query()->count();

        $this->updateAppointment($employee, $actor, '2022-06-01')
            ->assertSessionHas('error')
            ->assertSessionHas('error', fn (mixed $message): bool => is_string($message)
                && str_contains($message, 'Pemakaian 20 hari melebihi hak cuti'));

        $this->assertSame('2020-01-01', $appointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame($beforeBalances, LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->orderBy('tahun')
            ->get()
            ->map->getAttributes()
            ->all());
        $this->assertSame($beforeLedger, LeaveBalanceLedger::query()->where('employee_id', $employee->id)->count());
        $this->assertSame($beforeAudit, AuditLog::query()->count());
    }

    public function test_replay_generik_tetap_memperluas_horizon_ke_fakta_tahunan_lama(): void
    {
        [$employee, , $actor] = $this->employeeWithAppointment('2000-01-01');
        $annual = RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();
        LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $annual->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'usage_year' => 2010,
            'effective_date' => '2010-01-04',
            'start_date' => '2010-01-04',
            'end_date' => '2010-01-04',
            'workdays' => 1,
            'administrative_note' => 'Fakta historis yang tetap menjadi anchor replay generik.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $actor->id,
        ]);

        app(LeaveBalanceRecalculationService::class)->recalculate(
            $employee,
            2024,
            $actor,
            'Replay generik tetap memproses fakta backdated.',
        );

        $historical = LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2010)
            ->sole();
        $this->assertSame(1, $historical->terpakai);
        $this->assertSame(11, $historical->sisa);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2010,
            'event_type' => LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED,
        ]);
    }

    /** @return array{Employee, Appointment, User} */
    private function employeeWithAppointment(string $tmt): array
    {
        $pns = RefJenisPegawai::query()->where('nama', 'PNS')->firstOrFail();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $pns->id,
            'status_aktif' => 'Aktif',
        ]);
        $appointment = $employee->appointment()->create([
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => $tmt,
            'no_sk' => 'SK-PENGANGKATAN-AWAL',
            'tanggal_sk' => $tmt,
        ]);
        $actor = User::factory()->superAdmin()->create([
            'name' => 'Admin Koreksi TMT Bounded',
        ]);

        return [$employee, $appointment, $actor];
    }

    private function updateAppointment(Employee $employee, User $actor, string $tmt): TestResponse
    {
        $token = 'employee-appointment-leave-balance-bounded-replay-token';

        return $this->actingAs($actor)
            ->withSession(['_token' => $token])
            ->post(route('pegawai.update', $employee->id), [
                '_token' => $token,
                'nama_lengkap' => $employee->nama_lengkap,
                'nip' => $employee->nip,
                'email_pribadi' => $employee->email_pribadi,
                'pengangkatan_jenis_pengangkatan' => 'PNS',
                'pengangkatan_tmt_pengangkatan' => $tmt,
                'pengangkatan_no_sk' => 'SK-PENGANGKATAN-AWAL',
                'pengangkatan_tanggal_sk' => $tmt,
            ]);
    }
}
