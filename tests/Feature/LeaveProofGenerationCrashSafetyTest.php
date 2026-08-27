<?php

namespace Tests\Feature;

use App\Actions\Cuti\GenerateLeaveProofAction;
use App\Models\Employee;
use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\StorageRecoveryService;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Mockery;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/** Regresi PostgreSQL untuk memastikan file bukti privat selalu memiliki manifest sebelum ditulis. */
#[Group('serial')]
class LeaveProofGenerationCrashSafetyTest extends TestCase
{
    use DatabaseMigrations;

    private const OBSERVER_CONNECTION = 'pgsql_leave_proof_generation_observer';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Crash safety bukti cuti wajib diverifikasi pada PostgreSQL.');
        }

        config([
            'database.connections.'.self::OBSERVER_CONNECTION => DB::connection()->getConfig(),
        ]);
        DB::purge(self::OBSERVER_CONNECTION);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        DB::disconnect(self::OBSERVER_CONNECTION);

        parent::tearDown();
    }

    public function test_intent_cleanup_terlihat_committed_sebelum_pdf_bukti_ditulis(): void
    {
        [$leaveRequest, $generator] = $this->approvedLeaveProofFixture();
        $realDisk = Storage::disk('local');
        $observerSawCommittedIntent = false;
        $probedDisk = Mockery::mock(FilesystemAdapter::class);
        $this->expectation($probedDisk, 'put')->once()->andReturnUsing(
            function (string $path, string $contents) use (
                $leaveRequest,
                $realDisk,
                &$observerSawCommittedIntent,
            ): bool {
                $observerSawCommittedIntent = DB::connection(self::OBSERVER_CONNECTION)
                    ->table('storage_recovery_tasks')
                    ->where('operation', 'creation_target')
                    ->where('status', 'prepared')
                    ->where('category', 'leave_proof')
                    ->where('disk', 'local')
                    ->where('path', $path)
                    ->where('owner_id', $leaveRequest->id)
                    ->where('sha256', hash('sha256', $contents))
                    ->exists();

                return $realDisk->put($path, $contents);
            },
        );
        Storage::shouldReceive('disk')->with('local')->andReturn($probedDisk);

        DB::transaction(
            fn (): array => DB::transaction(
                fn (): array => app(GenerateLeaveProofAction::class)->execute($leaveRequest->fresh(), $generator),
            ),
        );

        $this->assertTrue(
            $observerSawCommittedIntent,
            'Manifest cleanup harus committed pada koneksi lain sebelum byte PDF ditulis.',
        );
    }

    public function test_transaksi_pembuat_memegang_lease_intent_sebelum_pdf_ditulis(): void
    {
        [$leaveRequest, $generator] = $this->approvedLeaveProofFixture();
        $realDisk = Storage::disk('local');
        $intentLeaseHeld = false;
        $probedDisk = Mockery::mock(FilesystemAdapter::class);
        $this->expectation($probedDisk, 'put')->once()->andReturnUsing(
            function (string $path, string $contents) use ($realDisk, &$intentLeaseHeld): bool {
                try {
                    DB::connection(self::OBSERVER_CONNECTION)->transaction(
                        fn (): ?object => DB::connection(self::OBSERVER_CONNECTION)
                            ->table('storage_recovery_tasks')
                            ->where('path', $path)
                            ->lock('FOR UPDATE NOWAIT')
                            ->first(),
                    );
                } catch (QueryException $exception) {
                    $intentLeaseHeld = $exception->getCode() === '55P03';
                }

                return $realDisk->put($path, $contents);
            },
        );
        Storage::shouldReceive('disk')->with('local')->andReturn($probedDisk);

        DB::transaction(
            fn (): array => DB::transaction(
                fn (): array => app(GenerateLeaveProofAction::class)->execute($leaveRequest->fresh(), $generator),
            ),
        );

        $this->assertTrue(
            $intentLeaseHeld,
            'Transaksi pembuat harus menahan row lock intent sampai metadata proof commit atau rollback.',
        );
    }

    public function test_retry_menghapus_pdf_yatim_setelah_transaksi_pembuat_rollback(): void
    {
        [$leaveRequest, $generator] = $this->approvedLeaveProofFixture();

        try {
            DB::transaction(function () use ($leaveRequest, $generator): never {
                DB::transaction(
                    fn (): array => app(GenerateLeaveProofAction::class)->execute($leaveRequest->fresh(), $generator),
                );

                throw new RuntimeException('Simulasi process termination setelah PDF ditulis.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulasi process termination setelah PDF ditulis.', $exception->getMessage());
        }

        $intent = DB::connection(self::OBSERVER_CONNECTION)
            ->table('storage_recovery_tasks')
            ->where('operation', 'creation_target')
            ->where('owner_id', $leaveRequest->id)
            ->sole();
        Storage::disk('local')->assertExists($intent->path);
        $this->assertNull($leaveRequest->proof()->sole()->document_path);

        Carbon::setTestNow(Carbon::parse((string) $intent->created_at)->addMinutes(2));
        try {
            $attempted = app(StorageRecoveryService::class)->attempt($intent->id);
            $diagnostic = DB::table('storage_recovery_tasks')
                ->where('id', $intent->id)
                ->first(['status', 'attempts', 'last_error']);
            $this->assertTrue($attempted, json_encode($diagnostic, JSON_THROW_ON_ERROR));
        } finally {
            Carbon::setTestNow();
        }

        Storage::disk('local')->assertMissing($intent->path);
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'id' => $intent->id,
            'operation' => 'creation_target',
            'status' => 'completed',
            'attempts' => 1,
        ]);
    }

    public function test_retry_mengadopsi_pdf_yang_sudah_direferensikan_setelah_commit(): void
    {
        [$leaveRequest, $generator] = $this->approvedLeaveProofFixture();

        DB::transaction(
            fn (): array => DB::transaction(
                fn (): array => app(GenerateLeaveProofAction::class)->execute($leaveRequest->fresh(), $generator),
            ),
        );

        $proof = $leaveRequest->proof()->sole();
        $this->assertNotNull($proof->document_path);
        Storage::disk('local')->assertExists($proof->document_path);
        $intent = DB::connection(self::OBSERVER_CONNECTION)
            ->table('storage_recovery_tasks')
            ->where('operation', 'creation_target')
            ->where('path', $proof->document_path)
            ->sole();

        Carbon::setTestNow(Carbon::parse((string) $intent->created_at)->addMinutes(2));
        try {
            $this->assertTrue(app(StorageRecoveryService::class)->attempt($intent->id));
        } finally {
            Carbon::setTestNow();
        }

        Storage::disk('local')->assertExists($proof->document_path);
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'id' => $intent->id,
            'operation' => 'creation_target',
            'status' => 'adopted',
        ]);
    }

    public function test_generator_normal_mengadopsi_intent_setelah_metadata_commit(): void
    {
        [$leaveRequest, $generator] = $this->approvedLeaveProofFixture();

        app(GenerateLeaveProofAction::class)->execute($leaveRequest, $generator);

        $proof = $leaveRequest->proof()->sole();
        $this->assertNotNull($proof->document_path);
        Storage::disk('local')->assertExists($proof->document_path);
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'operation' => 'creation_target',
            'status' => 'adopted',
            'category' => 'leave_proof',
            'path' => $proof->document_path,
            'owner_id' => $leaveRequest->id,
        ]);
    }

    public function test_command_recovery_menunggu_grace_lalu_menghapus_creation_target_yatim(): void
    {
        $leaveRequestId = (string) Str::uuid();
        $path = 'leave-proofs/'.$leaveRequestId.'/'.Str::uuid().'.pdf';
        $contents = "%PDF-1.4\n% orphan command recovery\n%%EOF\n";
        $intent = app(StorageRecoveryService::class)->prepareLeaveProofCreationTarget(
            $path,
            $leaveRequestId,
            hash('sha256', $contents),
        );
        Storage::disk('local')->put($path, $contents);

        $this->artisan('storage:retry-recovery')->assertSuccessful();
        Storage::disk('local')->assertExists($path);
        $this->assertSame('prepared', $intent->fresh()->status);
        $this->assertSame(0, $intent->fresh()->attempts);

        Carbon::setTestNow($intent->created_at->copy()->addMinutes(2));
        try {
            $this->artisan('storage:retry-recovery')->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        Storage::disk('local')->assertMissing($path);
        $this->assertSame('completed', $intent->fresh()->status);
    }

    /** @return array{LeaveRequest, User} */
    private function approvedLeaveProofFixture(): array
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pemohon Crash Safety']);
        $approver = Employee::factory()->create(['nama_lengkap' => 'Penerbit Crash Safety']);
        $generator = User::factory()->pimpinan()->create(['employee_id' => $approver->id]);
        $leaveType = RefJenisCuti::query()->create([
            'code' => 'sakit_crash_safety_'.Str::lower(Str::random(8)),
            'nama' => 'Cuti Sakit Crash Safety',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leaveRequest = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => '2026-08-20',
            'tanggal_selesai' => '2026-08-20',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Verifikasi crash safety dokumen bukti.',
            'alamat_selama_cuti' => 'Alamat pengujian privat.',
            'nomor_telepon' => '080000000000',
            'status' => 'disetujui',
        ]);
        LeaveRequestStep::query()->create([
            'leave_request_id' => $leaveRequest->id,
            'step_order' => 1,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $approver->id,
            'status' => 'approved',
            'is_final' => true,
            'acted_at' => now(),
        ]);
        LeaveProof::query()->create([
            'leave_request_id' => $leaveRequest->id,
            'token' => Str::random(64),
            'generated_by' => $generator->id,
            'generated_at' => now(),
            'metadata' => [],
        ]);

        return [$leaveRequest->fresh(), $generator];
    }

    /** Mengubah hasil shouldReceive menjadi ekspektasi konkret yang dapat dikonfigurasi. */
    private function expectation(MockInterface $mock, string $method): Expectation|CompositeExpectation
    {
        $expectation = $mock->shouldReceive($method);

        if (! $expectation instanceof Expectation && ! $expectation instanceof CompositeExpectation) {
            throw new LogicException('Mockery tidak mengembalikan ekspektasi metode.');
        }

        return $expectation;
    }
}
