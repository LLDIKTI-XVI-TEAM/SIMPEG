<?php

namespace Tests\Feature;

use App\Actions\Cuti\ReconcileAnnualLeaveAnniversaryAction;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\RefJenisPegawai;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeaveUsageSingleSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_reconciliation_tahunan_tidak_lagi_memiliki_route_mutasi_aktif(): void
    {
        $this->assertFalse(Route::has('cuti.reconciliation.store'));
        $this->assertFalse(Route::has('cuti.reconciliation.correct'));
        $this->assertFalse(Route::has('cuti.reconciliation.document.download'));
        $this->assertTrue(Route::has('cuti.manual.store'));
        $this->assertTrue(Route::has('cuti.manual.correct'));
        $this->assertTrue(Route::has('cuti.manual.cancel'));
    }

    public function test_schema_tidak_lagi_menyimpan_sumber_agregat_rekonsiliasi_tahunan(): void
    {
        $this->assertFalse(Schema::hasTable('leave_usage_reconciliation_sets'));
        $this->assertFalse(Schema::hasTable('leave_usage_reconciliation_memberships'));
        $this->assertFalse(Schema::hasColumn('leave_usage_records', 'reconciliation_set_id'));
        $this->assertFalse(Schema::hasColumn('leave_usage_documents', 'leave_usage_reconciliation_set_id'));
    }

    public function test_administrasi_saldo_hanya_menampilkan_ringkasan_baca_saja_dan_cuti_luar_simpeg(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($admin)
            ->get(route('cuti.saldo.administrasi', [
                'pegawai' => $employee->id,
                'status' => 'semua_pegawai',
            ]))
            ->assertOk()
            ->assertSee('Ringkasan Pemakaian Tahunan', false)
            ->assertSee('Cuti di Luar SIMPEG', false)
            ->assertSee('Ledger &amp; Rollover', false)
            ->assertDontSee('Simpan Pemakaian Tahunan', false)
            ->assertDontSee('Catat Pemakaian Tahunan', false)
            ->assertDontSee('Perbaiki Data Pemakaian', false);
    }

    /** @param array{sisa:int,sisa_n2:int,sisa_n1:int,sisa_tahun_berjalan:int} $expected */
    #[DataProvider('employeesWithoutUsage')]
    public function test_tahun_tanpa_fakta_tetap_dihitung_sebagai_nol_pada_horizon_n2_n1_dan_tahun_berjalan(
        string $employeeType,
        string $appointmentDate,
        ?string $contractEnd,
        array $expected,
    ): void {
        Carbon::setTestNow('2026-08-25 10:00:00');

        try {
            $employee = Employee::factory()->create([
                'jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', $employeeType)->value('id'),
                'tanggal_akhir_kontrak' => $contractEnd,
            ]);
            $employee->appointment()->create([
                'jenis_pengangkatan' => $employeeType,
                'tmt_pengangkatan' => $appointmentDate,
                'no_sk' => 'SK-SUMBER-TUNGGAL-2020',
                'tanggal_sk' => '2020-01-01',
            ]);
            $result = app(ReconcileAnnualLeaveAnniversaryAction::class)->execute(now(), 50);

            $this->assertSame(1, $result['processed']);
            $this->assertSame(0, $result['failed']);
            $this->assertSame([], $employee->leaveUsageRecords()->get()->all());
            $this->assertDatabaseHas('leave_balances', [
                'employee_id' => $employee->id,
                'tahun' => 2026,
                'terpakai' => 0,
                ...$expected,
            ]);
            $this->assertSame(1, LeaveBalance::query()->whereBelongsTo($employee)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @return array<string, array{string,string,?string,array{sisa:int,sisa_n2:int,sisa_n1:int,sisa_tahun_berjalan:int}}> */
    public static function employeesWithoutUsage(): array
    {
        return [
            'PNS eligible tiga tahun' => ['PNS', '2020-01-01', null, ['sisa' => 24, 'sisa_n2' => 6, 'sisa_n1' => 6, 'sisa_tahun_berjalan' => 12]],
            'PNS anniversary pertama' => ['PNS', '2025-08-25', null, ['sisa' => 12, 'sisa_n2' => 0, 'sisa_n1' => 0, 'sisa_tahun_berjalan' => 12]],
            'PNS eligible mulai N-1' => ['PNS', '2024-01-01', null, ['sisa' => 18, 'sisa_n2' => 0, 'sisa_n1' => 6, 'sisa_tahun_berjalan' => 12]],
            'PPPK kontrak tidak lengkap' => ['PPPK', '2020-01-01', null, ['sisa' => 12, 'sisa_n2' => 0, 'sisa_n1' => 0, 'sisa_tahun_berjalan' => 12]],
            'PPPK tepat dua tahun' => ['PPPK', '2024-01-01', '2026-01-01', ['sisa' => 12, 'sisa_n2' => 0, 'sisa_n1' => 0, 'sisa_tahun_berjalan' => 12]],
            'PPPK di atas dua tahun' => ['PPPK', '2024-01-01', '2026-01-02', ['sisa' => 18, 'sisa_n2' => 0, 'sisa_n1' => 6, 'sisa_tahun_berjalan' => 12]],
            'PPPK tepat tiga tahun' => ['PPPK', '2023-01-01', '2026-01-01', ['sisa' => 18, 'sisa_n2' => 6, 'sisa_n1' => 0, 'sisa_tahun_berjalan' => 12]],
            'PPPK di atas tiga tahun' => ['PPPK', '2023-01-01', '2026-01-02', ['sisa' => 24, 'sisa_n2' => 6, 'sisa_n1' => 6, 'sisa_tahun_berjalan' => 12]],
        ];
    }
}
