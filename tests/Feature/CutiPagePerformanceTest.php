<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
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
