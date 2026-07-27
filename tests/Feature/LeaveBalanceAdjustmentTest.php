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

    public function test_opening_balance_set_bisa_diedit_sebelum_ada_pemotongan(): void
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();
        $service = app(LeaveBalanceService::class);

        $service->setOpeningBalance($employee, 2027, [
            'n2' => 1,
            'n1' => 2,
            'current' => 12,
        ], 'Input saldo awal pertama.', $actor);
        $service->setOpeningBalance($employee, 2027, [
            'n2' => 5,
            'n1' => 5,
            'current' => 12,
        ], 'Percobaan input ulang.', $actor);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(5, $balance->sisa_n2);
        $this->assertSame(5, $balance->sisa_n1);
        $this->assertSame(22, $balance->sisa);
        $this->assertSame(1, LeaveBalanceLedger::where('event_type', 'opening_balance_set')->count());
        $this->assertDatabaseHas('leave_balance_ledger', [
            'event_type' => 'opening_balance_set',
            'amount' => 22,
            'reason' => 'Percobaan input ulang.',
        ]);
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

    public function test_manual_adjustment_credit_menambah_bucket_dan_mencatat_audit(): void
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->superAdmin()->create();
        LeaveBalance::create($this->balancePayload($employee, 2027, [
            'sisa_n2' => 1,
            'sisa_n1' => 2,
            'sisa_tahun_berjalan' => 8,
            'carry_over' => 3,
            'sisa' => 11,
        ]));

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
        LeaveBalance::create($this->balancePayload($employee, 2027, [
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 2,
            'sisa' => 2,
        ]));

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
            'periode' => 2027,
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

    public function test_admin_kepegawaian_bisa_koreksi_saldo_via_web(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->adminKepegawaian()->create();
        LeaveBalance::create($this->balancePayload($employee, 2027, [
            'sisa_tahun_berjalan' => 5,
            'sisa' => 5,
        ]));

        $response = $this->actingAs($user)->post(route('cuti.saldo.adjust', $employee), [
            'tahun' => 2027,
            'bucket' => 'current',
            'amount' => -3,
            'reason' => 'Koreksi saldo setelah validasi dokumen.',
        ]);

        $response->assertRedirect(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'periode' => 2027,
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

    public function test_administrasi_saldo_cuti_menolak_role_tanpa_hak_koreksi(): void
    {
        foreach (['pimpinan', 'kepala_bagian', 'pegawai'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('cuti.saldo.administrasi'))
                ->assertForbidden();
        }
    }

    public function test_tamu_dialihkan_saat_membuka_administrasi_saldo_cuti(): void
    {
        $this->get(route('cuti.saldo.administrasi'))
            ->assertRedirect(route('login'));
    }

    public function test_administrasi_saldo_cuti_menampilkan_tab_dan_formulir_mutasi(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Saldo Admin']);
        $user = User::factory()->adminKepegawaian()->create();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2027, [
            'sisa_n2' => 1,
            'sisa_n1' => 2,
            'sisa_tahun_berjalan' => 9,
            'carry_over' => 3,
            'sisa' => 12,
        ]));
        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2027,
            'event_type' => 'manual_adjustment',
            'amount' => -1,
            'source_year' => 2027,
            'reason' => 'Koreksi tampil di ledger.',
            'occurred_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($user)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'periode' => 2027,
        ]));

        $response->assertOk();
        $response->assertSee('Pendaftaran Saldo Awal', false);
        $response->assertSee('Koreksi Saldo', false);
        $response->assertSee(route('cuti.saldo.opening-balance', $employee), false);
        $response->assertSee(route('cuti.saldo.adjust', $employee), false);
        $response->assertSee('Ledger Saldo', false);
        $response->assertSee('Status Rollover', false);
        $response->assertSee('Koreksi tampil di ledger.', false);
        $response->assertDontSee('Jalankan Rollover', false);
        $response->assertDontSee('data per halaman', false);
        $response->assertDontSee('Preview PDF resmi', false);
        $response->assertDontSee('Periode Laporan:', false);
        $response->assertDontSee('>Tahunan<', false);
        $response->assertDontSee('>Sakit<', false);
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
}
