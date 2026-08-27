<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageReconciliationMembership;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceRecalculationService;
use App\Services\Cuti\LeaveBalanceService;
use App\Services\Cuti\LeaveUsageReconciliationService;
use App\Services\Cuti\LeaveUsageRecordService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveBalanceRecalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_tanpa_tmt_tidak_membentuk_hak_tahunan(): void
    {
        $this->annualType();
        $pns = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);
        $actor = User::factory()->adminKepegawaian()->create();

        $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 0]);

        $balances = LeaveBalance::query()->whereBelongsTo($employee)->orderBy('tahun')->get();
        $this->assertSame([0, 0, 0], $balances->pluck('jatah_awal')->all());
        $this->assertSame([0, 0, 0], $balances->pluck('sisa')->all());
    }

    public function test_replay_menolak_pemakaian_tanpa_hak_tahunan(): void
    {
        $this->annualType();
        $pns = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);
        $actor = User::factory()->adminKepegawaian()->create();

        try {
            $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 1]);
            $this->fail('Pemakaian tahunan tanpa hak yang eligible harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('usage.2026', $exception->errors());
        }
    }

    public function test_replay_belum_memberi_hak_sebelum_anniversary_satu_tahun(): void
    {
        $originalNow = Carbon::getTestNow();

        try {
            Carbon::setTestNow('2026-08-23 09:00:00');
            [$employee, $actor] = $this->employeeWithAppointment('2025-08-24');

            $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 0]);

            $balance = LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2026)->sole();
            $this->assertSame(0, $balance->jatah_awal);
            $this->assertSame(0, $balance->sisa);
        } finally {
            Carbon::setTestNow($originalNow);
        }
    }

    public function test_replay_memberi_hak_tepat_anniversary_tanpa_carry_fiktif(): void
    {
        $originalNow = Carbon::getTestNow();

        try {
            Carbon::setTestNow('2026-08-23 09:00:00');
            [$employee, $actor] = $this->employeeWithAppointment('2025-08-23');

            $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 0]);

            $balances = LeaveBalance::query()->whereBelongsTo($employee)->orderBy('tahun')->get()->keyBy('tahun');
            $this->assertSame(0, $balances->get(2024)?->sisa);
            $this->assertSame(0, $balances->get(2025)?->sisa);
            $this->assertSame(12, $balances->get(2026)?->jatah_awal);
            $this->assertSame(0, $balances->get(2026)?->carry_over);
            $this->assertSame(12, $balances->get(2026)?->sisa);
        } finally {
            Carbon::setTestNow($originalNow);
        }
    }

    public function test_saldo_legacy_belum_eligible_tidak_dapat_diajukan(): void
    {
        [$employee] = $this->employeeWithAppointment('2025-08-24');
        LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'sisa_tahun_berjalan' => 12,
            'sisa' => 12,
        ]);

        $this->assertSame(
            0,
            app(LeaveBalanceService::class)->availableFor($employee, 2026, Carbon::parse('2026-08-23')),
        );
    }

    public function test_preview_saldo_legacy_belum_eligible_fail_closed(): void
    {
        [$employee] = $this->employeeWithAppointment('2025-08-24');
        LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'sisa_tahun_berjalan' => 12,
            'sisa' => 12,
        ]);

        $preview = app(LeaveBalanceService::class)->previewFor($employee, Carbon::parse('2026-08-23'));

        $this->assertFalse($preview['eligible']);
        $this->assertSame(0, $preview['jatah_dasar']);
        $this->assertSame(0, $preview['saldo_aktual']);
        $this->assertSame(0, $preview['saldo_dapat_diajukan']);
        $this->assertSame(['n2' => 0, 'n1' => 0, 'current' => 0], $preview['bucket']);
    }

    public function test_create_rekonsiliasi_service_menolak_tahun_saldo_masa_depan_sebelum_mutasi(): void
    {
        $originalNow = Carbon::getTestNow();

        try {
            Carbon::setTestNow(Carbon::parse('2026-12-31 16:30:00', 'UTC'));
            [$employee, $actor] = $this->employeeAndActor();
            $before = $this->domainSnapshot($employee);
            $exception = null;

            try {
                app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
                    $employee,
                    2028,
                    [2026 => 0, 2027 => 0, 2028 => 0],
                    Carbon::parse('2028-01-01 09:00:00', 'Asia/Makassar'),
                    'Rekonsiliasi masa depan tidak sah.',
                    $actor,
                );
            } catch (ValidationException $validationException) {
                $exception = $validationException;
            }

            $this->assertSame($before, $this->domainSnapshot($employee), 'Create future-year tidak boleh menulis set, fact, ledger, audit, atau projection.');
            $this->assertInstanceOf(ValidationException::class, $exception);
            $this->assertArrayHasKey('balance_year', $exception->errors());
        } finally {
            Carbon::setTestNow($originalNow);
        }
    }

    public function test_replace_rekonsiliasi_service_menolak_tahun_saldo_masa_depan_dan_mempertahankan_set(): void
    {
        $originalNow = Carbon::getTestNow();

        try {
            Carbon::setTestNow(Carbon::parse('2028-01-01 09:00:00', 'Asia/Makassar'));
            [$employee, $actor] = $this->employeeAndActor();
            $current = $this->createSetFor(
                $employee,
                $actor,
                2028,
                [2026 => 0, 2027 => 0, 2028 => 0],
                '2028-01-01',
            );

            Carbon::setTestNow(Carbon::parse('2026-12-31 16:30:00', 'UTC'));
            $before = $this->domainSnapshot($employee);
            $exception = null;

            try {
                app(LeaveUsageReconciliationService::class)->replaceAnnualReconciliationSet(
                    $current,
                    [2026 => 1, 2027 => 2, 2028 => 3],
                    Carbon::parse('2028-02-01 09:00:00', 'Asia/Makassar'),
                    'Koreksi future-year tidak sah.',
                    'Tolak koreksi di luar horizon WITA.',
                    $actor,
                );
            } catch (ValidationException $validationException) {
                $exception = $validationException;
            }

            $this->assertSame($before, $this->domainSnapshot($employee), 'Replace future-year wajib mempertahankan set, fact, ledger, audit, dan projection existing.');
            $this->assertSame(LeaveUsageReconciliationSet::STATUS_ACTIVE, $current->fresh()->status);
            $this->assertInstanceOf(ValidationException::class, $exception);
            $this->assertArrayHasKey('balance_year', $exception->errors());
        } finally {
            Carbon::setTestNow($originalNow);
        }
    }

    public function test_rekonsiliasi_service_menerima_tanggal_masa_depan_dalam_tahun_wita_berjalan(): void
    {
        $originalNow = Carbon::getTestNow();

        try {
            Carbon::setTestNow(Carbon::parse('2026-12-31 16:30:00', 'UTC'));
            $this->assertSame(2026, Carbon::now('UTC')->year);
            $this->assertSame(2027, Carbon::now('Asia/Makassar')->year);
            [$employee, $actor] = $this->employeeAndActor();

            $set = $this->createSetFor(
                $employee,
                $actor,
                2027,
                [2025 => 0, 2026 => 0, 2027 => 0],
                '2027-12-31',
            );

            $this->assertSame(2027, $set->balance_year);
            $this->assertSame('2027-12-31', $set->reconciled_at->toDateString());
            $this->assertSame(LeaveUsageReconciliationSet::STATUS_ACTIVE, $set->status);
        } finally {
            Carbon::setTestNow($originalNow);
        }
    }

    public function test_snapshot_membekukan_fact_itemized_dan_tidak_menghitung_ganda(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $fact = $this->manualFact($employee, $actor, 2024, '2024-06-03', 2);

        $set = $this->createSet($employee, $actor, [2024 => 2, 2025 => 0, 2026 => 0]);

        $membership = LeaveUsageReconciliationMembership::query()->firstOrFail();
        $this->assertSame($set->id, $membership->reconciliation_set_id);
        $this->assertSame($fact->id, $membership->itemized_usage_record_id);
        $this->assertSame(2, $membership->included_workdays);

        $balance = LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2024)->firstOrFail();
        $this->assertSame(10, $balance->sisa);
        $this->assertSame(2, $balance->terpakai);
    }

    public function test_fact_backdated_setelah_snapshot_dihitung_penuh_dan_replay_idempoten(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 5]);
        $before = LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2026)->firstOrFail();
        $this->assertSame(19, $before->sisa);

        $fact = $this->manualFact($employee, $actor, 2026, '2026-01-05', 2);
        $service = app(LeaveBalanceRecalculationService::class);
        $service->recalculate($employee, 2026, $actor, 'Tambah cuti backdated.');

        $after = LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2026)->firstOrFail();
        $this->assertSame(17, $after->sisa);
        $this->assertSame(7, $after->terpakai);
        $this->assertDatabaseMissing('leave_usage_reconciliation_memberships', [
            'itemized_usage_record_id' => $fact->id,
        ]);

        $ledgerCount = LeaveBalanceLedger::query()
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->count();
        $auditCount = AuditLog::query()
            ->where('auditable_type', 'LeaveBalance')
            ->where('event', 'UPDATE')
            ->count();

        $service->recalculate($employee, 2026, $actor, 'Retry identik.');

        $this->assertSame($ledgerCount, LeaveBalanceLedger::query()
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->count());
        $this->assertSame($auditCount, AuditLog::query()
            ->where('auditable_type', 'LeaveBalance')
            ->where('event', 'UPDATE')
            ->count());
    }

    public function test_oracle_replay_fifo_menghasilkan_tujuh_belas_lalu_lima_belas(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 2]);
        $initial = LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2026)->firstOrFail();
        $this->assertSame([4, 6, 12], [
            $initial->sisa_n2,
            $initial->sisa_n1,
            $initial->sisa_tahun_berjalan,
        ]);

        $this->manualFact($employee, $actor, 2026, '2026-06-08', 5);
        $service = app(LeaveBalanceRecalculationService::class);
        $service->recalculate($employee, 2026, $actor, 'Catat pemakaian lima hari.');
        $afterFive = LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2026)->firstOrFail();
        $this->assertSame([0, 5, 12], [
            $afterFive->sisa_n2,
            $afterFive->sisa_n1,
            $afterFive->sisa_tahun_berjalan,
        ]);
        $this->assertSame(17, $afterFive->sisa);

        $this->manualFact($employee, $actor, 2026, '2026-05-04', 2);
        $service->recalculate($employee, 2026, $actor, 'Tambahkan dua hari backdated.');
        $afterBackdated = LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2026)->firstOrFail();
        $this->assertSame([0, 3, 12], [
            $afterBackdated->sisa_n2,
            $afterBackdated->sisa_n1,
            $afterBackdated->sisa_tahun_berjalan,
        ]);
        $this->assertSame(15, $afterBackdated->sisa);

        $ledger = LeaveBalanceLedger::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2026)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->get()
            ->first(fn (LeaveBalanceLedger $entry): bool => ($entry->metadata['before']['sisa'] ?? null) === 17
                && ($entry->metadata['after']['sisa'] ?? null) === 15);
        $this->assertNotNull($ledger);
        $this->assertSame(-2, $ledger->amount);
        $this->assertSame(17, $ledger->metadata['before']['sisa']);
        $this->assertSame(15, $ledger->metadata['after']['sisa']);
    }

    public function test_koreksi_dan_pembatalan_fact_member_memakai_delta_terhadap_snapshot(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $member = $this->manualFact($employee, $actor, 2026, '2026-03-02', 2);
        $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 2]);

        $replacement = app(LeaveUsageRecordService::class)->replace(
            $member,
            ['workdays' => 3],
            'Koreksi jumlah hari.',
            $actor,
            approvalSteps: $this->validManualApprovalStepData(),
        );

        $supersededLedger = LeaveBalanceLedger::query()
            ->where('event_type', LeaveBalanceLedger::EVENT_USAGE_FACT_SUPERSEDED)
            ->where('metadata->usage_record_id', $member->id)
            ->sole();
        $recordedLedger = LeaveBalanceLedger::query()
            ->where('event_type', LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED)
            ->where('metadata->usage_record_id', $replacement->id)
            ->sole();
        $this->assertSame($replacement->id, $supersededLedger->metadata['replacement_id']);
        $this->assertSame($member->id, $recordedLedger->metadata['replaces_id']);

        $this->assertSame('superseded', $member->fresh()->record_status);
        $this->assertSame(3, LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2026)->value('terpakai'));

        $replacementKedua = app(LeaveUsageRecordService::class)->replace(
            $replacement,
            ['workdays' => 4],
            'Koreksi lanjutan jumlah hari.',
            $actor,
            approvalSteps: $this->validManualApprovalStepData(),
        );
        $this->assertSame(4, LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2026)->value('terpakai'));

        app(LeaveUsageRecordService::class)->cancel(
            $replacementKedua,
            'Cuti eksternal dibatalkan.',
            $actor,
        );

        $this->assertSame('cancelled', $replacementKedua->fresh()->record_status);
        $this->assertSame(0, LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2026)->value('terpakai'));
    }

    public function test_replacement_set_menyegarkan_membership_ke_versi_itemized_aktif(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $member = $this->manualFact($employee, $actor, 2026, '2026-03-02', 2);
        $current = $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 2]);
        $replacementFact = app(LeaveUsageRecordService::class)->replace(
            $member,
            ['workdays' => 3],
            'Koreksi itemized.',
            $actor,
            approvalSteps: $this->validManualApprovalStepData(),
        );

        $replacementSet = app(LeaveUsageReconciliationService::class)->replaceAnnualReconciliationSet(
            $current,
            [2024 => 0, 2025 => 0, 2026 => 3],
            Carbon::parse('2026-08-18'),
            'Snapshot pengganti.',
            'Selaraskan dokumen rekonsiliasi.',
            $actor,
        );

        $membership = $replacementSet->memberships()->sole();
        $this->assertSame($replacementFact->id, $membership->itemized_usage_record_id);
        $this->assertSame(3, $membership->included_workdays);
    }

    public function test_replacement_rekonsiliasi_mengganti_seluruh_set_secara_atomik(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $current = $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 1]);

        $replacement = app(LeaveUsageReconciliationService::class)->replaceAnnualReconciliationSet(
            $current,
            [2024 => 0, 2025 => 2, 2026 => 3],
            Carbon::parse('2026-08-18'),
            'Rekonsiliasi pengganti.',
            'Dokumen sumber diperbaiki.',
            $actor,
        );

        $this->assertSame('superseded', $current->fresh()->status);
        $this->assertSame('active', $replacement->status);
        $this->assertSame($current->id, $replacement->replaces_id);
        $this->assertSame(3, $current->records()->where('record_status', 'superseded')->count());
        $this->assertSame([0, 2, 3], $replacement->records()->orderBy('usage_year')->pluck('workdays')->all());
        $this->assertSame(1, LeaveUsageReconciliationSet::query()
            ->whereBelongsTo($employee)->where('status', 'active')->count());

        $audit = AuditLog::query()
            ->where('auditable_type', 'LeaveUsageReconciliationSet')
            ->where('auditable_id', $replacement->id)
            ->where('event', 'UPDATE')
            ->sole();
        $this->assertSame([2024 => 0, 2025 => 0, 2026 => 1], $audit->old_values['usage_by_year']);
        $this->assertSame([2024 => 0, 2025 => 2, 2026 => 3], $audit->new_values['snapshot']['usage_by_year']);
        $this->assertSame('Dokumen sumber diperbaiki.', $audit->new_values['reason']);
    }

    public function test_set_tahun_saldo_berikutnya_menggantikan_satu_set_aktif_pegawai(): void
    {
        Carbon::setTestNow('2027-01-10 09:00:00');

        try {
            [$employee, $actor] = $this->employeeAndActor();
            $this->manualFact($employee, $actor, 2024, '2024-03-04', 2);
            $old = $this->createSet($employee, $actor, [2024 => 2, 2025 => 0, 2026 => 0]);
            $new = $this->createSetFor(
                $employee,
                $actor,
                2027,
                [2025 => 0, 2026 => 1, 2027 => 0],
                '2027-01-10',
            );

            $this->assertSame('superseded', $old->fresh()->status);
            $this->assertSame($old->id, $new->replaces_id);
            $this->assertSame(1, LeaveUsageReconciliationSet::query()
                ->whereBelongsTo($employee)->where('status', 'active')->count());
            $expectedBuckets = [2025 => [0, 0, 12], 2026 => [0, 5, 12], 2027 => [0, 6, 12]];
            $actualBuckets = fn (): array => LeaveBalance::query()
                ->whereBelongsTo($employee)
                ->whereBetween('tahun', [2025, 2027])
                ->orderBy('tahun')
                ->get()
                ->mapWithKeys(fn (LeaveBalance $balance): array => [
                    $balance->tahun => [
                        $balance->sisa_n2,
                        $balance->sisa_n1,
                        $balance->sisa_tahun_berjalan,
                    ],
                ])
                ->all();
            $this->assertSame(
                $expectedBuckets,
                $actualBuckets(),
                'Snapshot 2027 harus memulai replay dari N-2 (2025) tanpa mengasumsikan carry 2024.',
            );
            app(LeaveBalanceRecalculationService::class)->recalculate(
                $employee,
                2024,
                $actor,
                'Retry dengan tahun terdampak di luar anchor aktif.',
            );
            $this->assertSame($expectedBuckets, $actualBuckets());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_koreksi_fact_member_yang_berpindah_tahun_memindahkan_pemakaian_secara_utuh(): void
    {
        Carbon::setTestNow('2027-01-10 09:00:00');

        try {
            [$employee, $actor] = $this->employeeAndActor();
            $member = $this->manualFact($employee, $actor, 2025, '2025-03-03', 2);
            $this->createSetFor(
                $employee,
                $actor,
                2027,
                [2025 => 2, 2026 => 0, 2027 => 0],
                '2027-01-10',
            );

            app(LeaveUsageRecordService::class)->replace(
                $member,
                [
                    'usage_year' => 2026,
                    'effective_date' => '2026-04-06',
                    'start_date' => '2026-04-06',
                    'end_date' => '2026-04-08',
                    'workdays' => 3,
                ],
                'Periode final yang benar berada pada tahun berikutnya.',
                $actor,
                approvalSteps: $this->validManualApprovalStepData(),
            );

            $usageByYear = LeaveBalance::query()
                ->whereBelongsTo($employee)
                ->whereIn('tahun', [2025, 2026])
                ->orderBy('tahun')
                ->pluck('terpakai', 'tahun')
                ->all();

            $this->assertSame(
                [2025 => 0, 2026 => 3],
                $usageByYear,
                'Versi lama harus dikeluarkan dari tahun snapshot dan versi aktif dihitung penuh pada tahun barunya.',
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_total_rekonsiliasi_di_bawah_membership_ditolak_tanpa_partial_write(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $this->manualFact($employee, $actor, 2026, '2026-02-02', 3);

        try {
            $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 2]);
            $this->fail('Total di bawah fact itemized seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('usage.2026', $exception->errors());
        }

        $this->assertDatabaseCount('leave_usage_reconciliation_sets', 0);
        $this->assertDatabaseCount('leave_usage_reconciliation_memberships', 0);
    }

    public function test_record_approved_request_idempoten_dan_urut_replay_stabil(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 0]);
        $request = $this->approvedRequest($employee, '2026-04-06', 2);

        $service = app(LeaveUsageRecordService::class);
        $first = $service->recordApprovedRequest($request, $actor);
        $second = $service->recordApprovedRequest($request, $actor);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, LeaveUsageRecord::query()->where('leave_request_id', $request->id)->count());

        $ledger = LeaveBalanceLedger::query()
            ->where('tahun', 2026)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->get()
            ->first(fn (LeaveBalanceLedger $entry): bool => in_array(
                $first->id,
                $entry->metadata['ordered_fact_ids'] ?? [],
                true,
            ));
        $this->assertNotNull($ledger);
    }

    public function test_approved_request_masa_depan_tetap_mengonsumsi_saldo_setelah_final(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 0]);
        $request = $this->approvedRequest($employee, '2026-12-01', 2);

        app(LeaveUsageRecordService::class)->recordApprovedRequest($request, $actor);

        $balance = LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2026)->firstOrFail();
        $this->assertSame(2, $balance->terpakai);
        $this->assertSame(22, $balance->sisa);
    }

    public function test_approved_request_tidak_dapat_dikoreksi_atau_dibatalkan_lewat_service_manual(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 0]);
        $fact = app(LeaveUsageRecordService::class)->recordApprovedRequest(
            $this->approvedRequest($employee, '2026-07-01', 2),
            $actor,
        );
        $before = $this->domainSnapshot($employee);
        $service = app(LeaveUsageRecordService::class);

        foreach (['replace', 'cancel'] as $operation) {
            try {
                if ($operation === 'replace') {
                    $service->replace(
                        $fact,
                        ['workdays' => 3],
                        'Koreksi tidak diizinkan.',
                        $actor,
                        [],
                    );
                } else {
                    $service->cancel($fact, 'Pembatalan tidak diizinkan.', $actor);
                }

                $this->fail($operation.' approved request seharusnya ditolak.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('usage_record', $exception->errors());
            }

            $this->assertSame($before, $this->domainSnapshot($employee));
        }
    }

    public function test_pembatalan_fact_yang_memulihkan_projection_lama_tetap_menulis_event_kompensasi_baru(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 0]);
        $fact = $this->manualFact($employee, $actor, 2026, '2026-07-01', 2);
        $recalculation = app(LeaveBalanceRecalculationService::class);
        $recalculation->recalculate($employee, 2026, $actor, 'Catat fakta baru.');
        $beforeCancellation = LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2026)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->count();

        app(LeaveUsageRecordService::class)->cancel($fact, 'Batalkan fakta baru.', $actor);

        $afterCancellation = LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2026)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->get();
        $restoredEvents = $afterCancellation->filter(
            fn (LeaveBalanceLedger $ledger): bool => ($ledger->metadata['after']['sisa'] ?? null) === 24,
        );
        $this->assertCount($beforeCancellation + 1, $afterCancellation);
        $this->assertCount(2, $restoredEvents);
        $this->assertCount(2, $restoredEvents->pluck('metadata.source_hash')->unique());
        $this->assertSame(24, LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2026)->value('sisa'));

        $recalculation->recalculate($employee, 2026, $actor, 'Retry state pembatalan identik.');
        $this->assertSame($afterCancellation->count(), LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2026)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->count());
    }

    public function test_urutan_tie_replay_tepat_effective_date_created_at_lalu_id(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 0]);
        $createdAt = Carbon::parse('2026-08-18 10:00:00');
        $ids = [
            '00000000-0000-4000-8000-000000000001',
            '00000000-0000-4000-8000-000000000002',
        ];

        foreach (array_reverse($ids) as $index => $id) {
            DB::table('leave_usage_records')->insert([
                'id' => $id,
                'employee_id' => $employee->id,
                'leave_type_id' => $this->annualType()->id,
                'source_type' => 'manual_external',
                'usage_year' => 2026,
                'effective_date' => '2026-06-01',
                'start_date' => '2026-06-01',
                'end_date' => $index === 0 ? '2026-06-02' : '2026-06-01',
                'workdays' => 1,
                'administrative_note' => "Tie ordering {$id}.",
                'record_status' => 'active',
                'recorded_by' => $actor->id,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $this->attachValidManualApprovalSnapshot(
                LeaveUsageRecord::query()->findOrFail($id),
            );
        }

        app(LeaveBalanceRecalculationService::class)->recalculate(
            $employee,
            2026,
            $actor,
            'Buktikan urutan tie.',
        );

        $ledger = LeaveBalanceLedger::query()
            ->where('tahun', 2026)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->get()
            ->first(fn (LeaveBalanceLedger $entry): bool => count(array_intersect(
                $entry->metadata['ordered_fact_ids'] ?? [],
                $ids,
            )) === 2);
        $this->assertNotNull($ledger);
        $this->assertSame($ids, array_values(array_intersect($ledger->metadata['ordered_fact_ids'], $ids)));
    }

    public function test_rule_3_ditolak_bila_replay_mengambil_bucket_terlindungi_tanpa_clamp_atau_reassign(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 0]);
        $balance = LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2026)->firstOrFail();
        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED,
            'amount' => 0,
            'source_year' => 2026,
            'reason' => 'Hak terlindungi untuk pengujian.',
            'dedup_key' => "rule3-test:{$employee->id}",
            'metadata' => [
                'protected_allocations' => ['n2' => 0, 'n1' => 0, 'current' => 4],
                'protected_days' => 4,
            ],
            'created_by' => $actor->id,
            'occurred_at' => now(),
        ]);
        $this->manualFact($employee, $actor, 2026, '2026-08-01', 21);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('terlindungi');
        app(LeaveBalanceRecalculationService::class)->recalculate(
            $employee,
            2026,
            $actor,
            'Replay tidak boleh mengambil hak Rule 3.',
        );
    }

    public function test_rule_3_carry_statutory_hangus_setelah_satu_tahun_dan_tidak_menua_ke_n2(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'tahun' => 2024,
            'event_type' => LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED,
            'amount' => 0,
            'source_year' => 2024,
            'reason' => 'Hak statutory satu tahun untuk pengujian expiry.',
            'dedup_key' => "rule3-expiry-test:{$employee->id}",
            'metadata' => [
                'protected_allocations' => ['n2' => 0, 'n1' => 0, 'current' => 12],
                'protected_days' => 12,
                'expiry_policy' => 'valid_one_year_no_n2_aging',
            ],
            'created_by' => $actor->id,
            'occurred_at' => now(),
        ]);

        $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 0]);

        $balance2025 = LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2025)->firstOrFail();
        $balance2026 = LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2026)->firstOrFail();

        $this->assertSame([0, 12, 12], [
            $balance2025->sisa_n2,
            $balance2025->sisa_n1,
            $balance2025->sisa_tahun_berjalan,
        ]);
        $this->assertSame([0, 6, 12], [
            $balance2026->sisa_n2,
            $balance2026->sisa_n1,
            $balance2026->sisa_tahun_berjalan,
        ]);
        $this->assertSame(18, $balance2026->hangus);
        $expiry = LeaveBalanceLedger::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2026)
            ->where('event_type', LeaveBalanceLedger::EVENT_CARRY_OVER_EXPIRED)
            ->sole();
        $this->assertSame(2025, $expiry->source_year);
        $this->assertSame(18, $expiry->metadata['expired_days']);
        $expiryCount = LeaveBalanceLedger::query()
            ->whereBelongsTo($employee)
            ->where('event_type', LeaveBalanceLedger::EVENT_CARRY_OVER_EXPIRED)
            ->count();

        app(LeaveBalanceRecalculationService::class)->recalculate(
            $employee,
            2024,
            $actor,
            'Retry expiry identik.',
        );
        $this->assertSame($expiryCount, LeaveBalanceLedger::query()
            ->whereBelongsTo($employee)
            ->where('event_type', LeaveBalanceLedger::EVENT_CARRY_OVER_EXPIRED)
            ->count());
    }

    public function test_rule_5_tidak_mengubah_bucket_tahun_sumber_tetapi_mengecualikan_current_dari_carry(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $request = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $this->largeLeaveType()->id,
            'tanggal_mulai' => '2025-07-01',
            'tanggal_selesai' => '2025-07-20',
            'jumlah_hari_kerja' => 14,
            'alasan' => 'Cuti Besar final.',
            'status' => 'disetujui',
        ]);

        $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 0]);

        $source = LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2025)->firstOrFail();
        $target = LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2026)->firstOrFail();
        // Lifecycle request tanpa fakta final tidak boleh mengaktifkan Rule 5.
        $this->assertDatabaseMissing('leave_usage_records', ['leave_request_id' => $request->id]);
        $this->assertSame(['n2' => 6, 'n1' => 6, 'current' => 12], [
            'n2' => $target->sisa_n2,
            'n1' => $target->sisa_n1,
            'current' => $target->sisa_tahun_berjalan,
        ]);

        $fact = app(LeaveUsageRecordService::class)->recordApprovedRequest($request, $actor);
        $source->refresh();
        $target->refresh();

        $this->assertSame(LeaveUsageRecord::SOURCE_APPROVED_REQUEST, $fact->source_type);
        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $fact->record_status);
        $this->assertSame(['n2' => 0, 'n1' => 6, 'current' => 12], [
            'n2' => $source->sisa_n2,
            'n1' => $source->sisa_n1,
            'current' => $source->sisa_tahun_berjalan,
        ]);
        $this->assertSame(['n2' => 6, 'n1' => 0, 'current' => 12], [
            'n2' => $target->sisa_n2,
            'n1' => $target->sisa_n1,
            'current' => $target->sisa_tahun_berjalan,
        ]);
    }

    public function test_koreksi_ditolak_bila_projection_baru_tidak_mencukupi_reservasi_aktif(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $current = $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 0]);
        $request = $this->activeRequest($employee, '2026-09-01', 20);
        $balance = LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2026)->firstOrFail();

        LeaveBalanceReservationEvent::create([
            'employee_id' => $employee->id,
            'leave_request_id' => $request->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
            'amount' => 20,
            'dedup_key' => "test-reservation:{$request->id}",
            'reason' => 'Reservasi pengujian.',
            'created_by' => $actor->id,
            'occurred_at' => now(),
        ]);
        $before = $this->domainSnapshot($employee);

        try {
            app(LeaveUsageReconciliationService::class)->replaceAnnualReconciliationSet(
                $current,
                [2024 => 0, 2025 => 0, 2026 => 10],
                Carbon::parse('2026-08-18'),
                'Koreksi yang mengurangi saldo.',
                'Perbaikan dokumen.',
                $actor,
            );
            $this->fail('Koreksi yang mengambil hak terreservasi seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('saldo', $exception->errors());
        }

        $this->assertSame('active', $current->fresh()->status);
        $this->assertSame(1, LeaveUsageReconciliationSet::query()->where('status', 'active')->count());
        $this->assertSame(24, $balance->fresh()->sisa);
        $this->assertSame($before, $this->domainSnapshot($employee));
    }

    public function test_reservasi_tidak_boleh_memakai_hak_yang_dilindungi_rule_3(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $current = $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 0]);
        $balance = LeaveBalance::query()->whereBelongsTo($employee)->where('tahun', 2026)->firstOrFail();
        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED,
            'amount' => 0,
            'source_year' => 2026,
            'reason' => 'Empat hari dilindungi untuk pengujian reservasi.',
            'dedup_key' => "rule3-reservation-test:{$employee->id}",
            'metadata' => [
                'protected_allocations' => ['n2' => 0, 'n1' => 0, 'current' => 4],
                'protected_days' => 4,
                'expiry_policy' => 'valid_one_year_no_n2_aging',
            ],
            'created_by' => $actor->id,
            'occurred_at' => now(),
        ]);
        $request = $this->activeRequest($employee, '2026-09-01', 20);
        LeaveBalanceReservationEvent::create([
            'employee_id' => $employee->id,
            'leave_request_id' => $request->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
            'amount' => 20,
            'dedup_key' => "rule3-reservation-event-test:{$request->id}",
            'reason' => 'Reservasi tidak boleh mengambil hak protected.',
            'created_by' => $actor->id,
            'occurred_at' => now(),
        ]);
        $before = $this->domainSnapshot($employee);

        try {
            app(LeaveUsageReconciliationService::class)->replaceAnnualReconciliationSet(
                $current,
                [2024 => 0, 2025 => 0, 2026 => 4],
                Carbon::parse('2026-08-18'),
                'Koreksi yang menyisakan dua puluh hari bruto.',
                'Reservasi wajib dihitung dari saldo tanpa hak protected.',
                $actor,
            );
            $this->fail('Reservasi seharusnya ditolak ketika hanya cukup dengan mengambil hak Rule 3.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('saldo', $exception->errors());
        }

        $this->assertSame($before, $this->domainSnapshot($employee));
    }

    public function test_kegagalan_audit_merollback_fact_projection_dan_ledger(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_issue9_audit() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'audit intentionally rejected';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER reject_issue9_audit_trigger
BEFORE INSERT ON audit_logs
FOR EACH ROW EXECUTE FUNCTION reject_issue9_audit();
SQL);

        try {
            try {
                $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 0]);
                $this->fail('Kegagalan audit harus menggagalkan transaksi domain.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('audit intentionally rejected', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_issue9_audit_trigger ON audit_logs; DROP FUNCTION IF EXISTS reject_issue9_audit();');
        }

        $this->assertDatabaseCount('leave_usage_reconciliation_sets', 0);
        $this->assertDatabaseCount('leave_usage_records', 0);
        $this->assertDatabaseCount('leave_balances', 0);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
    }

    public function test_audit_manusia_dan_system_actor_disimpan_eksplisit(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $this->createSet($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 0]);

        $humanAudit = AuditLog::query()
            ->where('auditable_type', 'LeaveUsageRecord')
            ->where('event', 'CREATE')
            ->firstOrFail();
        $this->assertSame($actor->id, $humanAudit->user_id);
        $this->assertSame('admin_kepegawaian', $humanAudit->new_values['actor_role']);
        $this->assertSame('usage_recorded', $humanAudit->new_values['operation']);
        $this->assertSame('Rekonsiliasi pengujian.', $humanAudit->new_values['reason']);
        $this->assertNull($humanAudit->old_values);

        $this->manualFact($employee, $actor, 2026, '2026-08-01', 1);
        $service = app(LeaveBalanceRecalculationService::class);
        $service->recalculateForSystem(
            $employee,
            2026,
            'Replay scheduler.',
            'SIMPEG Scheduler',
        );

        $systemAudit = AuditLog::query()
            ->whereNull('user_id')
            ->where('user_name', 'SIMPEG Scheduler')
            ->where('auditable_type', 'LeaveBalance')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertSame('system', $systemAudit->new_values['actor_type']);
        $this->assertSame('Replay scheduler.', $systemAudit->new_values['reason']);
        $this->assertArrayHasKey('before', $systemAudit->new_values);
        $this->assertArrayHasKey('after', $systemAudit->new_values);

        $this->expectException(ValidationException::class);
        $service->recalculateForSystem($employee, 2026, 'Aktor tidak sah.', 'Cron Arbitrer');
    }

    /** @return array{Employee, User} */
    private function employeeAndActor(): array
    {
        return $this->employeeWithAppointment('2020-01-01');
    }

    /** @return array{Employee, User} */
    private function employeeWithAppointment(string $tmt): array
    {
        $this->annualType();
        $pns = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => $tmt,
        ]);

        return [$employee, User::factory()->adminKepegawaian()->create()];
    }

    /** @param array<int, int> $usage */
    private function createSet(Employee $employee, User $actor, array $usage): LeaveUsageReconciliationSet
    {
        return $this->createSetFor($employee, $actor, 2026, $usage, '2026-08-18');
    }

    /** @param array<int, int> $usage */
    private function createSetFor(
        Employee $employee,
        User $actor,
        int $balanceYear,
        array $usage,
        string $reconciledAt,
    ): LeaveUsageReconciliationSet {
        return app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            $balanceYear,
            $usage,
            Carbon::parse($reconciledAt),
            'Rekonsiliasi pengujian.',
            $actor,
        );
    }

    private function manualFact(
        Employee $employee,
        User $actor,
        int $year,
        string $date,
        int $workdays,
    ): LeaveUsageRecord {
        $record = LeaveUsageRecord::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $this->annualType()->id,
            'source_type' => 'manual_external',
            'usage_year' => $year,
            'effective_date' => $date,
            'start_date' => $date,
            'end_date' => $date,
            'workdays' => $workdays,
            'administrative_note' => 'Fact itemized untuk pengujian.',
            'record_status' => 'active',
            'recorded_by' => $actor->id,
        ]);

        return $this->attachValidManualApprovalSnapshot($record);
    }

    private function annualType(): RefJenisCuti
    {
        return RefJenisCuti::firstOrCreate(
            ['code' => 'tahunan'],
            ['nama' => 'Cuti Tahunan', 'mengurangi_saldo_tahunan' => true, 'khusus_pns' => false],
        );
    }

    private function largeLeaveType(): RefJenisCuti
    {
        return RefJenisCuti::firstOrCreate(
            ['code' => 'besar'],
            ['nama' => 'Cuti Besar', 'mengurangi_saldo_tahunan' => false, 'khusus_pns' => true],
        );
    }

    /** @return array<string, mixed> */
    private function domainSnapshot(Employee $employee): array
    {
        return [
            'sets' => LeaveUsageReconciliationSet::query()->whereBelongsTo($employee)->orderBy('id')->get()->toArray(),
            'facts' => LeaveUsageRecord::query()->whereBelongsTo($employee)->orderBy('id')->get()->toArray(),
            'memberships' => LeaveUsageReconciliationMembership::query()->orderBy('id')->get()->toArray(),
            'balances' => LeaveBalance::query()->whereBelongsTo($employee)->orderBy('tahun')->get()->toArray(),
            'ledger_count' => LeaveBalanceLedger::query()->whereBelongsTo($employee)->count(),
            'reservations' => LeaveBalanceReservationEvent::query()->whereBelongsTo($employee)->orderBy('id')->get()->toArray(),
            'audit_count' => AuditLog::query()->count(),
        ];
    }

    private function approvedRequest(Employee $employee, string $date, int $days): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $this->annualType()->id,
            'tanggal_mulai' => $date,
            'tanggal_selesai' => $date,
            'jumlah_hari_kerja' => $days,
            'alasan' => 'Pengajuan final pengujian.',
            'status' => 'disetujui',
        ]);
    }

    private function activeRequest(Employee $employee, string $date, int $days): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $this->annualType()->id,
            'tanggal_mulai' => $date,
            'tanggal_selesai' => $date,
            'jumlah_hari_kerja' => $days,
            'alasan' => 'Pengajuan aktif pengujian.',
            'status' => 'menunggu_approval',
        ]);
    }
}
