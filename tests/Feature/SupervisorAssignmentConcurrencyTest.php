<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\SupervisorAssignment;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('serial')]
class SupervisorAssignmentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private ?string $raceDirectory = null;

    protected function setUp(): void
    {
        $driver = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION');

        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Race penugasan Kepala Bagian wajib dijalankan pada PostgreSQL.');
        }

        parent::setUp();
        $this->seed(ReferenceSeeder::class);
    }

    protected function tearDown(): void
    {
        if ($this->raceDirectory !== null) {
            File::deleteDirectory($this->raceDirectory);
        }

        parent::tearDown();
    }

    public function test_assignment_silang_mengunci_target_dan_approver_dalam_urutan_global(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Race penugasan Kepala Bagian wajib dijalankan pada PostgreSQL.');
        }

        try {
            $employeeA = Employee::factory()->create();
            $employeeB = Employee::factory()->create();
            $directory = storage_path('framework/testing/supervisor-assignment-'.Str::uuid());
            $this->raceDirectory = $directory;
            File::ensureDirectoryExists($directory);
            $barrier = $directory.'/go';
            $validatedA = $directory.'/validated-a';
            $validatedB = $directory.'/validated-b';
            $resultA = $directory.'/result-a.json';
            $resultB = $directory.'/result-b.json';
            $worker = base_path('tests/Fixtures/SupervisorAssignmentRaceWorker.php');
            $processA = $this->worker(
                $worker,
                $barrier,
                $validatedA,
                $validatedB,
                $resultA,
                $employeeA->id,
                $employeeB->id,
            );
            $processB = $this->worker(
                $worker,
                $barrier,
                $validatedB,
                $validatedA,
                $resultB,
                $employeeB->id,
                $employeeA->id,
            );

            $processA->start();
            $processB->start();
            File::put($barrier, 'go');
            $processA->wait();
            $processB->wait();

            $outcomeA = json_decode(File::get($resultA), true, flags: JSON_THROW_ON_ERROR);
            $outcomeB = json_decode(File::get($resultB), true, flags: JSON_THROW_ON_ERROR);

            $this->assertTrue($outcomeA['ok'], $outcomeA['message'] ?? 'Worker A gagal.');
            $this->assertTrue($outcomeB['ok'], $outcomeB['message'] ?? 'Worker B gagal.');
            $this->assertDatabaseHas('supervisor_assignments', [
                'employee_id' => $employeeA->id,
                'kepala_bagian_id' => $employeeB->id,
                'tanggal_mulai' => '2099-01-01 00:00:00',
            ]);
            $this->assertDatabaseHas('supervisor_assignments', [
                'employee_id' => $employeeB->id,
                'kepala_bagian_id' => $employeeA->id,
                'tanggal_mulai' => '2099-01-01 00:00:00',
            ]);
            $this->assertNull($employeeA->fresh()->kepala_bagian_id);
            $this->assertNull($employeeB->fresh()->kepala_bagian_id);
            $this->assertSame(2, SupervisorAssignment::query()->count());
        } finally {
            $this->kosongkanAuditSebelumPenurunanMigrasi();
        }
    }

    private function worker(
        string $worker,
        string $barrier,
        string $validated,
        string $peerValidated,
        string $result,
        string $employeeId,
        string $supervisorId,
    ): Process {
        return new Process([PHP_BINARY, $worker, base64_encode(json_encode([
            'barrier' => $barrier,
            'validated' => $validated,
            'peer_validated' => $peerValidated,
            'result' => $result,
            'employee_id' => $employeeId,
            'supervisor_id' => $supervisorId,
        ], JSON_THROW_ON_ERROR))], base_path(), timeout: 30);
    }

    private function kosongkanAuditSebelumPenurunanMigrasi(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('drop trigger if exists audit_logs_append_only on audit_logs;');
            DB::unprepared('drop trigger if exists audit_logs_append_only_truncate on audit_logs;');
        }

        DB::table('audit_logs')->delete();
    }
}
