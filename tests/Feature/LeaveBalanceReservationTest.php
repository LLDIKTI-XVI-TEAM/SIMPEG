<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Queries\Cuti\LeaveBalanceAdminEmployeeQuery;
use App\Services\Cuti\LeaveBalanceRecalculationService;
use App\Services\Cuti\LeaveBalanceReservationService;
use App\Services\Cuti\LeaveBalanceService;
use App\Services\Cuti\LeaveUsageRecordService;
use App\Services\LeaveApprovalService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Mengunci kebijakan alokasi pengajuan aktif tanpa mengubah saldo final.
 */
class LeaveBalanceReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array{
     *     user: User,
     *     employee: Employee,
     *     supervisor: Employee,
     *     supervisor_user: User,
     *     pybmc: Employee,
     *     pybmc_user: User
     * }
     */
    private function makePemohon(): array
    {
        $jenisPegawai = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $jenisPegawai->id]);
        $supervisor = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $supervisorUser = User::factory()->kepalaBagian()->create(['employee_id' => $supervisor->id]);
        $pybmcUser = User::factory()->pimpinan()->create(['employee_id' => $pybmc->id]);

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2024-01-01',
            'no_sk' => 'SK-RESERVASI-001',
            'tanggal_sk' => '2024-01-01',
        ]);
        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'supervisor_id' => $supervisor->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);

        $chain = LeaveApprovalChain::create([
            'employee_id' => $employee->id,
            'name' => 'Chain reservasi saldo',
            'effective_from' => '2026-01-01',
            'change_reason' => 'Setup pengujian reservasi.',
        ]);
        $chain->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $supervisor->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $pybmc->id,
                'is_final' => true,
            ],
        ]);

        LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);

        return [
            'user' => $user,
            'employee' => $employee,
            'supervisor' => $supervisor,
            'supervisor_user' => $supervisorUser,
            'pybmc' => $pybmc,
            'pybmc_user' => $pybmcUser,
        ];
    }

    private function annualLeaveType(): RefJenisCuti
    {
        return RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
    }

    private function sickLeaveType(): RefJenisCuti
    {
        return RefJenisCuti::create([
            'nama' => 'Cuti Sakit',
            'code' => 'sakit',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
    }

    /** Membentuk fakta Cuti Besar final melalui jalur approved-request kanonis. */
    private function recordApprovedLargeUsage(Employee $employee, User $actor, string $name): LeaveRequest
    {
        $large = RefJenisCuti::create([
            'nama' => $name,
            'code' => 'besar',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => true,
        ]);
        $request = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $large->id,
            'tanggal_mulai' => '2026-03-02',
            'tanggal_selesai' => '2026-03-31',
            'jumlah_hari_kerja' => 20,
            'alasan' => 'Cuti Besar final.',
            'status' => 'disetujui',
        ]);
        $fact = app(LeaveUsageRecordService::class)->recordApprovedRequest($request, $actor);

        $this->assertSame(LeaveUsageRecord::SOURCE_APPROVED_REQUEST, $fact->source_type);
        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $fact->record_status);

        return $request;
    }

    /** @return array<string, string> */
    private function payload(RefJenisCuti $jenis, string $mulai = '2026-07-06', string $selesai = '2026-07-10'): array
    {
        return [
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => $mulai,
            'tanggal_selesai' => $selesai,
            'alasan' => 'Keperluan keluarga.',
            'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 1, Manado',
            'nomor_telepon' => '+62 (431) 123-456',
        ];
    }

    /**
     * @param  array{user:User,employee:Employee,supervisor:Employee,supervisor_user:User,pybmc:Employee,pybmc_user:User}|null  $actors
     * @return array{request: LeaveRequest, actor: User, balance: LeaveBalance}
     */
    private function makeReservedRequest(
        int $reservedDays = 5,
        ?RefJenisCuti $jenis = null,
        string $mulai = '2026-07-06',
        string $selesai = '2026-07-10',
        int $workdays = 5,
        ?array $actors = null,
    ): array {
        $aktor = $actors ?? $this->makePemohon();
        $jenis ??= $this->annualLeaveType();
        $balance = LeaveBalance::query()
            ->where('employee_id', $aktor['employee']->id)
            ->where('tahun', 2026)
            ->firstOrFail();
        $request = LeaveRequest::create([
            'employee_id' => $aktor['employee']->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => $mulai,
            'tanggal_selesai' => $selesai,
            'jumlah_hari_kerja' => $workdays,
            'alasan' => 'Pengajuan dengan reservasi aktif.',
            'status' => 'menunggu_approval',
        ])->load('jenisCuti');

        if ($reservedDays !== 0) {
            LeaveBalanceReservationEvent::create([
                'employee_id' => $aktor['employee']->id,
                'leave_request_id' => $request->id,
                'leave_balance_id' => $balance->id,
                'tahun' => 2026,
                'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
                'amount' => $reservedDays,
                'reason' => 'Hak cuti tahunan dialokasikan untuk pengajuan aktif.',
                'dedup_key' => "leave_reservation:{$request->id}:reserved",
                'metadata' => ['requested_days' => $workdays],
                'created_by' => $aktor['user']->id,
                'occurred_at' => Carbon::parse('2026-07-01 08:00:00'),
            ]);
        }

        return ['request' => $request, 'actor' => $aktor['user'], 'balance' => $balance];
    }

    public function test_duty_postponement_releases_exact_reservation_once_without_reservation_audit(): void
    {
        $fixture = $this->makeReservedRequest();
        $service = app(LeaveBalanceReservationService::class);

        $first = $service->releaseForDutyPostponement($fixture['request'], $fixture['actor']);
        $second = $service->releaseForDutyPostponement($fixture['request'], $fixture['actor']);

        $this->assertNotNull($first);
        $this->assertTrue($first->is($second));
        $this->assertSame(LeaveBalanceReservationEvent::EVENT_RELEASED, $first->event_type);
        $this->assertSame(-5, $first->amount);
        $this->assertSame("leave_reservation:{$fixture['request']->id}:released:duty_postponement:2026", $first->dedup_key);
        $this->assertSame('duty_postponement_terminal', $first->metadata['release_context']);
        $this->assertSame($fixture['request']->employee_id, $first->employee_id);
        $this->assertSame($fixture['request']->id, $first->leave_request_id);
        $this->assertSame($fixture['balance']->id, $first->leave_balance_id);
        $this->assertSame($fixture['actor']->id, $first->created_by);
        $this->assertSame(2026, $first->tahun);
        $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->sum('amount'));
        $this->assertSame(1, LeaveBalanceReservationEvent::query()
            ->where('dedup_key', $first->dedup_key)
            ->count());
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'LEAVE_BALANCE_RESERVATION_RELEASED',
            'auditable_type' => 'LeaveBalanceReservationEvent',
        ]);
    }

    public function test_rollover_return_releases_source_reservation_once_with_dedicated_metadata_and_audit(): void
    {
        $fixture = $this->makeReservedRequest();
        $service = app(LeaveBalanceReservationService::class);

        $first = $service->releaseForRollover($fixture['request'], 2026, 2027);
        $second = $service->releaseForRollover($fixture['request'], 2026, 2027);

        $this->assertTrue($first->is($second));
        $this->assertSame(LeaveBalanceReservationEvent::EVENT_RELEASED, $first->event_type);
        $this->assertSame(-5, $first->amount);
        $this->assertSame("leave_reservation:{$fixture['request']->id}:released:rollover:2026", $first->dedup_key);
        $this->assertSame([
            'release_context' => 'rollover_return',
            'source_year' => 2026,
            'target_year' => 2027,
        ], $first->metadata);
        $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->sum('amount'));
        $this->assertSame(1, LeaveBalanceReservationEvent::query()
            ->where('dedup_key', $first->dedup_key)
            ->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'LEAVE_BALANCE_RESERVATION_RELEASED',
            'auditable_type' => 'LeaveBalanceReservationEvent',
            'auditable_id' => $first->id,
        ]);
    }

    public function test_duty_postponement_release_rejects_non_annual_request(): void
    {
        $fixture = $this->makeReservedRequest(jenis: $this->sickLeaveType());

        $this->expectException(ValidationException::class);

        app(LeaveBalanceReservationService::class)
            ->releaseForDutyPostponement($fixture['request'], $fixture['actor']);
    }

    public function test_duty_postponement_release_rejects_missing_or_partial_active_reservation(): void
    {
        $jenis = $this->annualLeaveType();

        foreach ([0, 3] as $reservedDays) {
            $fixture = $this->makeReservedRequest(reservedDays: $reservedDays, jenis: $jenis);

            try {
                app(LeaveBalanceReservationService::class)
                    ->releaseForDutyPostponement($fixture['request'], $fixture['actor']);
                $this->fail("Reservasi aktif {$reservedDays} hari seharusnya ditolak.");
            } catch (ValidationException) {
                $this->assertSame($reservedDays, (int) LeaveBalanceReservationEvent::query()
                    ->where('leave_request_id', $fixture['request']->id)
                    ->sum('amount'));
                $this->assertDatabaseMissing('leave_balance_reservation_events', [
                    'leave_request_id' => $fixture['request']->id,
                    'event_type' => LeaveBalanceReservationEvent::EVENT_RELEASED,
                ]);
            }
        }
    }

    public function test_duty_postponement_release_rejects_non_positive_or_cross_year_request(): void
    {
        $jenis = $this->annualLeaveType();

        foreach ([
            ['mulai' => '2026-07-06', 'selesai' => '2026-07-06', 'workdays' => 0],
            ['mulai' => '2026-12-31', 'selesai' => '2027-01-04', 'workdays' => 2],
        ] as $case) {
            $fixture = $this->makeReservedRequest(
                reservedDays: max(1, $case['workdays']),
                jenis: $jenis,
                mulai: $case['mulai'],
                selesai: $case['selesai'],
                workdays: $case['workdays'],
            );

            try {
                app(LeaveBalanceReservationService::class)
                    ->releaseForDutyPostponement($fixture['request'], $fixture['actor']);
                $this->fail('Rentang pengajuan tidak valid seharusnya ditolak.');
            } catch (ValidationException) {
                $this->assertDatabaseMissing('leave_balance_reservation_events', [
                    'leave_request_id' => $fixture['request']->id,
                    'event_type' => LeaveBalanceReservationEvent::EVENT_RELEASED,
                ]);
            }
        }
    }

    public function test_duty_postponement_release_rejects_corrupted_existing_dedup_event(): void
    {
        $fixture = $this->makeReservedRequest();
        LeaveBalanceReservationEvent::create([
            'employee_id' => $fixture['request']->employee_id,
            'leave_request_id' => $fixture['request']->id,
            'leave_balance_id' => $fixture['balance']->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RELEASED,
            'amount' => -4,
            'reason' => 'Event rusak untuk pengujian fail-closed.',
            'dedup_key' => "leave_reservation:{$fixture['request']->id}:released:duty_postponement:2026",
            'metadata' => ['release_context' => 'wrong_context'],
            'created_by' => $fixture['actor']->id,
            'occurred_at' => Carbon::parse('2026-07-01 09:00:00'),
        ]);

        $this->expectException(ValidationException::class);

        app(LeaveBalanceReservationService::class)
            ->releaseForDutyPostponement($fixture['request'], $fixture['actor']);
    }

    public function test_submit_reserves_active_annual_leave_and_blocks_combined_requests_above_balance(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->annualLeaveType();

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($jenis, '2026-07-06', '2026-07-14'))
            ->assertRedirect(route('cuti'));

        $firstRequest = LeaveRequest::query()->firstOrFail();
        $this->assertSame(7, $firstRequest->jumlah_hari_kerja);
        $this->assertDatabaseHas('leave_balance_reservation_events', [
            'leave_request_id' => $firstRequest->id,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
            'amount' => 7,
            'tahun' => 2026,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'LEAVE_BALANCE_RESERVED',
            'auditable_type' => 'LeaveBalanceReservationEvent',
        ]);

        $balance = LeaveBalance::query()->where('employee_id', $aktor['employee']->id)->firstOrFail();
        $this->assertSame(12, $balance->sisa);
        $this->assertSame(0, $balance->terpakai);
        $this->assertSame(0, LeaveBalanceLedger::query()->count());

        $preview = app(LeaveBalanceService::class)->previewFor($aktor['employee'], Carbon::parse('2026-07-20'));
        $this->assertSame(12, $preview['saldo_aktual']);
        $this->assertSame(7, $preview['dialokasikan_aktif']);
        $this->assertSame(5, $preview['saldo_dapat_diajukan']);

        $this->actingAs($aktor['user'])
            ->postJson(route('cuti.store'), $this->payload($jenis, '2026-07-20', '2026-07-27'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tanggal_selesai']);

        $this->assertDatabaseCount('leave_requests', 1);
        $this->assertSame(7, (int) LeaveBalanceReservationEvent::query()->sum('amount'));
    }

    public function test_seluruh_consumer_reservasi_aktif_mengabaikan_event_non_tahunan_tanpa_menunggu_rekonsiliasi(): void
    {
        Carbon::setTestNow('2026-08-23 09:00:00');
        $aktor = $this->makePemohon();
        $this->makeReservedRequest(3, $this->annualLeaveType(), actors: $aktor);
        $this->makeReservedRequest(20, $this->sickLeaveType(), actors: $aktor);
        $tanggalAcuan = Carbon::parse('2026-08-23');

        $this->assertSame(
            9,
            app(LeaveBalanceReservationService::class)
                ->availableForSubmission($aktor['employee'], 2026, $tanggalAcuan),
        );

        $preview = app(LeaveBalanceService::class)->previewFor($aktor['employee'], $tanggalAcuan);
        $this->assertSame(3, $preview['dialokasikan_aktif']);
        $this->assertSame(9, $preview['saldo_dapat_diajukan']);

        $workspace = app(LeaveBalanceAdminEmployeeQuery::class)
            ->selectedWorkspace($aktor['employee']->id, 2026);
        $this->assertSame(3, $workspace['activeReserved']);

        app(LeaveBalanceRecalculationService::class)->recalculateForDatabaseUpgrade(
            $aktor['employee'],
            2026,
            'Memastikan reservasi non-tahunan tidak memblokir rekalkulasi.',
        );

        $this->assertSame(12, (int) LeaveBalance::query()
            ->where('employee_id', $aktor['employee']->id)
            ->where('tahun', 2026)
            ->value('sisa'));
    }

    public function test_resubmit_recalculates_reservation_without_changing_final_balance(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->annualLeaveType();

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($jenis))
            ->assertRedirect(route('cuti'));

        $leaveRequest = LeaveRequest::query()->firstOrFail();

        $this->actingAs($aktor['user'])
            ->patch(route('cuti.resubmit', $leaveRequest), [
                'revision_version' => $leaveRequest->fresh()->revision_version,
                'tanggal_mulai' => '2026-07-13',
                'tanggal_selesai' => '2026-07-15',
                'alasan' => 'Tanggal telah disesuaikan.',
                'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 2, Manado',
                'nomor_telepon' => '+62 (431) 123-457',
            ])
            ->assertRedirect(route('cuti.show', $leaveRequest));

        $leaveRequest->refresh();
        $this->assertSame('menunggu_approval', $leaveRequest->status);
        $this->assertSame(3, $leaveRequest->jumlah_hari_kerja);
        $this->assertSame(3, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->sum('amount'));
        $this->assertDatabaseHas('leave_balance_reservation_events', [
            'leave_request_id' => $leaveRequest->id,
            'event_type' => LeaveBalanceReservationEvent::EVENT_ADJUSTED,
            'amount' => -2,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'LEAVE_BALANCE_RESERVATION_ADJUSTED',
            'auditable_type' => 'LeaveBalanceReservationEvent',
        ]);

        $balance = LeaveBalance::query()->where('employee_id', $aktor['employee']->id)->firstOrFail();
        $this->assertSame(12, $balance->sisa);
        $this->assertSame(0, $balance->terpakai);
    }

    public function test_resubmit_to_a_different_calendar_year_moves_reservation_atomically(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->annualLeaveType();

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($jenis))
            ->assertRedirect(route('cuti'));

        $leaveRequest = LeaveRequest::query()->firstOrFail();
        LeaveBalance::create([
            'employee_id' => $aktor['employee']->id,
            'tahun' => 2027,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);

        $this->actingAs($aktor['user'])
            ->patch(route('cuti.resubmit', $leaveRequest), [
                'revision_version' => $leaveRequest->fresh()->revision_version,
                'tanggal_mulai' => '2027-02-01',
                'tanggal_selesai' => '2027-02-03',
                'alasan' => 'Jadwal telah dipindahkan ke tahun berikutnya.',
                'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 3, Manado',
                'nomor_telepon' => '+62 (431) 123-458',
            ])
            ->assertRedirect(route('cuti.show', $leaveRequest));

        $leaveRequest->refresh();
        $this->assertSame('2027-02-01', $leaveRequest->tanggal_mulai->toDateString());
        $this->assertSame(3, $leaveRequest->jumlah_hari_kerja);
        $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('tahun', 2026)
            ->sum('amount'));
        $this->assertSame(3, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('tahun', 2027)
            ->sum('amount'));
        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $aktor['employee']->id,
            'tahun' => 2027,
            'sisa' => 12,
        ]);
        $this->assertSame(12, LeaveBalance::query()
            ->where('employee_id', $aktor['employee']->id)
            ->where('tahun', 2026)
            ->value('sisa'));
    }

    public function test_final_approval_converts_reservation_then_records_fact_and_replays_balance_once(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->annualLeaveType();

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($jenis))
            ->assertRedirect(route('cuti'));

        $leaveRequest = LeaveRequest::query()->firstOrFail();
        $approval = app(LeaveApprovalService::class);
        $approval->approve($leaveRequest, $aktor['supervisor'], $leaveRequest->steps()->where('status', 'active')->valueOrFail('id'), (int) $leaveRequest->fresh()->revision_version, null, $aktor['supervisor_user']);
        $approval->approve($leaveRequest->fresh(), $aktor['pybmc'], $leaveRequest->steps()->where('status', 'active')->valueOrFail('id'), (int) $leaveRequest->fresh()->revision_version, null, $aktor['pybmc_user']);

        $this->assertSame('disetujui', $leaveRequest->fresh()->status);
        $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->sum('amount'));
        $this->assertDatabaseHas('leave_balance_reservation_events', [
            'leave_request_id' => $leaveRequest->id,
            'event_type' => LeaveBalanceReservationEvent::EVENT_CONVERTED,
            'amount' => -5,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'LEAVE_BALANCE_RESERVATION_CONVERTED',
            'auditable_type' => 'LeaveBalanceReservationEvent',
        ]);

        $balance = LeaveBalance::query()->where('employee_id', $aktor['employee']->id)->firstOrFail();
        $this->assertSame(13, $balance->sisa);
        $this->assertSame(5, $balance->terpakai);
        $this->assertSame(1, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $fact = LeaveUsageRecord::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->sole();
        $this->assertSame(LeaveUsageRecord::SOURCE_APPROVED_REQUEST, $fact->source_type);
        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $fact->record_status);
        $this->assertSame(5, $fact->workdays);
        $this->assertSame(1, LeaveBalanceLedger::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED)
            ->count());
        $this->assertSame(1, LeaveBalanceLedger::query()
            ->where('employee_id', $aktor['employee']->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->count());
        $this->assertSame(0, LeaveBalanceLedger::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_LEAVE_DEDUCTED)
            ->count());
    }

    public function test_final_approval_tahunan_ditolak_setelah_cuti_besar_final_tanpa_mutasi(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->annualLeaveType();
        $this->recordApprovedLargeUsage($aktor['employee'], $aktor['pybmc_user'], 'Cuti Besar Rule 5');
        $annual = $this->makeReservedRequest(jenis: $jenis, actors: $aktor)['request'];
        $annual->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $aktor['supervisor']->id,
                'status' => 'active',
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $aktor['pybmc']->id,
                'status' => 'pending',
                'is_final' => true,
            ],
        ]);

        $approval = app(LeaveApprovalService::class);
        $approval->approve($annual, $aktor['supervisor'], $annual->steps()->where('status', 'active')->valueOrFail('id'), (int) $annual->fresh()->revision_version, null, $aktor['supervisor_user']);

        try {
            $approval->approve($annual->fresh(), $aktor['pybmc'], $annual->steps()->where('status', 'active')->valueOrFail('id'), (int) $annual->fresh()->revision_version, null, $aktor['pybmc_user']);
            $this->fail('Persetujuan final tahunan harus ditolak setelah Cuti Besar final.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Cuti Tahunan tidak dapat digunakan pada tahun yang sama dengan Cuti Besar yang telah disetujui.',
                $exception->errors()['tanggal_mulai'][0] ?? null,
            );
        }

        $this->assertSame('menunggu_approval', $annual->fresh()->status);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $annual->id,
            'step_order' => 2,
            'status' => 'active',
        ]);
        $this->assertSame(5, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $annual->id)
            ->sum('amount'));
        $balance = LeaveBalance::query()->where('employee_id', $aktor['employee']->id)->firstOrFail();
        $this->assertSame(18, $balance->sisa);
        $this->assertSame(0, $balance->terpakai);
        $this->assertDatabaseMissing('leave_usage_records', [
            'leave_request_id' => $annual->id,
        ]);
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'leave_request_id' => $annual->id,
            'event_type' => LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED,
        ]);
    }

    public function test_submit_tahunan_ditolak_setelah_cuti_besar_final_walau_saldo_persisted_tersedia(): void
    {
        $aktor = $this->makePemohon();
        $annual = $this->annualLeaveType();
        $this->recordApprovedLargeUsage(
            $aktor['employee'],
            $aktor['pybmc_user'],
            'Cuti Besar Rule 5 Submit',
        );

        $this->actingAs($aktor['user'])
            ->postJson(route('cuti.store'), $this->payload($annual))
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.tanggal_mulai.0',
                'Cuti Tahunan tidak dapat digunakan pada tahun yang sama dengan Cuti Besar yang telah disetujui.',
            );

        $this->assertDatabaseCount('leave_requests', 1);
        $this->assertDatabaseCount('leave_balance_reservation_events', 0);
        $this->assertSame(18, LeaveBalance::query()
            ->where('employee_id', $aktor['employee']->id)
            ->where('tahun', 2026)
            ->value('sisa'));
    }

    public function test_submit_tahunan_ditolak_saat_cuti_besar_masih_memiliki_workflow_aktif(): void
    {
        $aktor = $this->makePemohon();
        $annual = $this->annualLeaveType();
        $large = RefJenisCuti::create([
            'nama' => 'Cuti Besar Rule 5 Belum Final',
            'code' => 'besar',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => true,
        ]);
        LeaveRequest::create([
            'employee_id' => $aktor['employee']->id,
            'jenis_cuti_id' => $large->id,
            'tanggal_mulai' => '2026-03-02',
            'tanggal_selesai' => '2026-03-31',
            'jumlah_hari_kerja' => 20,
            'alasan' => 'Cuti Besar belum final.',
            'status' => 'menunggu_approval',
        ]);

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($annual, '2026-09-01', '2026-09-03'))
            ->assertSessionHasErrors('tanggal_mulai');

        $this->assertDatabaseMissing('leave_requests', [
            'employee_id' => $aktor['employee']->id,
            'jenis_cuti_id' => $annual->id,
        ]);
    }

    public function test_resubmit_tahunan_ditolak_setelah_cuti_besar_final_tanpa_menggeser_reservasi(): void
    {
        $aktor = $this->makePemohon();
        $annual = $this->annualLeaveType();
        $this->recordApprovedLargeUsage(
            $aktor['employee'],
            $aktor['pybmc_user'],
            'Cuti Besar Rule 5 Resubmit',
        );
        $request = $this->makeReservedRequest(jenis: $annual, actors: $aktor)['request'];
        $beforeReservation = (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $request->id)
            ->sum('amount');

        $this->actingAs($aktor['user'])
            ->patchJson(route('cuti.resubmit', $request), [
                'revision_version' => $request->fresh()->revision_version,
                'tanggal_mulai' => '2026-09-01',
                'tanggal_selesai' => '2026-09-03',
                'alasan' => 'Revisi tetap pada tahun Rule 5.',
                'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 1',
                'nomor_telepon' => '+62 431 123456',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.tanggal_mulai.0',
                'Cuti Tahunan tidak dapat digunakan pada tahun yang sama dengan Cuti Besar yang telah disetujui.',
            );

        $request->refresh();
        $this->assertSame('menunggu_approval', $request->status);
        $this->assertSame('2026-07-06', $request->tanggal_mulai->toDateString());
        $this->assertSame($beforeReservation, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $request->id)
            ->sum('amount'));
    }

    public function test_reservasi_submit_menolak_tahunan_dengan_pesan_rule_5_setelah_cuti_besar_final(): void
    {
        $aktor = $this->makePemohon();
        $annual = $this->annualLeaveType();
        $this->recordApprovedLargeUsage(
            $aktor['employee'],
            $aktor['pybmc_user'],
            'Cuti Besar Rule 5 Guard Submit',
        );
        $request = LeaveRequest::create([
            'employee_id' => $aktor['employee']->id,
            'jenis_cuti_id' => $annual->id,
            'tanggal_mulai' => '2026-09-01',
            'tanggal_selesai' => '2026-09-03',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Uji guard reservasi submit.',
            'status' => 'menunggu_approval',
        ])->load('jenisCuti');

        try {
            app(LeaveBalanceReservationService::class)->reserveForNewRequest($request, $aktor['user']);
            $this->fail('Reservasi Tahunan harus ditolak oleh guard Rule 5.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Cuti Tahunan tidak dapat digunakan pada tahun yang sama dengan Cuti Besar yang telah disetujui.'],
                $exception->errors()['tanggal_mulai'] ?? [],
            );
        }

        $this->assertDatabaseCount('leave_balance_reservation_events', 0);
    }

    public function test_reservasi_resubmit_menolak_tahunan_dengan_pesan_rule_5_tanpa_mutasi(): void
    {
        $actors = $this->makePemohon();
        $annual = $this->annualLeaveType();
        $this->recordApprovedLargeUsage(
            $actors['employee'],
            $actors['pybmc_user'],
            'Cuti Besar Rule 5 Guard Resubmit',
        );
        $fixture = $this->makeReservedRequest(jenis: $annual, actors: $actors);

        try {
            app(LeaveBalanceReservationService::class)->adjustForResubmission(
                $fixture['request'],
                Carbon::parse('2026-09-01'),
                3,
                $fixture['actor'],
            );
            $this->fail('Penyesuaian reservasi Tahunan harus ditolak oleh guard Rule 5.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Cuti Tahunan tidak dapat digunakan pada tahun yang sama dengan Cuti Besar yang telah disetujui.'],
                $exception->errors()['tanggal_mulai'] ?? [],
            );
        }

        $this->assertSame(5, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->sum('amount'));
    }

    public function test_not_approved_releases_reservation_without_deducting_final_balance(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->annualLeaveType();

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($jenis))
            ->assertRedirect(route('cuti'));

        $leaveRequest = LeaveRequest::query()->firstOrFail();
        app(LeaveApprovalService::class)->decline($leaveRequest, $aktor['supervisor'], $leaveRequest->steps()->where('status', 'active')->valueOrFail('id'), (int) $leaveRequest->fresh()->revision_version, 'Belum dapat disetujui.');

        $this->assertSame('tidak_disetujui', $leaveRequest->fresh()->status);
        $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->sum('amount'));
        $this->assertDatabaseHas('leave_balance_reservation_events', [
            'leave_request_id' => $leaveRequest->id,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RELEASED,
            'amount' => -5,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'LEAVE_BALANCE_RESERVATION_RELEASED',
            'auditable_type' => 'LeaveBalanceReservationEvent',
        ]);

        $balance = LeaveBalance::query()->where('employee_id', $aktor['employee']->id)->firstOrFail();
        $this->assertSame(12, $balance->sisa);
        $this->assertSame(0, $balance->terpakai);
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'leave_request_id' => $leaveRequest->id,
            'event_type' => LeaveBalanceLedger::EVENT_LEAVE_DEDUCTED,
        ]);
    }

    public function test_postpone_keeps_reservation_active(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->annualLeaveType();

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($jenis))
            ->assertRedirect(route('cuti'));

        $leaveRequest = LeaveRequest::query()->firstOrFail();
        $approval = app(LeaveApprovalService::class);
        $approval->postpone($leaveRequest, $aktor['supervisor'], $leaveRequest->steps()->where('status', 'active')->valueOrFail('id'), (int) $leaveRequest->fresh()->revision_version, 'Menunggu penyesuaian tugas.');

        $this->assertSame('ditangguhkan', $leaveRequest->fresh()->status);
        $this->assertSame(5, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->sum('amount'));
        $this->assertSame(1, LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('event_type', LeaveBalanceReservationEvent::EVENT_RESERVED)
            ->count());
        $this->assertDatabaseMissing('leave_balance_reservation_events', [
            'leave_request_id' => $leaveRequest->id,
            'dedup_key' => "leave_reservation:{$leaveRequest->id}:released:duty_postponement:2026",
        ]);
    }

    public function test_non_annual_leave_never_creates_balance_reservation(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->sickLeaveType();

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($jenis))
            ->assertRedirect(route('cuti'));

        $this->assertDatabaseCount('leave_balance_reservation_events', 0);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'LEAVE_BALANCE_RESERVED']);
    }
}
