<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Menguji koreksi saldo cuti tahunan berbasis ledger.
 * Saldo harus tetap append-only: koreksi tidak boleh mengubah fakta cuti yang sudah disetujui.
 */
class LeaveBalanceAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Carbon::setTestNow('2027-02-03 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_opening_balance_set_menulis_ledger_summary_dan_audit(): void
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();

        app(LeaveBalanceService::class)->setOpeningBalance($employee, 2027, [
            'n2' => 2,
            'n1' => 4,
            'current' => 12,
        ], 'Input saldo awal hasil rekonsiliasi manual.', $actor);

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'jatah_awal' => 12,
            'carry_over' => 6,
            'terpakai' => 0,
            'sisa' => 18,
            'sisa_n2' => 2,
            'sisa_n1' => 4,
            'sisa_tahun_berjalan' => 12,
            'hangus' => 0,
        ]);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => 'opening_balance_set',
            'amount' => 18,
            'dedup_key' => "{$employee->id}:2027:opening_balance_set",
            'created_by' => $actor->id,
        ]);

        $audit = AuditLog::where('event', 'LEAVE_BALANCE_OPENING_SET')->firstOrFail();
        $this->assertSame('LeaveBalance', $audit->auditable_type);
        $this->assertSame($employee->id, $audit->new_values['employee_id']);
        $this->assertSame(18, $audit->new_values['new_balance']);
        $this->assertSame('Input saldo awal hasil rekonsiliasi manual.', $audit->new_values['reason']);
        $this->assertSame($actor->id, $audit->new_values['corrected_by']);
    }

    public function test_opening_balance_set_hanya_sekali_dan_baseline_tetap_immutable(): void
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();
        $service = app(LeaveBalanceService::class);

        $service->setOpeningBalance($employee, 2027, [
            'n2' => 1,
            'n1' => 2,
            'current' => 12,
        ], 'Input saldo awal pertama.', $actor);
        $opening = LeaveBalanceLedger::where('event_type', LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET)->firstOrFail();
        $original = $this->immutableLedgerPayload($opening);
        $auditCount = AuditLog::count();

        try {
            $service->setOpeningBalance($employee, 2027, [
                'n2' => 5,
                'n1' => 5,
                'current' => 12,
            ], 'Percobaan input ulang.', $actor);
            $this->fail('Pembukaan kedua harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Saldo awal pegawai sudah tercatat. Gunakan Koreksi Saldo untuk perubahan yang dapat diaudit.',
                $exception->errors()['saldo'][0],
            );
        }

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(1, $balance->sisa_n2);
        $this->assertSame(2, $balance->sisa_n1);
        $this->assertSame(15, $balance->sisa);
        $this->assertSame(1, LeaveBalanceLedger::where('event_type', 'opening_balance_set')->count());
        $this->assertSame($original, $this->immutableLedgerPayload($opening->fresh()));
        $this->assertSame($auditCount, AuditLog::count());
    }

    public function test_opening_balance_set_tidak_bisa_diedit_setelah_ada_pemotongan(): void
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();
        $service = app(LeaveBalanceService::class);
        $balance = $service->setOpeningBalance($employee, 2027, [
            'n2' => 1,
            'n1' => 2,
            'current' => 12,
        ], 'Input saldo awal pertama.', $actor);
        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2027,
            'event_type' => 'leave_deducted',
            'amount' => -1,
            'source_year' => 2027,
            'reason' => 'Pemotongan final yang sudah menjadi fakta.',
            'dedup_key' => "leave_deducted:test:{$employee->id}:2027",
            'occurred_at' => Carbon::now(),
        ]);

        $this->expectException(ValidationException::class);
        $service->setOpeningBalance($employee, 2027, [
            'n2' => 5,
            'n1' => 5,
            'current' => 12,
        ], 'Percobaan ubah setelah pemotongan.', $actor);
    }

    public function test_opening_balance_set_tidak_bisa_dibuat_setelah_pemotongan_tanpa_saldo_awal_sebelumnya(): void
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2027, [
            'sisa_tahun_berjalan' => 10,
            'terpakai' => 2,
            'terpakai_tahun_berjalan' => 2,
            'sisa' => 10,
        ]));
        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2027,
            'event_type' => 'leave_deducted',
            'amount' => -2,
            'source_year' => 2027,
            'reason' => 'Pemotongan final tanpa input saldo awal manual.',
            'dedup_key' => "leave_deducted:test:{$employee->id}:2027:no-opening",
            'occurred_at' => Carbon::now(),
        ]);

        $this->expectException(ValidationException::class);
        app(LeaveBalanceService::class)->setOpeningBalance($employee, 2027, [
            'n2' => 0,
            'n1' => 0,
            'current' => 12,
        ], 'Percobaan saldo awal setelah cuti terpakai.', $actor);
    }

    public function test_manual_adjustment_ditolak_sebelum_inisialisasi_tanpa_efek_samping(): void
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();

        try {
            app(LeaveBalanceService::class)->adjustBalance(
                $employee,
                2027,
                'current',
                2,
                'Percobaan koreksi sebelum saldo awal.',
                $actor,
            );
            $this->fail('Koreksi sebelum inisialisasi harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Daftarkan saldo awal pegawai terlebih dahulu sebelum melakukan koreksi.',
                $exception->errors()['saldo'][0],
            );
        }

        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
        ]);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_balance_row_dan_legacy_manual_adjustment_tidak_dianggap_inisialisasi(): void
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2027));
        $this->createLedgerEvent(
            $employee,
            $balance,
            2027,
            LeaveBalanceLedger::EVENT_MANUAL_ADJUSTMENT,
            'Koreksi legacy tanpa sumber inisialisasi.',
        );
        $summaryBefore = $balance->only(['sisa_n2', 'sisa_n1', 'sisa_tahun_berjalan', 'sisa']);
        $ledgerCount = LeaveBalanceLedger::count();
        $auditCount = AuditLog::count();

        try {
            app(LeaveBalanceService::class)->adjustBalance(
                $employee,
                2027,
                'current',
                1,
                'Percobaan koreksi orphan kedua.',
                $actor,
            );
            $this->fail('Ledger koreksi legacy tidak boleh membuka lifecycle saldo.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('saldo', $exception->errors());
        }

        $this->assertSame($summaryBefore, $balance->fresh()->only(array_keys($summaryBefore)));
        $this->assertSame($ledgerCount, LeaveBalanceLedger::count());
        $this->assertSame($auditCount, AuditLog::count());
    }

    public function test_koreksi_setelah_pembukaan_mengubah_saldo_aktual_tanpa_mengubah_baseline(): void
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();
        $service = app(LeaveBalanceService::class);
        $service->setOpeningBalance($employee, 2027, [
            'n2' => 2,
            'n1' => 4,
            'current' => 12,
        ], 'Baseline admin yang immutable.', $actor);
        $opening = LeaveBalanceLedger::where('event_type', LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET)->firstOrFail();
        $baseline = $this->immutableLedgerPayload($opening);

        $service->adjustBalance($employee, 2027, 'current', -3, 'Koreksi hasil rekonsiliasi.', $actor);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(9, $balance->sisa_tahun_berjalan);
        $this->assertSame(15, $balance->sisa);
        $this->assertSame($baseline, $this->immutableLedgerPayload($opening->fresh()));
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_MANUAL_ADJUSTMENT,
            'amount' => -3,
            'reason' => 'Koreksi hasil rekonsiliasi.',
        ]);
    }

    public function test_inisialisasi_sistem_mengunci_pembukaan_dan_mengizinkan_koreksi(): void
    {
        foreach ([
            LeaveBalanceLedger::EVENT_ANNUAL_ENTITLEMENT_GRANTED,
            LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
        ] as $eventType) {
            $employee = Employee::factory()->create();
            $actor = User::factory()->adminKepegawaian()->create();
            $balance = LeaveBalance::create($this->balancePayload($employee, 2027, [
                'sisa_n1' => $eventType === LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED ? 4 : 0,
                'carry_over' => $eventType === LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED ? 4 : 0,
                'sisa' => $eventType === LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED ? 16 : 12,
            ]));
            $this->createLedgerEvent($employee, $balance, 2027, $eventType, 'Inisialisasi resmi oleh sistem.');
            $summaryBefore = $balance->only(['sisa_n2', 'sisa_n1', 'sisa_tahun_berjalan', 'sisa']);
            $ledgerCount = LeaveBalanceLedger::count();
            $auditCount = AuditLog::count();

            try {
                app(LeaveBalanceService::class)->setOpeningBalance($employee, 2027, [
                    'n2' => 1,
                    'n1' => 1,
                    'current' => 12,
                ], 'Percobaan menimpa inisialisasi sistem.', $actor);
                $this->fail('Pembukaan admin setelah inisialisasi sistem harus ditolak.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('saldo', $exception->errors());
            }

            $this->assertSame($summaryBefore, $balance->fresh()->only(array_keys($summaryBefore)));
            $this->assertSame($ledgerCount, LeaveBalanceLedger::count());
            $this->assertSame($auditCount, AuditLog::count());

            app(LeaveBalanceService::class)->adjustBalance(
                $employee,
                2027,
                'current',
                1,
                'Koreksi setelah inisialisasi sistem.',
                $actor,
            );

            $this->assertSame($summaryBefore['sisa'] + 1, $balance->fresh()->sisa);
        }
    }

    public function test_manual_adjustment_credit_menambah_bucket_dan_mencatat_audit(): void
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->superAdmin()->create();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2027, [
            'sisa_n2' => 1,
            'sisa_n1' => 2,
            'sisa_tahun_berjalan' => 8,
            'carry_over' => 3,
            'sisa' => 11,
        ]));
        $this->createLedgerEvent($employee, $balance, 2027, LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET, 'Baseline koreksi kredit.');

        app(LeaveBalanceService::class)->adjustBalance($employee, 2027, 'current', 3, 'Koreksi tambahan hak cuti tahunan.', $actor);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(11, $balance->sisa_tahun_berjalan);
        $this->assertSame(14, $balance->sisa);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => 'manual_adjustment',
            'amount' => 3,
            'source_year' => 2027,
            'dedup_key' => null,
            'created_by' => $actor->id,
        ]);

        $audit = AuditLog::where('event', 'LEAVE_BALANCE_CORRECTED')->firstOrFail();
        $this->assertSame(11, $audit->old_values['old_balance']);
        $this->assertSame(14, $audit->new_values['new_balance']);
        $this->assertSame(3, $audit->new_values['delta']);
    }

    public function test_manual_adjustment_menangani_bucket_legacy_yang_kosong(): void
    {
        // Baris legacy pra-ledger: migrasi bucket bersifat additif dengan default 0 (NOT NULL),
        // sehingga baris lama tetap punya `sisa`/`carry_over` nyata sementara bucket baru masih nol.
        // Service harus memetakan carry_over ke N-1 dan sisa selebihnya ke tahun berjalan saat koreksi.
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2027, [
            'jatah_awal' => 12,
            'carry_over' => 2,
            'terpakai' => 9,
            'sisa' => 5,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 0,
        ]));
        $this->createLedgerEvent($employee, $balance, 2027, LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET, 'Baseline legacy yang sudah terdaftar.');

        app(LeaveBalanceService::class)->adjustBalance($employee, 2027, 'current', 1, 'Koreksi dari saldo legacy kosong.', $actor);

        $balance->refresh();
        // carry_over lama (2) dipetakan ke N-1; sisa lama 5 dikurangi N-1 menjadi 3 di tahun berjalan, lalu +1 koreksi.
        $this->assertSame(0, $balance->sisa_n2);
        $this->assertSame(2, $balance->sisa_n1);
        $this->assertSame(4, $balance->sisa_tahun_berjalan);
        $this->assertSame(6, $balance->sisa);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => 'manual_adjustment',
            'amount' => 1,
            'source_year' => 2027,
        ]);
    }

    public function test_manual_adjustment_debit_diclamps_supaya_saldo_tidak_negatif(): void
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2027, [
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 2,
            'sisa' => 2,
        ]));
        $this->createLedgerEvent($employee, $balance, 2027, LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET, 'Baseline koreksi debit.');

        app(LeaveBalanceService::class)->adjustBalance($employee, 2027, 'current', -10, 'Koreksi kelebihan input; niat -10.', $actor);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $balance->sisa_tahun_berjalan);
        $this->assertSame(0, $balance->sisa);
        $ledger = LeaveBalanceLedger::where('event_type', 'manual_adjustment')->firstOrFail();
        $this->assertSame(-2, $ledger->amount);
        $this->assertSame(10, $ledger->metadata['niat_pengurangan']);
        $this->assertSame(2, $ledger->metadata['diterapkan']);

        $audit = AuditLog::where('event', 'LEAVE_BALANCE_CORRECTED')->firstOrFail();
        $this->assertSame(-2, $audit->new_values['delta']);
        $this->assertSame(10, $audit->new_values['niat_pengurangan']);
        $this->assertSame(2, $audit->new_values['diterapkan']);
    }

    public function test_manual_debit_cannot_consume_protected_bucket_and_rolls_back_without_effect(): void
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();
        $service = app(LeaveBalanceService::class);
        $balance = LeaveBalance::create($this->balancePayload($employee, 2027, [
            'sisa_tahun_berjalan' => 5,
            'sisa' => 5,
        ]));
        $this->createLedgerEvent($employee, $balance, 2027, LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET, 'Baseline debit terlindungi.');
        $protectedRequestId = (string) str()->uuid();
        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'leave_request_id' => null,
            'leave_balance_id' => $balance->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED,
            'amount' => 0,
            'source_year' => 2027,
            'reason' => 'Hak empat hari dilindungi.',
            'dedup_key' => "duty_postponement:{$protectedRequestId}",
            'metadata' => [
                'request_id' => $protectedRequestId,
                'protected_days' => 4,
                'protected_allocations' => ['n2' => 0, 'n1' => 0, 'current' => 4],
                'expiry_policy' => 'valid_one_year_no_n2_aging',
            ],
            'created_by' => $actor->id,
        ]);
        $summaryBefore = $balance->only(['sisa_n2', 'sisa_n1', 'sisa_tahun_berjalan', 'sisa']);
        $ledgerCount = LeaveBalanceLedger::count();
        $auditCount = AuditLog::count();

        try {
            $service->adjustBalance($employee, 2027, 'current', -2, 'Debit melebihi satu hari yang tidak terlindungi.', $actor);
            $this->fail('Debit yang memerlukan hak terlindungi wajib ditolak seluruhnya.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertSame($summaryBefore, $balance->fresh()->only(array_keys($summaryBefore)));
        $this->assertSame($ledgerCount, LeaveBalanceLedger::count());
        $this->assertSame($auditCount, AuditLog::count());
    }

    public function test_admin_balance_adjust_route_menolak_role_tanpa_permission(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pimpinan()->create();

        $response = $this->actingAs($user)->post(route('cuti.saldo.adjust', $employee), [
            'tahun' => 2027,
            'bucket' => 'current',
            'amount' => 1,
            'reason' => 'Percobaan koreksi oleh approver.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('leave_balance_ledger', 0);
    }

    public function test_admin_kepegawaian_bisa_set_opening_balance_via_web(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)->post(route('cuti.saldo.opening-balance', $employee), [
            'tahun' => 2027,
            'sisa_n2' => 2,
            'sisa_n1' => 4,
            'sisa_tahun_berjalan' => 12,
            'reason' => 'Input saldo awal melalui halaman admin.',
        ]);

        $response->assertRedirect(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
        ]));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'sisa' => 18,
            'sisa_n2' => 2,
            'sisa_n1' => 4,
            'sisa_tahun_berjalan' => 12,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'LEAVE_BALANCE_OPENING_SET',
            'auditable_type' => 'LeaveBalance',
        ]);
    }

    public function test_direct_post_menolak_koreksi_sebelum_inisialisasi_dan_pembukaan_duplikat(): void
    {
        $adjustmentEmployee = Employee::factory()->create();
        $openingEmployee = Employee::factory()->create();
        $user = User::factory()->adminKepegawaian()->create();
        $service = app(LeaveBalanceService::class);
        $service->setOpeningBalance($openingEmployee, 2027, [
            'n2' => 1,
            'n1' => 2,
            'current' => 12,
        ], 'Pembukaan pertama via service.', $user);
        $opening = LeaveBalanceLedger::where('employee_id', $openingEmployee->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET)
            ->firstOrFail();
        $openingBefore = $this->immutableLedgerPayload($opening);
        $balanceBefore = LeaveBalance::where('employee_id', $openingEmployee->id)->firstOrFail()
            ->only(['sisa_n2', 'sisa_n1', 'sisa_tahun_berjalan', 'sisa']);
        $ledgerCount = LeaveBalanceLedger::count();
        $auditCount = AuditLog::count();

        $this->actingAs($user)->post(route('cuti.saldo.adjust', $adjustmentEmployee), [
            'tahun' => 2027,
            'bucket' => 'current',
            'amount' => 1,
            'reason' => 'Direct POST sebelum inisialisasi.',
        ])->assertSessionHasErrors([
            'saldo' => 'Daftarkan saldo awal pegawai terlebih dahulu sebelum melakukan koreksi.',
        ]);

        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $adjustmentEmployee->id,
            'tahun' => 2027,
        ]);
        $this->assertSame($ledgerCount, LeaveBalanceLedger::count());
        $this->assertSame($auditCount, AuditLog::count());
        $this->flushSession();

        $this->actingAs($user)->post(route('cuti.saldo.opening-balance', $openingEmployee), [
            'tahun' => 2027,
            'sisa_n2' => 6,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
            'reason' => 'Direct POST pembukaan duplikat.',
        ])->assertSessionHasErrors([
            'saldo' => 'Saldo awal pegawai sudah tercatat. Gunakan Koreksi Saldo untuk perubahan yang dapat diaudit.',
        ]);

        $this->assertSame($balanceBefore, LeaveBalance::where('employee_id', $openingEmployee->id)->firstOrFail()
            ->only(array_keys($balanceBefore)));
        $this->assertSame($openingBefore, $this->immutableLedgerPayload($opening->fresh()));
        $this->assertSame($ledgerCount, LeaveBalanceLedger::count());
        $this->assertSame($auditCount, AuditLog::count());
    }

    public function test_admin_kepegawaian_bisa_koreksi_saldo_via_web(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->adminKepegawaian()->create();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2027, [
            'sisa_tahun_berjalan' => 5,
            'sisa' => 5,
        ]));
        $this->createLedgerEvent($employee, $balance, 2027, LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET, 'Baseline koreksi web.');

        $response = $this->actingAs($user)->post(route('cuti.saldo.adjust', $employee), [
            'tahun' => 2027,
            'bucket' => 'current',
            'amount' => -3,
            'reason' => 'Koreksi saldo setelah validasi dokumen.',
        ]);

        $response->assertRedirect(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
        ]));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'sisa_tahun_berjalan' => 2,
            'sisa' => 2,
        ]);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'event_type' => 'manual_adjustment',
            'amount' => -3,
            'created_by' => $user->id,
        ]);
    }

    public function test_opening_balance_redirect_mempertahankan_konteks_filter_dan_tab(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)->post(route('cuti.saldo.opening-balance', $employee), [
            'tahun' => 2027,
            'sisa_n2' => 2,
            'sisa_n1' => 4,
            'sisa_tahun_berjalan' => 12,
            'reason' => 'Input saldo awal dengan konteks daftar.',
            'status' => 'semua_pegawai',
            'search' => 'Sutarto',
            'tab' => 'pendaftaran',
            'page_pegawai' => 2,
        ]);

        $response->assertRedirect(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'search' => 'Sutarto',
            'tab' => 'pendaftaran',
            'page_pegawai' => 2,
        ]));
    }

    public function test_adjustment_redirect_mempertahankan_konteks_filter_dan_tab(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->adminKepegawaian()->create();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2027));
        $this->createLedgerEvent($employee, $balance, 2027, LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET, 'Baseline koreksi dengan konteks daftar.');

        $response = $this->actingAs($user)->post(route('cuti.saldo.adjust', $employee), [
            'tahun' => 2027,
            'bucket' => 'current',
            'amount' => 1,
            'reason' => 'Koreksi saldo dengan konteks daftar.',
            'status' => 'semua_pegawai',
            'search' => 'Sutarto',
            'tab' => 'koreksi',
            'page_pegawai' => 3,
        ]);

        $response->assertRedirect(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'search' => 'Sutarto',
            'tab' => 'koreksi',
            'page_pegawai' => 3,
        ]));
    }

    public function test_form_mutasi_menolak_konteks_return_yang_tidak_aman(): void
    {
        $openingEmployee = Employee::factory()->create();
        $adjustmentEmployee = Employee::factory()->create();
        $user = User::factory()->adminKepegawaian()->create();
        LeaveBalance::create($this->balancePayload($adjustmentEmployee, 2027));
        $invalidContext = [
            'status' => 'aktif',
            'search' => str_repeat('a', 151),
            'tab' => 'hapus',
        ];

        $ledgerCountBeforeOpening = LeaveBalanceLedger::count();
        $openingResponse = $this->actingAs($user)->post(
            route('cuti.saldo.opening-balance', $openingEmployee),
            [
                'tahun' => 2027,
                'sisa_n2' => 2,
                'sisa_n1' => 4,
                'sisa_tahun_berjalan' => 12,
                'reason' => 'Payload domain pembukaan tetap valid.',
                ...$invalidContext,
            ],
        );

        $openingResponse->assertSessionHasErrors(['status', 'search', 'tab']);
        $this->assertSame($ledgerCountBeforeOpening, LeaveBalanceLedger::count());

        $this->flushSession();
        $ledgerCountBeforeAdjustment = LeaveBalanceLedger::count();
        $adjustmentResponse = $this->actingAs($user)->post(
            route('cuti.saldo.adjust', $adjustmentEmployee),
            [
                'tahun' => 2027,
                'bucket' => 'current',
                'amount' => 1,
                'reason' => 'Payload domain koreksi tetap valid.',
                ...$invalidContext,
            ],
        );

        $adjustmentResponse->assertSessionHasErrors(['status', 'search', 'tab']);
        $this->assertSame($ledgerCountBeforeAdjustment, LeaveBalanceLedger::count());
    }

    public function test_form_mutasi_menolak_page_pegawai_yang_tidak_valid_tanpa_mutasi(): void
    {
        $openingEmployee = Employee::factory()->create();
        $adjustmentEmployee = Employee::factory()->create();
        $user = User::factory()->adminKepegawaian()->create();
        LeaveBalance::create($this->balancePayload($adjustmentEmployee, 2027));

        foreach (['bukan-angka', 0] as $invalidPage) {
            $ledgerCount = LeaveBalanceLedger::count();

            $this->actingAs($user)->post(route('cuti.saldo.opening-balance', $openingEmployee), [
                'tahun' => 2027,
                'sisa_n2' => 2,
                'sisa_n1' => 4,
                'sisa_tahun_berjalan' => 12,
                'reason' => 'Payload pembukaan valid selain halaman daftar.',
                'page_pegawai' => $invalidPage,
            ])->assertSessionHasErrors('page_pegawai');

            $this->assertSame($ledgerCount, LeaveBalanceLedger::count());
            $this->flushSession();

            $this->actingAs($user)->post(route('cuti.saldo.adjust', $adjustmentEmployee), [
                'tahun' => 2027,
                'bucket' => 'current',
                'amount' => 1,
                'reason' => 'Payload koreksi valid selain halaman daftar.',
                'page_pegawai' => $invalidPage,
            ])->assertSessionHasErrors('page_pegawai');

            $this->assertSame($ledgerCount, LeaveBalanceLedger::count());
            $this->flushSession();
        }
    }

    public function test_validasi_mutasi_kembali_ke_referer_dengan_old_input(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->adminKepegawaian()->create();
        LeaveBalance::create($this->balancePayload($employee, 2027));
        $referer = route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'perlu_tindakan',
            'search' => 'Pegawai',
            'tab' => 'koreksi',
            'page_pegawai' => 2,
            'page_ledger' => 3,
        ]);

        $response = $this->actingAs($user)
            ->from($referer)
            ->post(route('cuti.saldo.adjust', $employee), [
                'tahun' => 2027,
                'bucket' => 'current',
                'amount' => 1,
                'reason' => '',
                'status' => 'perlu_tindakan',
                'search' => 'Pegawai',
                'tab' => 'koreksi',
                'page_pegawai' => 2,
            ]);

        $response->assertRedirect($referer);
        $response->assertSessionHasErrors('reason');
        $response->assertSessionHasInput('status', 'perlu_tindakan');
        $response->assertSessionHasInput('search', 'Pegawai');
        $response->assertSessionHasInput('tab', 'koreksi');
        $response->assertSessionHasInput('page_pegawai', 2);
    }

    public function test_koreksi_saldo_via_web_wajib_punya_alasan(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)->post(route('cuti.saldo.adjust', $employee), [
            'tahun' => 2027,
            'bucket' => 'current',
            'amount' => 2,
            'reason' => '',
        ]);

        $response->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('leave_balance_ledger', 0);
    }

    public function test_super_admin_dan_admin_kepegawaian_bisa_membuka_administrasi_saldo_cuti(): void
    {
        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->adminKepegawaian()->create(),
        ] as $user) {
            $this->actingAs($user)->get(route('cuti.saldo.administrasi'))->assertOk();
        }
    }

    public function test_administrasi_saldo_cuti_menolak_filter_pegawai_dengan_uuid_rusak(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->get(route('cuti.saldo.administrasi', ['pegawai' => 'bukan-uuid']))
            ->assertNotFound();
    }

    public function test_administrasi_saldo_cuti_menolak_format_periode_yang_ambigu(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        foreach (['2026.5', '02026', '2e3'] as $periode) {
            $this->actingAs($user)
                ->get(route('cuti.saldo.administrasi', ['periode' => $periode]))
                ->assertNotFound();
        }
    }

    public function test_administrasi_saldo_cuti_memakai_tahun_aplikasi_sebagai_periode_tepercaya(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)->get(route('cuti.saldo.administrasi'));

        $response->assertOk();
        $response->assertViewHas('periode', '2027');
        $response->assertSee('Kelola saldo cuti tahun 2027. Cari pegawai berdasarkan nama atau NIP.', false);
        $response->assertDontSee('name="periode"', false);
    }

    public function test_administrasi_saldo_cuti_menolak_periode_historis_dan_masa_depan(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        foreach ([2026, 2028] as $periode) {
            $this->actingAs($user)
                ->get(route('cuti.saldo.administrasi', ['periode' => $periode]))
                ->assertNotFound();
        }
    }

    public function test_periode_tahun_berjalan_diterima_sebagai_input_lama_tetapi_url_admin_tetap_bersih(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai URL Bersih']);
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)->get(route('cuti.saldo.administrasi', [
            'periode' => 2027,
            'status' => 'semua_pegawai',
            'search' => 'Pegawai',
        ]));

        $response->assertOk();
        $response->assertViewHas('periode', '2027');
        $this->assertStringNotContainsString('periode=', $response->getContent());
        $response->assertSee(route('cuti.saldo.administrasi', [
            'status' => 'semua_pegawai',
            'search' => 'Pegawai',
            'pegawai' => $employee->id,
            'tab' => 'pendaftaran',
        ]));
    }

    public function test_mutasi_saldo_menolak_tahun_nonaktif_tanpa_efek_samping(): void
    {
        $openingEmployee = Employee::factory()->create();
        $adjustmentEmployee = Employee::factory()->create();
        $user = User::factory()->adminKepegawaian()->create();
        LeaveBalance::create($this->balancePayload($adjustmentEmployee, 2027));

        foreach ([2026, 2028] as $tahun) {
            $summaryCount = LeaveBalance::count();
            $ledgerCount = LeaveBalanceLedger::count();
            $auditCount = AuditLog::count();

            $this->actingAs($user)->post(route('cuti.saldo.opening-balance', $openingEmployee), [
                'tahun' => $tahun,
                'sisa_n2' => 2,
                'sisa_n1' => 4,
                'sisa_tahun_berjalan' => 12,
                'reason' => 'Percobaan pembukaan untuk tahun nonaktif.',
            ])->assertSessionHasErrors('tahun');

            $this->assertSame($summaryCount, LeaveBalance::count());
            $this->assertSame($ledgerCount, LeaveBalanceLedger::count());
            $this->assertSame($auditCount, AuditLog::count());
            $this->flushSession();

            $this->actingAs($user)->post(route('cuti.saldo.adjust', $adjustmentEmployee), [
                'tahun' => $tahun,
                'bucket' => 'current',
                'amount' => 1,
                'reason' => 'Percobaan koreksi untuk tahun nonaktif.',
            ])->assertSessionHasErrors('tahun');

            $this->assertSame($summaryCount, LeaveBalance::count());
            $this->assertSame($ledgerCount, LeaveBalanceLedger::count());
            $this->assertSame($auditCount, AuditLog::count());
            $this->flushSession();
        }
    }

    public function test_administrasi_saldo_memvalidasi_filter_dan_pagination(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        foreach ([
            ['status' => 'aktif', 'field' => 'status'],
            ['search' => str_repeat('a', 151), 'field' => 'search'],
            ['tab' => 'ringkasan', 'field' => 'tab'],
            ['page_pegawai' => 0, 'field' => 'page_pegawai'],
            ['page_ledger' => -1, 'field' => 'page_ledger'],
        ] as $case) {
            $field = $case['field'];
            unset($case['field']);

            $this->actingAs($user)
                ->get(route('cuti.saldo.administrasi', $case))
                ->assertSessionHasErrors($field);
        }

        foreach ([
            ['periode' => ['2027']],
            ['pegawai' => ['00000000-0000-0000-0000-000000000000']],
        ] as $query) {
            $this->actingAs($user)
                ->get(route('cuti.saldo.administrasi').'?'.http_build_query($query))
                ->assertNotFound();
        }
    }

    public function test_administrasi_saldo_cuti_menolak_role_tanpa_hak_koreksi(): void
    {
        $employeeMarker = 'Pegawai Queue Rahasia RBAC';
        Employee::factory()->create(['nama_lengkap' => $employeeMarker]);

        foreach (['pimpinan', 'kepala_bagian', 'pegawai'] as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('cuti.saldo.administrasi'));

            $response->assertForbidden();
            $response->assertDontSee($employeeMarker, false);
        }
    }

    public function test_tamu_dialihkan_saat_membuka_administrasi_saldo_cuti(): void
    {
        $this->get(route('cuti.saldo.administrasi'))
            ->assertRedirect(route('login'));
    }

    public function test_administrasi_saldo_sebelum_inisialisasi_mengunci_koreksi_dan_mengaktifkan_pendaftaran(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Belum Inisialisasi']);
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'tab' => 'koreksi',
        ]));

        $response->assertOk();
        $response->assertViewHas('balanceInitialization', function (array $initialization): bool {
            $this->assertFalse($initialization['initialized']);
            $this->assertNull($initialization['source']);

            return true;
        });
        $response->assertSee('Daftarkan saldo awal pegawai terlebih dahulu sebelum melakukan koreksi.', false);
        $response->assertSee('aria-disabled="true"', false);
        $response->assertSee('x-on:click.prevent', false);
        $response->assertSee('x-on:keydown.home.prevent="selectTab(tabs[0])"', false);
        $response->assertSee('x-on:keydown.end.prevent="selectTab(tabs[tabs.length - 1])"', false);
        $response->assertDontSee('x-on:keydown.end.prevent="selectTab(&#039;koreksi&#039;)"', false);
        $response->assertSee(route('cuti.saldo.opening-balance', $employee), false);
        $response->assertDontSee(route('cuti.saldo.adjust', $employee), false);
        $response->assertDontSee('Saldo awal pegawai sudah tercatat.', false);
    }

    public function test_administrasi_saldo_setelah_pembukaan_menampilkan_baseline_read_only_dan_koreksi(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Saldo Admin']);
        $user = User::factory()->adminKepegawaian()->create();
        $service = app(LeaveBalanceService::class);
        $service->setOpeningBalance($employee, 2027, [
            'n2' => 1,
            'n1' => 2,
            'current' => 12,
        ], 'Baseline admin tampil baca-saja.', $user);
        $service->adjustBalance($employee, 2027, 'current', -3, 'Koreksi tampil di ledger.', $user);

        $response = $this->actingAs($user)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
        ]));

        $response->assertOk();
        $response->assertViewHas('balanceInitialization', function (array $initialization) use ($user): bool {
            $this->assertTrue($initialization['initialized']);
            $this->assertSame('admin', $initialization['source']);
            $this->assertSame(['n2' => 1, 'n1' => 2, 'current' => 12], $initialization['baseline']);
            $this->assertSame($user->id, $initialization['actor_id']);

            return true;
        });
        $response->assertSee('Pendaftaran Saldo Awal', false);
        $response->assertSee('Koreksi Saldo', false);
        $response->assertSee('Saldo awal pegawai sudah tercatat. Gunakan Koreksi Saldo untuk menambah atau mengurangi saldo dengan alasan yang dapat diaudit.', false);
        $response->assertSee('Sumber inisialisasi', false);
        $response->assertSee('Admin', false);
        $response->assertSee('Baseline N-2 (2025)', false);
        $response->assertSee('Baseline N-1 (2026)', false);
        $response->assertSee('Baseline tahun berjalan (2027)', false);
        $response->assertSee('>1<', false);
        $response->assertSee('>2<', false);
        $response->assertSee('>12<', false);
        $response->assertDontSee(route('cuti.saldo.opening-balance', $employee), false);
        $response->assertSee(route('cuti.saldo.adjust', $employee), false);
        $response->assertSee('Ledger Saldo', false);
        $response->assertSee('Status Rollover', false);
        $response->assertSee('Koreksi tampil di ledger.', false);
        $response->assertSee('Saldo N-2 (2025)', false);
        $response->assertSee('Saldo N-1 (2026)', false);
        $response->assertSee('Saldo tahun berjalan (2027)', false);
        $response->assertSee('Saldo ini adalah nilai berjalan dan dapat berubah melalui koreksi atau pemotongan cuti.', false);
        $response->assertDontSee('Jalankan Rollover', false);
        $response->assertDontSee('data per halaman', false);
        $response->assertDontSee('Preview PDF resmi', false);
        $response->assertDontSee('Periode Laporan:', false);
        $response->assertDontSee('>Tahunan<', false);
        $response->assertDontSee('>Sakit<', false);
    }

    public function test_administrasi_saldo_inisialisasi_rollover_menampilkan_baseline_sistem_read_only(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Rollover']);
        $user = User::factory()->adminKepegawaian()->create();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2027, [
            'sisa_n1' => 5,
            'carry_over' => 5,
            'sisa' => 17,
        ]));
        $this->createLedgerEvent($employee, $balance, 2027, LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED, 'Rollover resmi sistem.');
        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_ANNUAL_ENTITLEMENT_GRANTED,
            'amount' => 12,
            'source_year' => 2027,
            'reason' => 'Jatah tahunan sistem.',
            'occurred_at' => Carbon::now(),
        ]);
        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED,
            'amount' => 5,
            'source_year' => 2026,
            'reason' => 'Carry-over sistem.',
            'metadata' => ['n2' => 0, 'n1' => 5],
            'occurred_at' => Carbon::now(),
        ]);
        app(LeaveBalanceService::class)->adjustBalance(
            $employee,
            2027,
            'current',
            -3,
            'Koreksi setelah baseline sistem tercatat.',
            $user,
        );

        $response = $this->actingAs($user)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
        ]));

        $response->assertOk();
        $response->assertViewHas('balanceInitialization', function (array $initialization): bool {
            $this->assertTrue($initialization['initialized']);
            $this->assertSame('system', $initialization['source']);
            $this->assertSame(['n2' => 0, 'n1' => 5, 'current' => 12], $initialization['baseline']);

            return true;
        });
        $response->assertViewHas('selectedBalance', function (LeaveBalance $currentBalance): bool {
            $this->assertSame(9, $currentBalance->sisa_tahun_berjalan);
            $this->assertSame(14, $currentBalance->sisa);

            return true;
        });
        $response->assertSee('Sistem/Rollover', false);
        $response->assertSee('Baseline ini dibentuk oleh entitlement tahunan dan rollover sistem.', false);
        $response->assertDontSee(route('cuti.saldo.opening-balance', $employee), false);
        $response->assertSee(route('cuti.saldo.adjust', $employee), false);
    }

    public function test_baseline_sistem_tidak_memakai_summary_mutable_saat_event_bucket_tidak_tersedia(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Baseline Parsial']);
        $user = User::factory()->adminKepegawaian()->create();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2027, [
            'sisa_n2' => 4,
            'sisa_n1' => 5,
            'sisa_tahun_berjalan' => 9,
            'carry_over' => 9,
            'sisa' => 18,
        ]));
        $this->createLedgerEvent(
            $employee,
            $balance,
            2027,
            LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
            'Marker rollover tanpa rincian baseline.',
        );

        $response = $this->actingAs($user)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
        ]));

        $response->assertOk();
        $response->assertViewHas('balanceInitialization', function (array $initialization): bool {
            $this->assertTrue($initialization['initialized']);
            $this->assertSame('system', $initialization['source']);
            $this->assertSame(['n2' => null, 'n1' => null, 'current' => null], $initialization['baseline']);

            return true;
        });
        $this->assertSame(3, substr_count($response->getContent(), '>Tidak tersedia<'));
        $response->assertSee('>4<', false);
        $response->assertSee('>5<', false);
        $response->assertSee('>9<', false);
    }

    public function test_administrasi_saldo_list_state_hanya_menampilkan_pencarian_status_dan_antrian(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Antrean Dua State',
            'nip' => '199901010001',
        ]);
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)->get(route('cuti.saldo.administrasi', [
            'status' => 'semua_pegawai',
            'search' => 'Pegawai',
        ]));

        $selectionUrl = route('cuti.saldo.administrasi', [
            'status' => 'semua_pegawai',
            'search' => 'Pegawai',
            'pegawai' => $employee->id,
            'tab' => 'pendaftaran',
        ]);
        $resetUrl = route('cuti.saldo.administrasi', [
            'status' => 'semua_pegawai',
        ]);

        $response->assertOk();
        $content = $response->getContent();
        $searchPosition = strpos($content, 'name="search"');
        $statusTabsPosition = strpos($content, 'aria-label="Status antrian pegawai"');

        $this->assertIsInt($searchPosition);
        $this->assertIsInt($statusTabsPosition);
        $this->assertLessThan($statusTabsPosition, $searchPosition);
        $response->assertDontSee('name="periode"', false);
        $response->assertSee('Cari pegawai', false);
        $response->assertSee('Masukkan nama atau NIP', false);
        $response->assertSee('Perlu Tindakan', false);
        $response->assertSee('Sudah Terdaftar', false);
        $response->assertSee('Semua Pegawai', false);
        $response->assertSee('Pegawai Antrean Dua State', false);
        $this->assertSame(2, substr_count($content, 'href="'.e($selectionUrl).'"'));
        $response->assertSee($resetUrl);
        foreach (['perlu_tindakan', 'sudah_terdaftar', 'semua_pegawai'] as $statusValue) {
            $statusUrl = route('cuti.saldo.administrasi', [
                'status' => $statusValue,
                'search' => 'Pegawai',
            ]);

            $this->assertStringContainsString('href="'.e($statusUrl).'"', $content);
            $this->assertArrayNotHasKey('pegawai', $this->queryFromUrl($statusUrl));
            $this->assertArrayNotHasKey('tab', $this->queryFromUrl($statusUrl));
            $this->assertArrayNotHasKey('page_ledger', $this->queryFromUrl($statusUrl));
        }
        $response->assertDontSee('Ringkasan Saldo Pegawai', false);
        $response->assertDontSee(route('cuti.saldo.opening-balance', $employee), false);
        $response->assertDontSee(route('cuti.saldo.adjust', $employee), false);
        $response->assertDontSee('Ledger Saldo', false);
    }

    public function test_administrasi_saldo_selected_state_hanya_menampilkan_workspace_dan_back_ke_antrian(): void
    {
        $selectedEmployee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Workspace Terpilih',
            'nip' => '198801010001',
        ]);
        Employee::factory()->create(['nama_lengkap' => 'Pegawai Queue Tidak Tampil']);
        $user = User::factory()->adminKepegawaian()->create();
        $balance = LeaveBalance::create($this->balancePayload($selectedEmployee, 2027));
        $this->createLedgerEvent(
            $selectedEmployee,
            $balance,
            2027,
            LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET,
            'Riwayat workspace dua state.',
        );

        $response = $this->actingAs($user)->get(route('cuti.saldo.administrasi', [
            'status' => 'semua_pegawai',
            'search' => 'Pegawai',
            'pegawai' => $selectedEmployee->id,
            'tab' => 'koreksi',
            'page_pegawai' => 2,
            'page_ledger' => 1,
        ]));
        $backUrl = route('cuti.saldo.administrasi', [
            'status' => 'semua_pegawai',
            'search' => 'Pegawai',
            'page_pegawai' => 2,
        ]);

        $response->assertOk();
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<a href="'.preg_quote(e($backUrl), '/').'"[^>]*>\s*Kembali ke antrian pegawai\s*<\/a>/',
            $content,
        );
        $backQuery = $this->queryFromUrl($backUrl);
        $this->assertSame([
            'status' => 'semua_pegawai',
            'search' => 'Pegawai',
            'page_pegawai' => '2',
        ], $backQuery);
        $this->assertArrayNotHasKey('pegawai', $backQuery);
        $this->assertArrayNotHasKey('tab', $backQuery);
        $this->assertArrayNotHasKey('page_ledger', $backQuery);
        $response->assertSee('Kembali ke antrian pegawai', false);
        $response->assertSee('Pegawai Workspace Terpilih', false);
        $response->assertSee('198801010001', false);
        $response->assertSee('Ringkasan Saldo Pegawai', false);
        $response->assertSee('Riwayat workspace dua state.', false);
        $response->assertDontSee(route('cuti.saldo.opening-balance', $selectedEmployee), false);
        $response->assertSee(route('cuti.saldo.adjust', $selectedEmployee), false);
        $response->assertDontSee('Antrian Administrasi Saldo', false);
        $response->assertDontSee('Pegawai Queue Tidak Tampil', false);
        $response->assertDontSee('name="pegawai"', false);
        $response->assertDontSee('page_ledger=1', false);
    }

    public function test_administrasi_saldo_default_menampilkan_queue_perlu_tindakan_dengan_tiga_klasifikasi(): void
    {
        $andi = Employee::factory()->create([
            'nama_lengkap' => 'Andi Tanpa Saldo',
            'nip' => '198001',
        ]);
        $budi = Employee::factory()->create([
            'nama_lengkap' => 'Budi Tanpa Pembukaan',
            'nip' => '198002',
        ]);
        $citra = Employee::factory()->create([
            'nama_lengkap' => 'Citra Sudah Terdaftar',
            'nip' => '198003',
        ]);
        $balancePending = LeaveBalance::create($this->balancePayload($budi, 2027));
        $citraBalance = LeaveBalance::create($this->balancePayload($citra, 2027));
        $this->createLedgerEvent(
            $citra,
            $citraBalance,
            2027,
            LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET,
            'Saldo awal Citra sudah tercatat.',
        );

        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('cuti.saldo.administrasi'));

        $response->assertOk();
        $response->assertViewHas('status', 'perlu_tindakan');
        $response->assertViewHas('employeeRows', function ($employeeRows) use ($andi, $budi): bool {
            $this->assertSame('page_pegawai', $employeeRows->getPageName());
            $this->assertSame(10, $employeeRows->perPage());
            $this->assertSame([$andi->id, $budi->id], $employeeRows->pluck('employee_id')->all());
            $this->assertSame(
                ['saldo_belum_tersedia', 'pembukaan_belum_tercatat'],
                $employeeRows->pluck('status_code')->all(),
            );

            return true;
        });
        $response->assertViewHas('statusCounts', [
            'perlu_tindakan' => 2,
            'sudah_terdaftar' => 1,
            'semua_pegawai' => 3,
        ]);
        $response->assertDontSee('Citra Sudah Terdaftar', false);
    }

    public function test_administrasi_saldo_filter_sudah_terdaftar_hanya_memuat_opening_event_tahun_aktif(): void
    {
        Employee::factory()->create([
            'nama_lengkap' => 'Andi Tanpa Saldo',
            'nip' => '198001',
        ]);
        $budi = Employee::factory()->create([
            'nama_lengkap' => 'Budi Tanpa Pembukaan',
            'nip' => '198002',
        ]);
        $citra = Employee::factory()->create([
            'nama_lengkap' => 'Citra Sudah Terdaftar',
            'nip' => '198003',
        ]);
        LeaveBalance::create($this->balancePayload($budi, 2027));
        $citraBalance = LeaveBalance::create($this->balancePayload($citra, 2027));
        $this->createLedgerEvent(
            $citra,
            $citraBalance,
            2027,
            LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET,
            'Saldo awal Citra sudah tercatat.',
        );

        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('cuti.saldo.administrasi', [
                'status' => 'sudah_terdaftar',
            ]));

        $response->assertOk();
        $response->assertViewHas('status', 'sudah_terdaftar');
        $response->assertViewHas('employeeRows', function ($employeeRows) use ($citra): bool {
            $this->assertSame([$citra->id], $employeeRows->pluck('employee_id')->all());
            $this->assertSame(['saldo_awal_tercatat'], $employeeRows->pluck('status_code')->all());

            return true;
        });
    }

    public function test_antrian_menganggap_inisialisasi_sistem_sudah_terdaftar(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Inisialisasi Sistem',
            'nip' => '198009',
        ]);
        $balance = LeaveBalance::create($this->balancePayload($employee, 2027));
        $this->createLedgerEvent(
            $employee,
            $balance,
            2027,
            LeaveBalanceLedger::EVENT_ANNUAL_ENTITLEMENT_GRANTED,
            'Entitlement tahunan resmi sistem.',
        );

        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('cuti.saldo.administrasi', ['status' => 'sudah_terdaftar']));

        $response->assertOk();
        $response->assertViewHas('employeeRows', function ($rows) use ($employee): bool {
            $row = $rows->firstWhere('employee_id', $employee->id);

            $this->assertNotNull($row);
            $this->assertSame('saldo_awal_tercatat', $row['status_code']);

            return true;
        });
        $response->assertSee('Lihat atau koreksi saldo', false);
        $response->assertDontSee('Daftarkan saldo awal', false);
    }

    public function test_administrasi_saldo_filter_semua_pegawai_tidak_menerapkan_predikat_pembukaan(): void
    {
        $andi = Employee::factory()->create([
            'nama_lengkap' => 'Andi Tanpa Saldo',
            'nip' => '198001',
        ]);
        $budi = Employee::factory()->create([
            'nama_lengkap' => 'Budi Tanpa Pembukaan',
            'nip' => '198002',
        ]);
        $citra = Employee::factory()->create([
            'nama_lengkap' => 'Citra Sudah Terdaftar',
            'nip' => '198003',
        ]);
        LeaveBalance::create($this->balancePayload($budi, 2027));
        $citraBalance = LeaveBalance::create($this->balancePayload($citra, 2027));
        $this->createLedgerEvent(
            $citra,
            $citraBalance,
            2027,
            LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET,
            'Saldo awal Citra sudah tercatat.',
        );

        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('cuti.saldo.administrasi', [
                'status' => 'semua_pegawai',
            ]));

        $response->assertOk();
        $response->assertViewHas('status', 'semua_pegawai');
        $response->assertViewHas('employeeRows', function ($employeeRows) use ($andi, $budi, $citra): bool {
            $this->assertSame([$andi->id, $budi->id, $citra->id], $employeeRows->pluck('employee_id')->all());
            $this->assertSame(
                ['saldo_belum_tersedia', 'pembukaan_belum_tercatat', 'saldo_awal_tercatat'],
                $employeeRows->pluck('status_code')->all(),
            );

            return true;
        });
    }

    public function test_hitungan_status_mengabaikan_status_aktif_tetapi_menghormati_search(): void
    {
        $andiPending = Employee::factory()->create([
            'nama_lengkap' => 'Andi Pending',
            'nip' => '777001',
        ]);
        $andiRegistered = Employee::factory()->create([
            'nama_lengkap' => 'Andi Terdaftar',
            'nip' => '777002',
        ]);
        Employee::factory()->create([
            'nama_lengkap' => 'Bukan Hasil',
            'nip' => '888001',
        ]);
        $balance = LeaveBalance::create($this->balancePayload($andiRegistered, 2027));
        $this->createLedgerEvent(
            $andiRegistered,
            $balance,
            2027,
            LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET,
            'Pembukaan Andi terdaftar.',
        );
        $user = User::factory()->adminKepegawaian()->create();

        $registeredResponse = $this->actingAs($user)->get(route('cuti.saldo.administrasi', [
            'status' => 'sudah_terdaftar',
            'search' => 'ANDI',
        ]));

        $registeredResponse->assertOk();
        $registeredResponse->assertViewHas('search', 'ANDI');
        $registeredResponse->assertViewHas('statusCounts', [
            'perlu_tindakan' => 1,
            'sudah_terdaftar' => 1,
            'semua_pegawai' => 2,
        ]);
        $registeredResponse->assertViewHas('employeeRows', function ($rows) use ($andiRegistered): bool {
            $this->assertSame(1, $rows->total());
            $this->assertSame([$andiRegistered->id], $rows->pluck('employee_id')->all());

            return true;
        });

        $pendingResponse = $this->actingAs($user)->get(route('cuti.saldo.administrasi', [
            'status' => 'semua_pegawai',
            'search' => '777001',
        ]));

        $pendingResponse->assertOk();
        $pendingResponse->assertViewHas('search', '777001');
        $pendingResponse->assertViewHas('employeeRows', function ($rows) use ($andiPending): bool {
            $this->assertSame(1, $rows->total());
            $this->assertSame([$andiPending->id], $rows->pluck('employee_id')->all());

            return true;
        });
    }

    public function test_antrian_pegawai_dipaginasi_sepuluh_baris_dengan_page_name_terpisah(): void
    {
        $employees = collect(range(1, 11))->mapWithKeys(function (int $index): array {
            $employee = Employee::factory()->create([
                'nama_lengkap' => sprintf('Pegawai Antrian %02d', $index),
                'nip' => sprintf('990%03d', $index),
            ]);

            return [$index => $employee];
        });
        $user = User::factory()->adminKepegawaian()->create();
        $filters = [
            'status' => 'perlu_tindakan',
            'search' => 'Pegawai',
        ];

        $firstPage = $this->actingAs($user)->get(route('cuti.saldo.administrasi', $filters));

        $firstPage->assertOk();
        $firstPage->assertViewHas('employeeRows', function ($rows) use ($employees): bool {
            $this->assertSame('page_pegawai', $rows->getPageName());
            $this->assertSame(1, $rows->currentPage());
            $this->assertSame(10, $rows->count());
            $this->assertSame(11, $rows->total());
            $this->assertSame(
                $employees->take(10)->pluck('id')->all(),
                $rows->pluck('employee_id')->all(),
            );

            return true;
        });
        $firstPage->assertSee('page_pegawai=2', false);
        $firstPage->assertDontSee('this.$refs.queuePaginator', false);
        $firstPage->assertDontSee('page_pegawai=2&amp;tab=', false);
        $firstPage->assertSee('Pegawai Antrian 01', false);
        $firstPage->assertDontSee('Pegawai Antrian 11', false);

        $secondPage = $this->actingAs($user)->get(route('cuti.saldo.administrasi', [
            ...$filters,
            'page_pegawai' => 2,
        ]));

        $secondPage->assertOk();
        $secondPage->assertViewHas('employeeRows', function ($rows) use ($employees): bool {
            $this->assertSame('page_pegawai', $rows->getPageName());
            $this->assertSame(2, $rows->currentPage());
            $this->assertSame(1, $rows->count());
            $this->assertSame(11, $rows->total());
            $this->assertSame([$employees->get(11)->id], $rows->pluck('employee_id')->all());

            return true;
        });
        $secondPage->assertSee('Pegawai Antrian 11', false);
        $secondPage->assertDontSee('periode=2027', false);
        $secondPage->assertSee('status=perlu_tindakan', false);
        $secondPage->assertSee('search=Pegawai', false);
    }

    public function test_query_count_antrian_tidak_bertumbuh_per_pegawai(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Query Tunggal',
            'nip' => '992001',
        ]);

        $requestQueue = fn () => $this->actingAs($user)
            ->get(route('cuti.saldo.administrasi', [
                'status' => 'semua_pegawai',
            ]));
        $activeQueries = null;

        DB::listen(function ($query) use (&$activeQueries): void {
            if ($activeQueries !== null) {
                $activeQueries[] = $query->sql;
            }
        });

        $countQueries = function () use (&$activeQueries, $requestQueue): int {
            $activeQueries = [];

            try {
                $requestQueue()
                    ->assertOk();

                return count($activeQueries);
            } finally {
                $activeQueries = null;
            }
        };

        $requestQueue()
            ->assertOk();
        $singleCount = $countQueries();

        foreach (range(1, 20) as $index) {
            $employee = Employee::factory()->create([
                'nama_lengkap' => sprintf('Pegawai Query Banyak %02d', $index),
                'nip' => sprintf('993%03d', $index),
            ]);

            if ($index % 3 === 1) {
                LeaveBalance::create($this->balancePayload($employee, 2027));
            }

            if ($index % 3 === 2) {
                $balance = LeaveBalance::create($this->balancePayload($employee, 2027));
                $this->createLedgerEvent(
                    $employee,
                    $balance,
                    2027,
                    LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET,
                    sprintf('Pembukaan query pegawai %02d.', $index),
                );
            }
        }

        $requestQueue()
            ->assertOk();
        $manyCount = $countQueries();

        $this->assertLessThanOrEqual($singleCount + 2, $manyCount);
    }

    public function test_event_pembukaan_tahun_lain_tidak_mendaftarkan_pegawai_pada_periode_aktif(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Dewi Pembukaan Tahun Lalu',
            'nip' => '198004',
        ]);
        $balance2026 = LeaveBalance::create($this->balancePayload($employee, 2026));
        $this->createLedgerEvent(
            $employee,
            $balance2026,
            2026,
            LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET,
            'Saldo awal hanya berlaku untuk 2026.',
        );
        LeaveBalance::create($this->balancePayload($employee, 2027));

        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('cuti.saldo.administrasi', [
                'status' => 'perlu_tindakan',
            ]));

        $response->assertOk();
        $response->assertViewHas('employeeRows', function ($rows) use ($employee): bool {
            $row = $rows->firstWhere('employee_id', $employee->id);

            $this->assertNotNull($row);
            $this->assertTrue($row['has_balance_row']);
            $this->assertFalse($row['has_opening_event_same_year']);
            $this->assertSame('pembukaan_belum_tercatat', $row['status_code']);

            return true;
        });
    }

    public function test_ringkasan_saldo_membedakan_baris_hilang_dari_nilai_nol_persisted(): void
    {
        $tanpaSaldo = Employee::factory()->create([
            'nama_lengkap' => 'Eka Tanpa Saldo',
            'nip' => '198005',
        ]);
        $saldoNol = Employee::factory()->create([
            'nama_lengkap' => 'Fani Saldo Nol',
            'nip' => '198006',
        ]);
        LeaveBalance::create($this->balancePayload($saldoNol, 2027, [
            'jatah_awal' => 0,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 0,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 0,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]));
        $user = User::factory()->adminKepegawaian()->create();

        $missingResponse = $this->actingAs($user)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $tanpaSaldo->id,
        ]));

        $missingResponse->assertOk();
        $missingResponse->assertViewHas('selectedBalance', null);
        $missingResponse->assertSee('Belum ada saldo untuk pegawai dan tahun ini.', false);
        foreach (['N-2', 'N-1', 'Tahun berjalan', 'Terpakai', 'Hangus'] as $balanceCardLabel) {
            $missingResponse->assertDontSee(">{$balanceCardLabel}</p>", false);
        }

        $zeroResponse = $this->actingAs($user)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $saldoNol->id,
        ]));

        $zeroResponse->assertOk();
        $zeroResponse->assertViewHas('selectedBalance', function (?LeaveBalance $balance): bool {
            $this->assertNotNull($balance);
            $this->assertSame(0, $balance->sisa_n2);
            $this->assertSame(0, $balance->sisa_n1);
            $this->assertSame(0, $balance->sisa_tahun_berjalan);
            $this->assertSame(0, $balance->terpakai);
            $this->assertSame(0, $balance->hangus);

            return true;
        });
        $zeroResponse->assertSee('>0<', false);
        $zeroResponse->assertDontSee('Belum ada saldo untuk pegawai dan tahun ini.', false);
    }

    public function test_rekap_cuti_tidak_lagi_menampilkan_formulir_mutasi_saldo(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)->get(route('cuti.rekap', [
            'pegawai' => $employee->id,
            'periode' => 2027,
        ]));

        $response->assertOk();
        $response->assertDontSee(route('cuti.saldo.opening-balance', $employee), false);
        $response->assertDontSee(route('cuti.saldo.adjust', $employee), false);
    }

    public function test_administrasi_saldo_cuti_menyediakan_navigasi_halaman_ledger(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->adminKepegawaian()->create();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2027));

        // Ledger dipaginasi 10 baris; mutasi ke-11 hanya terjangkau bila kontrol halaman dirender.
        foreach (range(1, 11) as $index) {
            LeaveBalanceLedger::create([
                'employee_id' => $employee->id,
                'leave_balance_id' => $balance->id,
                'tahun' => 2027,
                'event_type' => 'manual_adjustment',
                'amount' => -1,
                'source_year' => 2027,
                'reason' => sprintf('Koreksi ledger urutan %02d.', $index),
                'occurred_at' => Carbon::now()->subMinutes(11 - $index),
            ]);
        }

        $firstPage = $this->actingAs($user)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
        ]));

        $firstPage->assertOk();
        $firstPage->assertSee('page_ledger=2', false);
        $firstPage->assertSee('Koreksi ledger urutan 11.', false);
        $firstPage->assertDontSee('Koreksi ledger urutan 01.', false);

        $secondPage = $this->actingAs($user)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'page_ledger' => 2,
        ]));

        $secondPage->assertOk();
        $secondPage->assertSee('Koreksi ledger urutan 01.', false);
    }

    public function test_tab_dan_paginator_ledger_mempertahankan_state_workspace_yang_relevan(): void
    {
        $employees = collect(range(1, 11))->map(function (int $index): Employee {
            return Employee::factory()->create([
                'nama_lengkap' => sprintf('Pegawai State %02d', $index),
                'nip' => sprintf('991%03d', $index),
            ]);
        });
        $selectedEmployee = $employees->first();
        $this->assertInstanceOf(Employee::class, $selectedEmployee);
        $balance = LeaveBalance::create($this->balancePayload($selectedEmployee, 2027));

        foreach (range(1, 11) as $index) {
            $this->createLedgerEvent(
                $selectedEmployee,
                $balance,
                2027,
                LeaveBalanceLedger::EVENT_MANUAL_ADJUSTMENT,
                sprintf('Ledger state urutan %02d.', $index),
                Carbon::now()->subMinutes(11 - $index),
            );
        }

        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('cuti.saldo.administrasi', [
                'pegawai' => $selectedEmployee->id,
                'status' => 'semua_pegawai',
                'search' => 'Pegawai',
                'tab' => 'koreksi',
                'page_pegawai' => 1,
                'page_ledger' => 2,
            ]));

        $response->assertOk();
        $response->assertViewHas('tab', 'koreksi');
        foreach ([
            '<input type="hidden" name="status" value="semua_pegawai">',
            '<input type="hidden" name="search" value="Pegawai">',
            '<input type="hidden" name="tab" value="koreksi" x-bind:value="activeTab">',
            '<input type="hidden" name="page_pegawai" value="1">',
        ] as $preservedInput) {
            $response->assertSee($preservedInput, false);
        }
        $response->assertDontSee('<input type="hidden" name="page_ledger"', false);

        foreach ([
            'page_ledger=1',
            'status=semua_pegawai',
            'search=Pegawai',
            'tab=koreksi',
        ] as $queryFragment) {
            $response->assertSee($queryFragment, false);
        }

        $backUrl = route('cuti.saldo.administrasi', [
            'status' => 'semua_pegawai',
            'search' => 'Pegawai',
            'page_pegawai' => 1,
        ]);
        $unsafeBackUrl = route('cuti.saldo.administrasi', [
            'status' => 'semua_pegawai',
            'search' => 'Pegawai',
            'page_pegawai' => 1,
            'page_ledger' => 1,
        ]);

        $response->assertSee($backUrl);
        $response->assertDontSee($unsafeBackUrl);
        $response->assertDontSee('Pegawai State 02', false);
    }

    public function test_workspace_ledger_dan_rollover_hanya_memuat_periode_aktif(): void
    {
        $employee = Employee::factory()->create();
        $balance2026 = LeaveBalance::create($this->balancePayload($employee, 2026));
        $balance2027 = LeaveBalance::create($this->balancePayload($employee, 2027));

        $this->createLedgerEvent(
            $employee,
            $balance2026,
            2026,
            LeaveBalanceLedger::EVENT_MANUAL_ADJUSTMENT,
            'Ledger tahun lama tidak boleh tampil.',
        );
        $this->createLedgerEvent(
            $employee,
            $balance2027,
            2027,
            LeaveBalanceLedger::EVENT_MANUAL_ADJUSTMENT,
            'Ledger tahun aktif tampil.',
        );
        $this->createLedgerEvent(
            $employee,
            $balance2026,
            2026,
            LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
            'Rollover tahun lama tidak boleh tampil.',
        );
        $this->createLedgerEvent(
            $employee,
            $balance2027,
            2027,
            LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
            'Rollover tahun aktif tampil.',
        );

        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('cuti.saldo.administrasi', [
                'pegawai' => $employee->id,
                'tab' => 'koreksi',
            ]));

        $response->assertOk();
        $response->assertSee('Ledger tahun aktif tampil.', false);
        $response->assertSee('Rollover tahun aktif tampil.', false);
        $response->assertDontSee('Ledger tahun lama tidak boleh tampil.', false);
        $response->assertDontSee('Rollover tahun lama tidak boleh tampil.', false);
        $response->assertViewHas('rolloverRows', function ($rolloverRows): bool {
            $this->assertSame([2027], $rolloverRows->pluck('tahun')->unique()->values()->all());

            return true;
        });
        $response->assertViewHas('ledgerRows', function ($ledgerRows): bool {
            $this->assertSame(2, $ledgerRows->total());
            $this->assertSame(2, $ledgerRows->count());
            $this->assertSame([2027], $ledgerRows->pluck('tahun')->unique()->values()->all());

            return true;
        });
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

    private function createLedgerEvent(
        Employee $employee,
        LeaveBalance $balance,
        int $tahun,
        string $eventType,
        string $reason,
        ?Carbon $occurredAt = null,
    ): LeaveBalanceLedger {
        return LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $balance->id,
            'tahun' => $tahun,
            'event_type' => $eventType,
            'amount' => 0,
            'source_year' => $tahun,
            'reason' => $reason,
            'occurred_at' => $occurredAt ?? Carbon::now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function immutableLedgerPayload(LeaveBalanceLedger $ledger): array
    {
        return [
            'amount' => $ledger->amount,
            'reason' => $ledger->reason,
            'metadata' => $ledger->metadata,
            'created_by' => $ledger->created_by,
            'occurred_at' => $ledger->occurred_at?->toIso8601String(),
            'created_at' => $ledger->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, string> */
    private function queryFromUrl(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
