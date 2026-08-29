<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Menjaga halaman cuti tetap memakai pagination/filtering database agar aman saat data bertambah.
 */
class CutiPagePerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_daftar_cuti_memakai_limit_database_bukan_paginasi_collection(): void
    {
        $user = User::factory()->superAdmin()->create();
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Sakit Performance',
            'code' => 'cuti_sakit_performance',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $employee = Employee::factory()->create();

        foreach (range(1, 12) as $index) {
            LeaveRequest::create([
                'employee_id' => $employee->id,
                'jenis_cuti_id' => $jenis->id,
                'tanggal_mulai' => "2026-07-{$this->day($index)}",
                'tanggal_selesai' => "2026-07-{$this->day($index)}",
                'jumlah_hari_kerja' => 1,
                'alasan' => "Smoke pagination {$index}",
                'status' => 'menunggu_approval',
            ]);
        }

        $queries = $this->captureQueries(fn () => $this->actingAs($user)->get(route('cuti')));

        $leaveRequestListQueries = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "leave_requests"')
            && str_contains($sql, 'order by')
            && str_contains($sql, 'select *'));

        $this->assertTrue(
            $leaveRequestListQueries->contains(fn (string $sql) => str_contains($sql, 'limit')),
            'Daftar cuti harus memakai LIMIT dari database.',
        );
        $this->assertFalse(
            $leaveRequestListQueries->contains(fn (string $sql) => ! str_contains($sql, 'limit')),
            'Daftar cuti tidak boleh mengambil seluruh leave_requests lalu dipaginasi di collection.',
        );
    }

    public function test_rekap_cuti_tidak_mengambil_semua_saldo_untuk_ringkasan(): void
    {
        $user = User::factory()->superAdmin()->create();

        foreach (range(1, 12) as $index) {
            LeaveBalance::create([
                'employee_id' => Employee::factory()->create()->id,
                'tahun' => 2026,
                'jatah_awal' => 12,
                'carry_over' => 0,
                'terpakai' => $index,
                'sisa' => 12 - $index,
            ]);
        }

        $queries = $this->captureQueries(fn () => $this->actingAs($user)->get(route('cuti.rekap')));

        $fullBalanceQueries = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "leave_balances"')
            && str_contains($sql, 'select *')
            && ! str_contains($sql, 'limit'));

        $this->assertTrue(
            $fullBalanceQueries->isEmpty(),
            'Rekap cuti harus memakai agregat database, bukan mengambil seluruh leave_balances untuk summary.',
        );
    }

    public function test_detail_rekap_union_memakai_limit_database_dan_query_count_tetap_bounded(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Rekap Union Performance',
            'code' => 'rekap_union_performance',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);

        foreach (range(1, 12) as $index) {
            LeaveRequest::create([
                'employee_id' => $employee->id,
                'jenis_cuti_id' => $jenis->id,
                'tanggal_mulai' => "2026-07-{$this->day($index)}",
                'tanggal_selesai' => "2026-07-{$this->day($index)}",
                'jumlah_hari_kerja' => 1,
                'alasan' => "Request union {$index}",
                'status' => 'menunggu_approval',
            ]);
            $record = LeaveUsageRecord::query()->forceCreate([
                'employee_id' => $employee->id,
                'leave_type_id' => $jenis->id,
                'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
                'reconciliation_set_id' => null,
                'leave_request_id' => null,
                'leave_request_case_id' => null,
                'usage_year' => 2026,
                'effective_date' => "2026-08-{$this->day($index)}",
                'start_date' => "2026-08-{$this->day($index)}",
                'end_date' => "2026-08-{$this->day($index)}",
                'workdays' => 1,
                'administrative_note' => "Manual union {$index}",
                'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
                'recorded_by' => null,
            ]);
            $this->attachValidManualApprovalSnapshot($record);
        }

        $this->actingAs($user);
        $queries = $this->captureQueries(fn () => $this->get(route('cuti.rekap', [
            'pegawai' => $employee->id,
        ])));
        $detailQueries = collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'union all')
            && str_contains($sql, 'leave_requests')
            && str_contains($sql, 'leave_usage_records'));

        $this->assertTrue(
            $detailQueries->contains(fn (string $sql): bool => str_contains($sql, 'limit')),
            'Detail rekap gabungan harus dipaginasi oleh PostgreSQL setelah UNION ALL.',
        );
        $this->assertLessThanOrEqual(20, count($queries));
    }

    public function test_rekap_dan_layout_menyelesaikan_capability_role_hanya_sekali_per_request(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        foreach (range(1, 12) as $index) {
            LeaveBalance::create([
                'employee_id' => Employee::factory()->create()->id,
                'tahun' => 2026,
                'jatah_awal' => 12,
                'carry_over' => 0,
                'terpakai' => $index,
                'sisa' => 12 - $index,
            ]);
        }

        $queries = $this->captureQueries(fn () => $this->actingAs($user)->get(route('cuti.rekap')));
        $permissionQueries = collect($queries)->filter(
            fn (string $sql): bool => str_contains($sql, 'role_permissions')
                && str_contains($sql, 'permissions'),
        );

        $this->assertCount(
            1,
            $permissionQueries,
            'Capability menu dan aksi per baris harus memakai satu pembacaan permission yang di-cache per request.',
        );
    }

    /** @return list<string> */
    private function captureQueries(callable $callback): array
    {
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $callback();
        $response->assertOk();

        return $queries;
    }

    private function day(int $index): string
    {
        return str_pad((string) $index, 2, '0', STR_PAD_LEFT);
    }
}
