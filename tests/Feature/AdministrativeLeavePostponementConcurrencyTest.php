<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\Cuti\LeaveUsageRecordService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\RecordsHistoricalAnnualLeaveUsage;
use Tests\TestCase;

#[Group('serial')]
class AdministrativeLeavePostponementConcurrencyTest extends TestCase
{
    use DatabaseMigrations;
    use RecordsHistoricalAnnualLeaveUsage;

    protected function setUp(): void
    {
        // Pemeriksaan sebelum hook migration menjaga schema development dari fixture committed.
        if (($_SERVER['DB_CONNECTION'] ?? getenv('DB_CONNECTION')) !== 'pgsql'
            || ($_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE')) !== 'simpeg_test'
            || ($_SERVER['APP_ENV'] ?? getenv('APP_ENV')) !== 'testing') {
            $this->markTestSkipped('Race penangguhan wajib memakai PostgreSQL simpeg_test terisolasi.');
        }
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-06 09:00:00', 'Asia/Makassar'));
        $this->seedReferenceData();
        $this->seedRbac();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->app !== null) {
            // Histori worker committed sengaja tidak dibatalkan lewat down domain; bersihkan hanya schema disposable.
            $this->assertTrue(app()->environment('testing'));
            $this->assertSame('pgsql', DB::getDriverName());
            $this->assertSame('simpeg_test', DB::connection()->getDatabaseName());
            $this->artisan('db:wipe', ['--force' => true])->assertExitCode(0);
            $this->artisan('migrate:install')->assertExitCode(0);
        }
        parent::tearDown();
    }

    /** Cache konfigurasi tidak boleh mengalahkan opt-in environment sebelum migration benar-benar dijalankan. */
    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame('simpeg_test', DB::connection()->getDatabaseName());
    }

    public function test_dua_penangguhan_paralel_hanya_satu_keputusan_refund_dan_notifikasi(): void
    {
        [$leave, $admin] = $this->fixture();
        $outcomes = $this->runContended($leave, $admin, ['postpone', 'postpone'], 'request');
        $this->assertSame(1, count(array_filter($outcomes, fn (array $result): bool => $result['ok'])), json_encode($outcomes));
        $loser = array_values(array_filter($outcomes, fn (array $result): bool => ! $result['ok']))[0];
        $this->assertSame(ValidationException::class, $loser['class']);
        $this->assertArrayHasKey('status', $loser['errors']);
        $this->assertSingleDecision($leave);
    }

    public function test_penangguhan_dan_rekalkulasi_bersamaan_mengikuti_mutex_pegawai(): void
    {
        [$leave, $admin] = $this->fixture();
        $outcomes = $this->runContended($leave, $admin, ['postpone', 'recalculate'], 'employee');
        $this->assertSame(2, count(array_filter($outcomes, fn (array $result): bool => $result['ok'])), json_encode($outcomes));
        $this->assertSingleDecision($leave);
    }

    /**
     * Kontensi benar-benar diamati pada server, bukan sekadar memanggil dua Action berurutan.
     *
     * @param  list<string>  $operations
     * @return list<array<string, mixed>>
     */
    private function runContended(LeaveRequest $leave, User $actor, array $operations, string $lock): array
    {
        $processes = [];
        DB::beginTransaction();
        try {
            if ($lock === 'request') {
                LeaveRequest::query()->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            } else {
                Employee::query()->whereKey($leave->employee_id)->lockForUpdate()->firstOrFail();
            }
            foreach ($operations as $operation) {
                $process = new Process([
                    PHP_BINARY, base_path('tests/Fixtures/AdministrativeLeavePostponementRaceWorker.php'),
                    base64_encode(json_encode(['leave_request_id' => $leave->id, 'actor_id' => $actor->id, 'operation' => $operation], JSON_THROW_ON_ERROR)),
                ], base_path(), timeout: 120);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $this->waitUntilBlocked($process);
            }
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $lines = explode("\n", trim($process->getOutput()));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }
    }

    /** Worker harus mencapai lock database dalam batas waktu, dengan diagnostik jika bootstrap gagal. */
    private function waitUntilBlocked(Process $process): void
    {
        $deadline = microtime(true) + 60;
        do {
            $lines = explode("\n", trim($process->getOutput()));
            $ready = json_decode($lines[0], true);
            if (is_array($ready) && isset($ready['pid'])) {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = DB::selectOne("SELECT COUNT(*) AS count FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock'", [$ready['pid']]);
                if ((int) $blocked->count === 1) {
                    return;
                }
            }
            if (! $process->isRunning()) {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        $this->fail('Worker tidak mencapai kontensi: '.$process->getOutput().' '.$process->getErrorOutput());
    }

    private function assertSingleDecision(LeaveRequest $leave): void
    {
        $this->assertSame('ditangguhkan_administratif', $leave->fresh()->status);
        $this->assertSame('cancelled', $leave->usageRecord->fresh()->record_status);
        $this->assertSame(1, AuditLog::query()->where('auditable_id', $leave->id)->where('new_values->operation', 'administrative_postponement')->count());
        $this->assertSame(1, LeaveBalanceLedger::query()->where('leave_request_id', $leave->id)->where('event_type', 'usage_fact_cancelled')->count());
        $this->assertSame(1, SimpegNotification::query()->where('type', 'cuti.ditangguhkan_administratif')->where('user_id', $leave->employee_id)->count());
        $this->assertSame(24, LeaveBalance::query()->where('employee_id', $leave->employee_id)->where('tahun', 2026)->sole()->sisa);
    }

    /** @return array{LeaveRequest, User} */
    private function fixture(): array
    {
        $employee = Employee::factory()->create(['jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', 'PNS')->sole()->id]);
        Appointment::create(['employee_id' => $employee->id, 'jenis_pengangkatan' => 'PNS', 'tmt_pengangkatan' => '2020-01-01']);
        $admin = User::factory()->adminKepegawaian()->create();
        $this->recordHistoricalAnnualUsage($employee, [2024 => 0, 2025 => 0, 2026 => 0], $admin, 'Saldo awal race.');
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id, 'jenis_cuti_id' => RefJenisCuti::query()->where('code', 'tahunan')->sole()->id,
            'tanggal_mulai' => '2026-09-14', 'tanggal_selesai' => '2026-09-15', 'jumlah_hari_kerja' => 2,
            'alasan' => 'Cuti future untuk kontensi.', 'status' => 'disetujui',
        ]);
        foreach (['kepala_bagian', 'pybmc'] as $index => $type) {
            $leave->steps()->create([
                'step_order' => $index + 1, 'step_type' => $type, 'role_label' => $type,
                'approver_employee_id' => Employee::factory()->create()->id, 'status' => 'approved',
                'is_final' => $type === 'pybmc', 'acted_at' => now(),
            ]);
        }
        app(LeaveUsageRecordService::class)->recordApprovedRequest($leave, $admin);

        return [$leave, $admin];
    }
}
