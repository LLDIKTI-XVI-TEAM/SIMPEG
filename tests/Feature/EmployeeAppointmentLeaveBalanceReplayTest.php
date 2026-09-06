<?php

namespace Tests\Feature;

use App\Actions\Cuti\RolloverLeaveBalanceAction;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\User;
use App\Services\Cuti\AnnualLeaveBusinessClock;
use App\Services\Cuti\LeaveBalanceRecalculationService;
use App\Services\Cuti\LeaveBalanceReservationService;
use App\Services\Cuti\LeaveBalanceService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EmployeeAppointmentLeaveBalanceReplayTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_koreksi_tmt_ke_tanggal_belum_eligible_segera_mereplay_projection_tahun_berjalan(): void
    {
        [$employee, $appointment, $actor] = $this->employeeWithAppointment('2024-01-01');
        $this->replayBaseline($employee, $actor);
        $beforeLedger = $this->replayLedgerCount($employee);
        $beforeAudit = $this->replayAuditCount($employee);

        $this->assertSame(6, $this->currentBalance($employee)->carry_over);
        $this->assertSame(18, $this->currentBalance($employee)->sisa);

        $this->updateAppointment($employee, $actor, '2026-01-01')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $balance = $this->currentBalance($employee);
        $this->assertSame('2026-01-01', $appointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame(0, $balance->jatah_awal);
        $this->assertSame(0, $balance->carry_over);
        $this->assertSame(0, $balance->sisa);
        $this->assertSame(0, $balance->sisa_tahun_berjalan);
        $this->assertSame(0, app(LeaveBalanceService::class)->availableFor(
            $employee->fresh(),
            2026,
            Carbon::now(),
        ));
        $this->assertSame($beforeLedger + 1, $this->replayLedgerCount($employee));
        $this->assertSame($beforeAudit + 1, $this->replayAuditCount($employee));
    }

    public function test_koreksi_tmt_yang_tetap_eligible_mempertahankan_carry_material_tanpa_fakta_rekonsiliasi(): void
    {
        [$employee, $appointment, $actor] = $this->employeeWithAppointment('2023-01-01');
        $this->replayMaterialProjectionWithoutFacts($employee, $actor);
        app(RolloverLeaveBalanceAction::class)->execute(2025);

        $this->assertSame(0, LeaveUsageRecord::query()->whereBelongsTo($employee)->count());
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'event_type' => LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
            'source_year' => 2025,
        ]);
        $this->assertSame(12, $this->currentBalance($employee)->carry_over);
        $this->assertSame(24, $this->currentBalance($employee)->sisa);

        $this->updateAppointment($employee, $actor, '2023-06-01')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $balance = $this->currentBalance($employee);
        $this->assertSame('2023-06-01', $appointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame(12, $balance->jatah_awal);
        $this->assertSame(12, $balance->carry_over);
        $this->assertSame(24, $balance->sisa);
        $this->assertSame(6, $balance->sisa_n2);
        $this->assertSame(6, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(0, LeaveUsageRecord::query()->whereBelongsTo($employee)->count());
    }

    /** Tahun replay harus mengikuti 1 Januari WITA dan tidak menulis ulang projection N-3. */
    public function test_perubahan_kontrak_di_tahun_baru_wita_tidak_mereplay_projection_di_luar_horizon_n2_saat_timezone_aplikasi_utc(): void
    {
        $originalAppTimezone = (string) config('app.timezone');
        $originalPhpTimezone = date_default_timezone_get();
        $originalTestNow = Carbon::getTestNow();

        try {
            config(['app.timezone' => 'UTC']);
            date_default_timezone_set('UTC');
            Carbon::setTestNow(Carbon::create(2026, 12, 31, 16, 15, 0, 'UTC'));

            $this->assertSame(2026, now()->year);
            $this->assertSame(2027, app(AnnualLeaveBusinessClock::class)->currentYear());

            [$employee, , $actor] = $this->employeeWithPppkCeiling(
                '2020-01-01',
                '2028-01-02',
            );
            app(LeaveBalanceRecalculationService::class)->recalculate(
                $employee,
                2023,
                $actor,
                'Membentuk projection material lintas boundary tahun WITA.',
            );

            $historicalBalance = LeaveBalance::query()
                ->whereBelongsTo($employee)
                ->where('tahun', 2024)
                ->sole();
            $historicalLedgerCount = LeaveBalanceLedger::query()
                ->where('employee_id', $employee->id)
                ->where('tahun', 2024)
                ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
                ->count();
            $historicalAuditCount = AuditLog::query()
                ->where('auditable_type', 'LeaveBalance')
                ->where('auditable_id', $historicalBalance->id)
                ->where('event', 'UPDATE')
                ->count();

            $this->assertSame([12, 12, 6, 6, 12, 24, 12], $this->balanceBucketSnapshots($employee)[2024]);

            $this->updatePppkContractEnd($employee, $actor, '2022-01-01')
                ->assertSessionHasNoErrors()
                ->assertRedirect();

            $snapshots = $this->balanceBucketSnapshots($employee);
            $this->assertSame([12, 12, 6, 6, 12, 24, 12], $snapshots[2024]);
            $this->assertSame([12, 0, 0, 0, 12, 12, 12], $snapshots[2027]);
            $this->assertSame($historicalLedgerCount, LeaveBalanceLedger::query()
                ->where('employee_id', $employee->id)
                ->where('tahun', 2024)
                ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
                ->count());
            $this->assertSame($historicalAuditCount, AuditLog::query()
                ->where('auditable_type', 'LeaveBalance')
                ->where('auditable_id', $historicalBalance->id)
                ->where('event', 'UPDATE')
                ->count());
        } finally {
            config(['app.timezone' => $originalAppTimezone]);
            date_default_timezone_set($originalPhpTimezone);
            Carbon::setTestNow($originalTestNow);
        }
    }

    public function test_koreksi_tmt_memakai_predecessor_material_untuk_opening_horizon_aktif(): void
    {
        [$employee, $appointment, $actor] = $this->employeeWithAppointment('2020-01-01');
        $annual = RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();
        LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $annual->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'usage_year' => 2024,
            'effective_date' => '2024-06-03',
            'start_date' => '2024-06-03',
            'end_date' => '2024-06-06',
            'workdays' => 4,
            'administrative_note' => 'Fakta 2024 mengonsumsi carry tertua untuk regresi predecessor.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $actor->id,
        ]);
        app(LeaveBalanceRecalculationService::class)->recalculate(
            $employee,
            2023,
            $actor,
            'Membentuk predecessor material sebelum koreksi TMT.',
        );
        $request = $this->activeAnnualRequest($employee, 3);
        app(LeaveBalanceReservationService::class)->reserveForNewRequest($request, $actor);

        $expected = [
            2023 => [12, 12, 6, 6, 12, 24, 6],
            2024 => [12, 12, 2, 6, 12, 20, 12],
            2025 => [12, 6, 0, 6, 12, 18, 14],
            2026 => [12, 6, 0, 6, 12, 18, 12],
        ];
        $this->assertSame($expected, $this->balanceBucketSnapshots($employee));

        $this->updateAppointment($employee, $actor, '2020-06-01')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('2020-06-01', $appointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame($expected, $this->balanceBucketSnapshots($employee));
        $this->assertSame(3, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $request->id)
            ->sum('amount'));
        $this->assertSame(18, app(LeaveBalanceService::class)->availableFor(
            $employee->fresh(),
            2026,
            Carbon::now(),
        ));
    }

    public function test_koreksi_tmt_tetap_memakai_predecessor_saat_snapshot_pemakaian_aktif_tersedia(): void
    {
        [$employee, $appointment, $actor] = $this->employeeWithAppointment('2020-01-01');
        app(LeaveBalanceRecalculationService::class)->recalculate(
            $employee,
            2023,
            $actor,
            'Membentuk predecessor sebelum snapshot pemakaian aktif.',
        );
        $this->recordHistoricalManualAnnualUsage($employee, $actor, 2024, 4);

        $this->assertSame(1, LeaveUsageRecord::query()->whereBelongsTo($employee)->count());

        $this->updateAppointment($employee, $actor, '2020-06-01')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('2020-06-01', $appointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame([
            2023 => [12, 12, 6, 6, 12, 24, 6],
            2024 => [12, 12, 2, 6, 12, 20, 12],
            2025 => [12, 6, 0, 6, 12, 18, 14],
            2026 => [12, 6, 0, 6, 12, 18, 12],
        ], $this->balanceBucketSnapshots($employee));
    }

    public function test_koreksi_tmt_memvalidasi_reservasi_aktif_pada_tahun_material_sumber(): void
    {
        [$employee, $appointment, $actor] = $this->employeeWithAppointment('2023-01-01');
        $this->replayMaterialProjectionWithoutFacts($employee, $actor);
        $request = $this->activeAnnualRequest($employee, 3, '2025-09-01');
        app(LeaveBalanceReservationService::class)->reserveForNewRequest($request, $actor);

        $this->assertSame(0, LeaveUsageRecord::query()->whereBelongsTo($employee)->count());
        $this->assertSame(3, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $request->id)
            ->where('tahun', 2025)
            ->sum('amount'));

        $this->updateAppointment($employee, $actor, '2025-01-01')
            ->assertSessionHas('error')
            ->assertSessionHas('error', fn (mixed $message): bool => is_string($message)
                && str_contains($message, '2025')
                && str_contains($message, 'lebih kecil dari reservasi aktif 3 hari'));

        $this->assertSame('2023-01-01', $appointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame(18, LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2025)
            ->sole()
            ->sisa);
        $this->assertSame(24, $this->currentBalance($employee)->sisa);
        $this->assertSame(3, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $request->id)
            ->where('tahun', 2025)
            ->sum('amount'));
        $this->assertSame('menunggu_approval', $request->fresh()->status);
    }

    public function test_koreksi_tmt_gagal_atomik_bila_projection_baru_tidak_mencukupi_reservasi_aktif(): void
    {
        [$employee, $appointment, $actor] = $this->employeeWithAppointment('2024-01-01');
        $this->replayBaseline($employee, $actor);
        $request = $this->activeAnnualRequest($employee, 3);
        app(LeaveBalanceReservationService::class)->reserveForNewRequest($request, $actor);
        $beforeLedger = $this->replayLedgerCount($employee);
        $beforeAudit = $this->replayAuditCount($employee);

        $this->updateAppointment($employee, $actor, '2026-01-01')
            ->assertSessionHas('error')
            ->assertSessionHas('error', fn (mixed $message): bool => is_string($message)
                && str_contains($message, 'lebih kecil dari reservasi aktif 3 hari'));

        $this->assertSame('2024-01-01', $appointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame(18, $this->currentBalance($employee)->sisa);
        $this->assertSame(3, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $request->id)
            ->sum('amount'));
        $this->assertSame('menunggu_approval', $request->fresh()->status);
        $this->assertSame($beforeLedger, $this->replayLedgerCount($employee));
        $this->assertSame($beforeAudit, $this->replayAuditCount($employee));
    }

    public function test_koreksi_tmt_ke_tanggal_eligible_membentuk_hak_dan_carry_dari_tahun_eligible(): void
    {
        [$employee, $appointment, $actor] = $this->employeeWithAppointment('2026-01-01');
        $this->replayBaseline($employee, $actor);

        $this->assertSame(0, $this->currentBalance($employee)->sisa);

        $this->updateAppointment($employee, $actor, '2024-01-01')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $balance = $this->currentBalance($employee);
        $this->assertSame('2024-01-01', $appointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame(12, $balance->jatah_awal);
        $this->assertSame(18, $balance->sisa);
        $this->assertSame(6, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(18, app(LeaveBalanceService::class)->availableFor(
            $employee->fresh(),
            2026,
            Carbon::now(),
        ));
    }

    public function test_mutasi_tmt_yang_bukan_paling_awal_tidak_membuat_noise_replay(): void
    {
        [$employee, $pppkAppointment, $actor] = $this->employeeWithNonEarliestPppkAppointment();
        $this->replayBaseline($employee, $actor);
        $beforeLedger = $this->replayLedgerCount($employee);
        $beforeAudit = $this->replayAuditCount($employee);

        $this->updatePppkAppointment($employee, $actor, '2025-06-01')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('2025-06-01', $pppkAppointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame(12, $this->currentBalance($employee)->sisa);
        $this->assertSame($beforeLedger, $this->replayLedgerCount($employee));
        $this->assertSame($beforeAudit, $this->replayAuditCount($employee));
    }

    public function test_perubahan_tanggal_akhir_kontrak_pppk_mereplay_projection_sesuai_plafon_baru(): void
    {
        [$employee, $pppkAppointment, $actor] = $this->employeeWithPppkCeiling(
            '2023-06-01',
            '2027-06-02',
        );
        $this->replayBaselineWithUsageHistory($employee, $actor);
        $beforeLedger = $this->replayLedgerCount($employee);
        $beforeAudit = $this->replayAuditCount($employee);
        $usageBefore = $this->usageFactSnapshot($employee);

        $this->assertSame(18, $this->currentBalance($employee)->sisa);

        $this->updatePppkContractEnd($employee, $actor, '2026-05-31')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $balance = $this->currentBalance($employee);
        $this->assertSame('2026-05-31', $employee->fresh()->tanggal_akhir_kontrak?->toDateString());
        $this->assertSame('2023-06-01', $pppkAppointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame(12, $balance->jatah_awal);
        $this->assertSame(6, $balance->carry_over);
        $this->assertSame(18, $balance->sisa);
        $this->assertSame(0, $balance->sisa_n2);
        $this->assertSame(6, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(18, app(LeaveBalanceService::class)->availableFor(
            $employee->fresh(),
            2026,
            Carbon::now(),
        ));
        $this->assertSame($beforeLedger, $this->replayLedgerCount($employee));
        $this->assertSame($beforeAudit, $this->replayAuditCount($employee));
        $this->assertSame($usageBefore, $this->usageFactSnapshot($employee));
    }

    public function test_mutasi_tmt_pppk_non_earliest_yang_mengubah_plafon_mereplay_projection(): void
    {
        [$employee, $pppkAppointment, $actor] = $this->employeeWithNonEarliestPppkAppointment(
            '2020-01-01',
            '2022-01-01',
            '2026-06-01',
        );
        $this->replayBaselineWithUsageHistory($employee, $actor);
        $beforeLedger = $this->replayLedgerCount($employee);
        $beforeAudit = $this->replayAuditCount($employee);
        $usageBefore = $this->usageFactSnapshot($employee);

        $this->assertSame(18, $this->currentBalance($employee)->sisa);
        $this->assertSame(12, $this->currentBalance($employee)->hangus);

        $this->updatePppkAppointment($employee, $actor, '2023-07-01')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $balance = $this->currentBalance($employee);
        $this->assertSame('2023-07-01', $pppkAppointment->fresh()->tmt_pengangkatan?->toDateString());
        $this->assertSame('2026-06-01', $employee->fresh()->tanggal_akhir_kontrak?->toDateString());
        $this->assertSame(12, $balance->jatah_awal);
        $this->assertSame(6, $balance->carry_over);
        $this->assertSame(18, $balance->sisa);
        $this->assertSame(0, $balance->sisa_n2);
        $this->assertSame(6, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(18, app(LeaveBalanceService::class)->availableFor(
            $employee->fresh(),
            2026,
            Carbon::now(),
        ));
        // Plafon baru mengubah carry pendahulu dan expiry meski total saldo akhir tetap sama.
        $this->assertSame(6, $balance->hangus);
        $this->assertSame($beforeLedger + 1, $this->replayLedgerCount($employee));
        $this->assertSame($beforeAudit + 1, $this->replayAuditCount($employee));
        $this->assertSame($usageBefore, $this->usageFactSnapshot($employee));
    }

    public function test_perubahan_jenis_pengangkatan_ke_pppk_mereplay_projection_ke_plafon_tertutup(): void
    {
        [$employee, $appointment, $actor] = $this->employeeWithAppointment('2020-01-01');
        $this->replayBaselineWithUsageHistory($employee, $actor);
        $beforeLedger = $this->replayLedgerCount($employee);
        $beforeAudit = $this->replayAuditCount($employee);
        $usageBefore = $this->usageFactSnapshot($employee);

        $this->assertSame(18, $this->currentBalance($employee)->sisa);

        $this->updateAppointment($employee, $actor, '2020-01-01', 'SK-PENGANGKATAN-PPPK', 'PPPK')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $balance = $this->currentBalance($employee);
        $this->assertSame('PPPK', $appointment->fresh()->jenis_pengangkatan);
        $this->assertSame('PPPK', $employee->fresh()->jenisPegawai?->nama);
        $this->assertNull($employee->fresh()->tanggal_akhir_kontrak);
        $this->assertSame(12, $balance->jatah_awal);
        $this->assertSame(0, $balance->carry_over);
        $this->assertSame(12, $balance->sisa);
        $this->assertSame(0, $balance->sisa_n2);
        $this->assertSame(0, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(12, app(LeaveBalanceService::class)->availableFor(
            $employee->fresh(),
            2026,
            Carbon::now(),
        ));
        $this->assertSame($beforeLedger + 1, $this->replayLedgerCount($employee));
        $this->assertSame($beforeAudit + 1, $this->replayAuditCount($employee));
        $this->assertSame($usageBefore, $this->usageFactSnapshot($employee));
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
        $actor = User::factory()->create([
            'role' => 'super_admin',
            'name' => 'Admin Koreksi TMT',
        ]);

        return [$employee, $appointment, $actor];
    }

    /** @return array{Employee, Appointment, User} */
    private function employeeWithNonEarliestPppkAppointment(
        string $earliestTmt = '2024-01-01',
        string $pppkTmt = '2025-01-01',
        ?string $contractEnd = null,
    ): array {
        $pppk = RefJenisPegawai::query()->where('nama', 'PPPK')->firstOrFail();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $pppk->id,
            'status_aktif' => 'Aktif',
            'tanggal_akhir_kontrak' => $contractEnd,
        ]);
        $employee->appointments()->create([
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => $earliestTmt,
            'no_sk' => 'SK-PENGANGKATAN-TERAWAL',
            'tanggal_sk' => $earliestTmt,
        ]);
        $pppkAppointment = $employee->appointments()->create([
            'jenis_pengangkatan' => 'PPPK',
            'tmt_pengangkatan' => $pppkTmt,
            'no_sk' => 'SK-PENGANGKATAN-PPPK',
            'tanggal_sk' => $pppkTmt,
        ]);
        $actor = User::factory()->create([
            'role' => 'super_admin',
            'name' => 'Admin Koreksi TMT',
        ]);

        return [$employee, $pppkAppointment, $actor];
    }

    /** @return array{Employee, Appointment, User} */
    private function employeeWithPppkCeiling(string $pppkTmt, string $contractEnd): array
    {
        $pppk = RefJenisPegawai::query()->where('nama', 'PPPK')->firstOrFail();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $pppk->id,
            'status_aktif' => 'Aktif',
            'tanggal_akhir_kontrak' => $contractEnd,
        ]);
        $appointment = $employee->appointments()->create([
            'jenis_pengangkatan' => 'PPPK',
            'tmt_pengangkatan' => $pppkTmt,
            'no_sk' => 'SK-PENGANGKATAN-PPPK-PLAFON',
            'tanggal_sk' => $pppkTmt,
        ]);
        $actor = User::factory()->create([
            'role' => 'super_admin',
            'name' => 'Admin Koreksi Plafon PPPK',
        ]);

        return [$employee, $appointment, $actor];
    }

    private function replayBaseline(Employee $employee, User $actor): void
    {
        app(LeaveBalanceRecalculationService::class)->recalculate(
            $employee,
            2026,
            $actor,
            'Membentuk projection awal untuk pengujian koreksi TMT.',
        );
    }

    private function replayMaterialProjectionWithoutFacts(Employee $employee, User $actor): void
    {
        app(LeaveBalanceRecalculationService::class)->recalculate(
            $employee,
            2025,
            $actor,
            'Membentuk projection material tanpa fakta rekonsiliasi.',
        );
    }

    private function replayBaselineWithUsageHistory(Employee $employee, User $actor): void
    {
        $this->recordHistoricalManualAnnualUsage($employee, $actor, 2025, 6);
        $this->replayBaseline($employee, $actor);
    }

    private function activeAnnualRequest(
        Employee $employee,
        int $workdays,
        string $start = '2026-09-01',
    ): LeaveRequest {
        $annual = RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();
        $end = Carbon::parse($start)->addDays($workdays - 1)->toDateString();

        return LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $annual->id,
            'tanggal_mulai' => $start,
            'tanggal_selesai' => $end,
            'jumlah_hari_kerja' => $workdays,
            'alasan' => 'Pengajuan aktif untuk pengujian koreksi TMT.',
            'status' => 'menunggu_approval',
        ])->load('jenisCuti');
    }

    private function updateAppointment(
        Employee $employee,
        User $actor,
        string $tmt,
        string $number = 'SK-PENGANGKATAN-AWAL',
        string $type = 'PNS',
    ): TestResponse {
        $token = 'employee-appointment-leave-balance-replay-token';

        return $this->actingAs($actor)
            ->withSession(['_token' => $token])
            ->post(route('pegawai.update', $employee->id), [
                '_token' => $token,
                'nama_lengkap' => $employee->nama_lengkap,
                'nip' => $employee->nip,
                'email_pribadi' => $employee->email_pribadi,
                'pengangkatan_jenis_pengangkatan' => $type,
                'pengangkatan_tmt_pengangkatan' => $tmt,
                'pengangkatan_no_sk' => $number,
                'pengangkatan_tanggal_sk' => $tmt,
            ]);
    }

    private function updatePppkAppointment(Employee $employee, User $actor, string $tmt): TestResponse
    {
        $token = 'employee-appointment-leave-balance-replay-token';

        return $this->actingAs($actor)
            ->withSession(['_token' => $token])
            ->post(route('pegawai.update', $employee->id), [
                '_token' => $token,
                'nama_lengkap' => $employee->nama_lengkap,
                'nip' => $employee->nip,
                'email_pribadi' => $employee->email_pribadi,
                'pppk_tmt_pengangkatan' => $tmt,
            ]);
    }

    private function updatePppkContractEnd(Employee $employee, User $actor, string $contractEnd): TestResponse
    {
        $token = 'employee-appointment-leave-balance-replay-token';

        return $this->actingAs($actor)
            ->withSession(['_token' => $token])
            ->post(route('pegawai.update', $employee->id), [
                '_token' => $token,
                'nama_lengkap' => $employee->nama_lengkap,
                'nip' => $employee->nip,
                'email_pribadi' => $employee->email_pribadi,
                'tanggal_akhir_kontrak' => $contractEnd,
            ]);
    }

    private function currentBalance(Employee $employee): LeaveBalance
    {
        return LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2026)
            ->firstOrFail();
    }

    /** @return array<int, array{int, int, int, int, int, int, int}> */
    private function balanceBucketSnapshots(Employee $employee): array
    {
        return LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->orderBy('tahun')
            ->get()
            ->mapWithKeys(fn (LeaveBalance $balance): array => [
                $balance->tahun => [
                    $balance->jatah_awal,
                    $balance->carry_over,
                    $balance->sisa_n2,
                    $balance->sisa_n1,
                    $balance->sisa_tahun_berjalan,
                    $balance->sisa,
                    $balance->hangus,
                ],
            ])
            ->all();
    }

    private function replayLedgerCount(Employee $employee): int
    {
        return LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2026)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->count();
    }

    private function replayAuditCount(Employee $employee): int
    {
        $balanceId = LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2026)
            ->value('id');

        return AuditLog::query()
            ->where('auditable_type', 'LeaveBalance')
            ->where('auditable_id', $balanceId)
            ->where('event', 'UPDATE')
            ->count();
    }

    /** @return array<int, array<string, mixed>> */
    private function usageFactSnapshot(Employee $employee): array
    {
        return LeaveUsageRecord::query()
            ->whereBelongsTo($employee)
            ->orderBy('id')
            ->get([
                'id',
                'usage_year',
                'workdays',
                'record_status',
            ])
            ->toArray();
    }

    private function recordHistoricalManualAnnualUsage(Employee $employee, User $actor, int $year, int $workdays): void
    {
        $annual = RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();
        $date = sprintf('%d-06-01', $year);

        LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $annual->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'usage_year' => $year,
            'effective_date' => $date,
            'start_date' => $date,
            'end_date' => $date,
            'workdays' => $workdays,
            'administrative_note' => 'Fakta manual historis untuk regresi koreksi TMT.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $actor->id,
        ]);
    }
}
