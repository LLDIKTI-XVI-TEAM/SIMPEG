<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\User;
use App\Services\Cuti\LeaveUsageReconciliationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('serial')]
class LeaveBalanceRecalculationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private ?string $raceDirectory = null;

    protected function setUp(): void
    {
        $driver = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION');

        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Race rekonsiliasi saldo wajib diuji pada PostgreSQL.');
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->raceDirectory !== null) {
            File::deleteDirectory($this->raceDirectory);
        }

        if ($this->app !== null && DB::getDriverName() === 'pgsql') {
            $this->cleanupProtectedDatabaseEvidence();
        }

        parent::tearDown();
    }

    /** Bersihkan histori committed worker sebelum hook DatabaseMigrations menurunkan schema disposable. */
    private function cleanupProtectedDatabaseEvidence(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS leave_balance_ledger_no_update_delete ON leave_balance_ledger;
DROP TRIGGER IF EXISTS leave_balance_ledger_no_truncate ON leave_balance_ledger;
DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs;
DROP TRIGGER IF EXISTS audit_logs_append_only_truncate ON audit_logs;
DROP TRIGGER IF EXISTS leave_usage_membership_validate ON leave_usage_reconciliation_memberships;
DROP TRIGGER IF EXISTS leave_usage_membership_no_update_delete ON leave_usage_reconciliation_memberships;
DROP TRIGGER IF EXISTS leave_usage_membership_no_truncate ON leave_usage_reconciliation_memberships;
DROP TRIGGER IF EXISTS leave_usage_record_no_delete ON leave_usage_records;
DROP TRIGGER IF EXISTS leave_usage_record_no_truncate ON leave_usage_records;
DROP TRIGGER IF EXISTS leave_usage_reconciliation_no_delete ON leave_usage_reconciliation_sets;
DROP TRIGGER IF EXISTS leave_usage_reconciliation_no_truncate ON leave_usage_reconciliation_sets;
SQL);

        DB::table('leave_usage_reconciliation_memberships')->delete();
        DB::table('leave_usage_records')->delete();
        DB::table('leave_usage_reconciliation_sets')->delete();
        DB::table('leave_balance_ledger')->delete();
        DB::table('audit_logs')->delete();
    }

    public function test_concurrent_create_diserialisasi_dan_hanya_satu_set_aktif_terbentuk(): void
    {
        RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $pns = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);
        $this->createEligibleAppointment($employee);
        $actor = User::factory()->adminKepegawaian()->create();
        $directory = storage_path('framework/testing/leave-recalculation-'.Str::uuid());
        $this->raceDirectory = $directory;
        File::ensureDirectoryExists($directory);
        $barrier = $directory.'/go';
        $processes = [];
        $results = [];

        foreach ([1, 2] as $index => $usage) {
            $ready = "{$directory}/ready-{$index}";
            $result = "{$directory}/result-{$index}.json";
            $results[] = $result;
            $processes[] = new Process([
                PHP_BINARY,
                base_path('tests/Fixtures/LeaveBalanceRecalculationRaceWorker.php'),
                base64_encode(json_encode([
                    'employee_id' => $employee->id,
                    'actor_id' => $actor->id,
                    'usage' => (string) $usage,
                    'ready' => $ready,
                    'barrier' => $barrier,
                    'result' => $result,
                ], JSON_THROW_ON_ERROR)),
            ], base_path(), timeout: 60);
            $processes[$index]->start();
        }

        foreach ([0, 1] as $index) {
            $this->assertTrue($this->waitFor("{$directory}/ready-{$index}", 30_000));
        }

        File::put($barrier, 'go');

        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }

        $outcomes = collect($results)->map(
            fn (string $path): array => json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR),
        );

        $diagnostic = $outcomes->toJson();
        $this->assertSame(1, $outcomes->where('ok', true)->count(), $diagnostic);
        $this->assertSame(1, $outcomes->where('ok', false)->count(), $diagnostic);
        $loser = $outcomes->firstWhere('ok', false);
        $this->assertSame(ValidationException::class, $loser['class'] ?? null, $diagnostic);
        $this->assertSame(
            ['Pegawai sudah memiliki catatan pemakaian aktif untuk tahun saldo yang sama atau lebih baru.'],
            $loser['errors']['balance_year'] ?? null,
            $diagnostic,
        );
        $this->assertSame(1, LeaveUsageReconciliationSet::query()->where('status', 'active')->count());
        $active = LeaveUsageReconciliationSet::query()->where('status', 'active')->sole();
        $this->assertSame(3, LeaveUsageRecord::query()->where('reconciliation_set_id', $active->id)->count());
    }

    public function test_concurrent_replace_set_yang_sama_hanya_menerima_satu_pemenang(): void
    {
        RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $pns = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);
        $this->createEligibleAppointment($employee);
        $actor = User::factory()->adminKepegawaian()->create();
        $current = app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            2026,
            [2024 => 0, 2025 => 0, 2026 => 0],
            Carbon::parse('2026-08-18'),
            'Snapshot awal sebelum race replacement.',
            $actor,
        );
        $directory = storage_path('framework/testing/leave-recalculation-replace-'.Str::uuid());
        $this->raceDirectory = $directory;
        File::ensureDirectoryExists($directory);
        $barrier = $directory.'/go';
        $processes = [];
        $results = [];

        foreach ([3, 5] as $index => $usage) {
            $ready = "{$directory}/ready-{$index}";
            $result = "{$directory}/result-{$index}.json";
            $results[] = $result;
            $processes[] = new Process([
                PHP_BINARY,
                base_path('tests/Fixtures/LeaveBalanceRecalculationRaceWorker.php'),
                base64_encode(json_encode([
                    'mode' => 'replace',
                    'current_set_id' => $current->id,
                    'employee_id' => $employee->id,
                    'actor_id' => $actor->id,
                    'usage' => (string) $usage,
                    'ready' => $ready,
                    'barrier' => $barrier,
                    'result' => $result,
                ], JSON_THROW_ON_ERROR)),
            ], base_path(), timeout: 60);
            $processes[$index]->start();
        }

        foreach ([0, 1] as $index) {
            $this->assertTrue($this->waitFor("{$directory}/ready-{$index}", 30_000));
        }

        File::put($barrier, 'go');

        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }

        $outcomes = collect($results)->map(
            fn (string $path): array => json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR),
        );
        $diagnostic = $outcomes->toJson();
        $this->assertSame(1, $outcomes->where('ok', true)->count(), $diagnostic);
        $this->assertSame(1, $outcomes->where('ok', false)->count(), $diagnostic);
        $winner = $outcomes->firstWhere('ok', true);
        $loser = $outcomes->firstWhere('ok', false);
        $this->assertSame(ValidationException::class, $loser['class'] ?? null, $diagnostic);
        $this->assertSame(
            ['Hanya data pemakaian aktif yang dapat diganti.'],
            $loser['errors']['reconciliation_set'] ?? null,
            $diagnostic,
        );

        $active = LeaveUsageReconciliationSet::query()->where('status', 'active')->sole();
        $winnerUsage = (int) $winner['usage'];
        $this->assertSame($winner['set_id'], $active->id);
        $this->assertSame($current->id, $active->replaces_id);
        $this->assertSame(1, LeaveUsageReconciliationSet::query()->where('replaces_id', $current->id)->count());
        $this->assertSame(
            [0, 0, $winnerUsage],
            $active->records()->where('record_status', 'active')->orderBy('usage_year')->pluck('workdays')->all(),
        );
        $this->assertSame(3, $active->records()->where('record_status', 'active')->count());

        $balance = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2026)
            ->sole();
        $this->assertSame($winnerUsage, $balance->terpakai);
        $this->assertSame(24 - $winnerUsage, $balance->sisa);
    }

    private function waitFor(string $path, int $timeoutMilliseconds): bool
    {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);

        do {
            clearstatcache(true, $path);

            if (File::exists($path)) {
                return true;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return File::exists($path);
    }

    private function createEligibleAppointment(Employee $employee): void
    {
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
        ]);
    }
}
