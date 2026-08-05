<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Rule5PostgresConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private ?string $raceDirectory = null;

    protected function setUp(): void
    {
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

    /** @return array{employee: Employee, finalApprover: Employee, large: LeaveRequest} */
    private function createRaceFixture(): array
    {
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', 'PNS')->value('id'),
        ]);
        $firstApprover = Employee::factory()->create();
        $finalApprover = Employee::factory()->create();
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2018-01-01',
            'no_sk' => 'SK-RACE-RULE-5',
            'tanggal_sk' => '2018-01-01',
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
        $large = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::query()->where('code', 'besar')->firstOrFail()->id,
            'tanggal_mulai' => '2026-08-03',
            'tanggal_selesai' => '2026-08-31',
            'jumlah_hari_kerja' => 20,
            'alasan' => 'Race final Cuti Besar.',
            'status' => 'menunggu_approval',
        ]);
        $large->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $firstApprover->id,
                'status' => 'approved',
                'is_final' => false,
                'acted_at' => now(),
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $finalApprover->id,
                'status' => 'active',
                'is_final' => true,
            ],
        ]);

        return compact('employee', 'finalApprover', 'large');
    }

    public function test_final_cuti_besar_dan_reservasi_tahunan_diserialisasi_pada_employee_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Race Rule 5 wajib dijalankan pada PostgreSQL.');
        }

        try {
            $fixture = $this->createRaceFixture();
            $directory = storage_path('framework/testing/rule5-'.Str::uuid());
            $this->raceDirectory = $directory;
            File::ensureDirectoryExists($directory);
            $barrier = $directory.'/go';

            $approveResult = $directory.'/approve.json';
            $reserveResult = $directory.'/reserve.json';
            $worker = base_path('tests/Fixtures/Rule5RaceWorker.php');
            $approve = new Process([PHP_BINARY, $worker, base64_encode(json_encode([
                'mode' => 'approve_large',
                'barrier' => $barrier,
                'result' => $approveResult,
                'request_id' => $fixture['large']->id,
                'approver_id' => $fixture['finalApprover']->id,
            ], JSON_THROW_ON_ERROR))], base_path(), timeout: 20);
            $reserve = new Process([PHP_BINARY, $worker, base64_encode(json_encode([
                'mode' => 'reserve_annual',
                'barrier' => $barrier,
                'result' => $reserveResult,
                'employee_id' => $fixture['employee']->id,
            ], JSON_THROW_ON_ERROR))], base_path(), timeout: 20);

            $approve->start();
            $reserve->start();
            File::put($barrier, 'go');
            $approve->wait();
            $reserve->wait();

            $results = collect([$approveResult, $reserveResult])
                ->map(fn (string $path): array => json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR));
            $this->assertSame(1, $results->where('ok', true)->count());
            $this->assertSame(1, $results->where('ok', false)->count());

            $largeApproved = $fixture['large']->fresh()->status === 'disetujui';
            $annualCount = LeaveRequest::query()
                ->where('employee_id', $fixture['employee']->id)
                ->whereHas('jenisCuti', fn ($query) => $query->where('code', 'tahunan'))
                ->count();
            $activeReservation = (int) LeaveBalanceReservationEvent::query()
                ->where('employee_id', $fixture['employee']->id)
                ->where('tahun', 2026)
                ->sum('amount');

            $this->assertTrue(
                ($largeApproved && $annualCount === 0 && $activeReservation === 0)
                || (! $largeApproved && $annualCount === 1 && $activeReservation === 3),
            );
            $this->assertSame($largeApproved ? 1 : 0, DB::table('leave_proofs')
                ->where('leave_request_id', $fixture['large']->id)
                ->count());
            $this->assertSame($largeApproved ? 1 : 0, DB::table('leave_approvals')
                ->where('leave_request_id', $fixture['large']->id)
                ->where('stage', 2)
                ->where('action', 'APPROVE')
                ->count());
        } finally {
            // Evidence worker hanya hidup dalam database test; bersihkan sebelum hook migration
            // agar guard rollback produksi tetap melindungi data nyata.
            DB::table('audit_logs')->delete();
            DB::table('leave_balance_reservation_events')->delete();
        }
    }
}
