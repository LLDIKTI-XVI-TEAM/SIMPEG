<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\StorageRecoveryTask;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\EmployeeFileStorageService;
use App\Services\StorageRecoveryService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
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
use Tests\TestCase;

/** Regresi PostgreSQL agar lampiran pengajuan selalu terlacak sebelum byte privat ditulis. */
#[Group('serial')]
class LeaveAttachmentCrashSafetyTest extends TestCase
{
    use DatabaseMigrations;

    private const OBSERVER_CONNECTION = 'pgsql_leave_attachment_observer';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Crash safety lampiran pengajuan wajib diverifikasi pada PostgreSQL.');
        }

        config([
            'database.connections.'.self::OBSERVER_CONNECTION => DB::connection()->getConfig(),
        ]);
        DB::purge(self::OBSERVER_CONNECTION);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs;
DROP TRIGGER IF EXISTS audit_logs_append_only_truncate ON audit_logs;
SQL);
            DB::table('audit_logs')->delete();
        }
        DB::disconnect(self::OBSERVER_CONNECTION);

        parent::tearDown();
    }

    public function test_creation_intent_terlihat_committed_sebelum_byte_lampiran_ditulis(): void
    {
        $employee = Employee::factory()->create();
        $realDisk = Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $observerSawCommittedIntent = false;
        $probedDisk = Mockery::mock(FilesystemAdapter::class);
        $this->expectation($probedDisk, 'putFileAs')->once()->andReturnUsing(
            function (string $directory, UploadedFile $file, string $storedName) use (
                $employee,
                $realDisk,
                &$observerSawCommittedIntent,
            ): string|false {
                $path = $directory.'/'.$storedName;
                $realPath = $file->getRealPath();
                if (! is_string($realPath)) {
                    throw new LogicException('Fixture lampiran tidak memiliki path lokal yang valid.');
                }
                $sha256 = hash_file('sha256', $realPath);
                if (! is_string($sha256)) {
                    throw new LogicException('Fixture lampiran tidak dapat dihitung SHA-256-nya.');
                }

                $observerSawCommittedIntent = DB::connection(self::OBSERVER_CONNECTION)
                    ->table('storage_recovery_tasks')
                    ->where('operation', StorageRecoveryTask::OPERATION_CREATION_TARGET)
                    ->where('status', StorageRecoveryTask::STATUS_PREPARED)
                    ->where('category', StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT)
                    ->where('disk', LeaveRequest::ATTACHMENT_STORAGE_DISK)
                    ->where('path', $path)
                    ->where('owner_id', $employee->id)
                    ->where('sha256', $sha256)
                    ->exists();

                return $realDisk->putFileAs($directory, $file, $storedName);
            },
        );
        Storage::shouldReceive('disk')
            ->with(LeaveRequest::ATTACHMENT_STORAGE_DISK)
            ->andReturn($probedDisk);

        app(EmployeeFileStorageService::class)->storeLampiran($this->document('intent-before-write.pdf'), $employee->id);

        $this->assertTrue(
            $observerSawCommittedIntent,
            'Manifest cleanup harus committed pada koneksi lain sebelum lampiran ditulis.',
        );
    }

    public function test_submit_menahan_lease_intent_sejak_sebelum_write_sampai_metadata_commit(): void
    {
        $this->seed(RbacSeeder::class);
        $actor = $this->leaveApplicant();
        $leaveType = $this->leaveType('Cuti Sakit Lease Submit');
        $realDisk = Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $leaseHeldAfterGrace = false;
        $probedDisk = Mockery::mock(FilesystemAdapter::class);
        $this->expectation($probedDisk, 'putFileAs')->once()->andReturnUsing(
            function (string $directory, UploadedFile $file, string $storedName) use (
                $realDisk,
                &$leaseHeldAfterGrace,
            ): string|false {
                $path = $directory.'/'.$storedName;
                $this->assertFalse($realDisk->exists($path));
                $leaseHeldAfterGrace = $this->creationIntentLeaseHeldAfterGrace($path);

                return $realDisk->putFileAs($directory, $file, $storedName);
            },
        );
        Storage::shouldReceive('disk')
            ->with(LeaveRequest::ATTACHMENT_STORAGE_DISK)
            ->andReturn($probedDisk, $realDisk);

        $response = $this->actingAs($actor['user'])->post(route('cuti.store'), $this->leavePayload(
            $leaveType,
            $this->document('submit-lease.pdf'),
        ));

        $response->assertRedirect(route('cuti'));
        $this->assertTrue(
            $leaseHeldAfterGrace,
            'Submit harus mengunci intent sebelum write agar recovery lewat grace tidak mengambil target prematur.',
        );
    }

    public function test_resubmit_menahan_lease_intent_sejak_sebelum_write_sampai_metadata_commit(): void
    {
        $this->seed(RbacSeeder::class);
        $actor = $this->leaveApplicant();
        $leaveType = $this->leaveType('Cuti Sakit Lease Resubmit');
        $this->actingAs($actor['user'])->post(route('cuti.store'), $this->leavePayload(
            $leaveType,
            $this->document('resubmit-old.pdf'),
        ));
        $leaveRequest = LeaveRequest::query()->firstOrFail();

        $realDisk = Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $leaseHeldAfterGrace = false;
        $probedDisk = Mockery::mock(FilesystemAdapter::class);
        $this->expectation($probedDisk, 'putFileAs')->once()->andReturnUsing(
            function (string $directory, UploadedFile $file, string $storedName) use (
                $realDisk,
                &$leaseHeldAfterGrace,
            ): string|false {
                $path = $directory.'/'.$storedName;
                $this->assertFalse($realDisk->exists($path));
                $leaseHeldAfterGrace = $this->creationIntentLeaseHeldAfterGrace($path);

                return $realDisk->putFileAs($directory, $file, $storedName);
            },
        );
        Storage::shouldReceive('disk')
            ->with(LeaveRequest::ATTACHMENT_STORAGE_DISK)
            ->andReturn($probedDisk, $realDisk);

        $response = $this->patch(route('cuti.resubmit', $leaveRequest), [
            'revision_version' => $leaveRequest->fresh()->revision_version,
            'tanggal_mulai' => '2026-09-14',
            'tanggal_selesai' => '2026-09-16',
            'alasan' => 'Lampiran sudah diperbaiki.',
            'alamat_selama_cuti' => 'Jl. Crash Safety',
            'nomor_telepon' => '+62 431 100',
            'lampiran' => $this->document('resubmit-new.pdf'),
        ]);

        $response->assertRedirect(route('cuti.show', $leaveRequest));
        $this->assertTrue(
            $leaseHeldAfterGrace,
            'Resubmit harus mengunci intent sebelum write agar recovery lewat grace tidak mengambil target prematur.',
        );
    }

    public function test_submit_dalam_outer_transaction_tetap_membuat_intent_committed_dan_memegang_lease(): void
    {
        $this->seed(RbacSeeder::class);
        $actor = $this->leaveApplicant();
        $leaveType = $this->leaveType('Cuti Sakit Lease Outer Transaction');
        $realDisk = Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $leaseHeldAfterGrace = false;
        $probedDisk = Mockery::mock(FilesystemAdapter::class);
        $this->expectation($probedDisk, 'putFileAs')->once()->andReturnUsing(
            function (string $directory, UploadedFile $file, string $storedName) use (
                $realDisk,
                &$leaseHeldAfterGrace,
            ): string|false {
                $path = $directory.'/'.$storedName;
                $this->assertFalse($realDisk->exists($path));
                $leaseHeldAfterGrace = $this->creationIntentLeaseHeldAfterGrace($path);

                return $realDisk->putFileAs($directory, $file, $storedName);
            },
        );
        Storage::shouldReceive('disk')
            ->with(LeaveRequest::ATTACHMENT_STORAGE_DISK)
            ->andReturn($probedDisk, $realDisk);

        $response = DB::transaction(
            fn () => $this->actingAs($actor['user'])->post(route('cuti.store'), $this->leavePayload(
                $leaveType,
                $this->document('submit-outer-transaction.pdf'),
            )),
        );

        $response->assertRedirect(route('cuti'));
        $this->assertTrue(
            $leaseHeldAfterGrace,
            'Savepoint Action tetap harus memakai manifest independen dan lease transaksi terluar.',
        );
    }

    public function test_recovery_menghapus_lampiran_yatim_setelah_process_berhenti_seusai_write(): void
    {
        $employee = Employee::factory()->create();
        $stored = app(EmployeeFileStorageService::class)
            ->storeLampiran($this->document('orphan-after-write.pdf'), $employee->id);
        $intent = StorageRecoveryTask::query()->findOrFail($stored['recovery_task_id']);

        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->assertExists($stored['path']);
        $this->assertDatabaseMissing('leave_requests', ['lampiran_path' => $stored['path']]);

        $this->advancePastCreationGrace($intent);
        $this->assertTrue(app(StorageRecoveryService::class)->attempt($intent->id));

        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->assertMissing($stored['path']);
        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $intent->fresh()->status);
    }

    public function test_recovery_mengadopsi_lampiran_yang_referensinya_sudah_commit(): void
    {
        $employee = Employee::factory()->create();
        $stored = app(EmployeeFileStorageService::class)
            ->storeLampiran($this->document('metadata-committed.pdf'), $employee->id);
        $intent = StorageRecoveryTask::query()->findOrFail($stored['recovery_task_id']);
        $this->leaveRequest($employee, $stored['path']);

        $this->advancePastCreationGrace($intent);
        $this->assertTrue(app(StorageRecoveryService::class)->attempt($intent->id));

        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->assertExists($stored['path']);
        $this->assertSame(StorageRecoveryTask::STATUS_ADOPTED, $intent->fresh()->status);
    }

    public function test_recovery_memindahkan_lampiran_dengan_sha_berubah_ke_manual_review(): void
    {
        $employee = Employee::factory()->create();
        $stored = app(EmployeeFileStorageService::class)
            ->storeLampiran($this->document('tampered-sha.pdf'), $employee->id);
        $intent = StorageRecoveryTask::query()->findOrFail($stored['recovery_task_id']);
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put($stored['path'], 'byte yang berubah');

        $this->advancePastCreationGrace($intent);
        $this->assertFalse(app(StorageRecoveryService::class)->attempt($intent->id));

        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->assertExists($stored['path']);
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $intent->fresh()->status);
    }

    public function test_recovery_memindahkan_path_lampiran_tidak_kanonis_ke_manual_review(): void
    {
        $employee = Employee::factory()->create();
        $path = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$employee->id.'/bukan-uuid.pdf';
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put($path, 'byte tidak kanonis');
        $intent = StorageRecoveryTask::query()->create([
            'idempotency_key' => hash('sha256', $path),
            'operation' => StorageRecoveryTask::OPERATION_CREATION_TARGET,
            'status' => StorageRecoveryTask::STATUS_PREPARED,
            'category' => StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
            'disk' => LeaveRequest::ATTACHMENT_STORAGE_DISK,
            'path' => $path,
            'owner_id' => $employee->id,
            'source_disk' => null,
            'source_path' => null,
            'sha256' => hash('sha256', 'byte tidak kanonis'),
            'attempts' => 0,
        ]);

        $this->advancePastCreationGrace($intent);
        $this->assertFalse(app(StorageRecoveryService::class)->attempt($intent->id));

        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->assertExists($path);
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $intent->fresh()->status);
    }

    private function leaveRequest(Employee $employee, string $path): LeaveRequest
    {
        $leaveType = RefJenisCuti::query()->create([
            'code' => 'crash_'.Str::lower(Str::random(8)),
            'nama' => 'Cuti Sakit Crash Safety',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);

        return LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => '2026-08-27',
            'tanggal_selesai' => '2026-08-27',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Membuktikan recovery setelah metadata commit.',
            'alamat_selama_cuti' => 'Alamat privat pengujian.',
            'nomor_telepon' => '080000000000',
            'lampiran_path' => $path,
            'status' => 'menunggu_approval',
        ]);
    }

    /** @return array{user:User,employee:Employee,supervisor:Employee} */
    private function leaveApplicant(): array
    {
        $employeeType = RefJenisPegawai::query()->firstOrCreate(['nama' => 'PNS']);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $employeeType->id]);
        $supervisor = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-LEASE-001',
            'tanggal_sk' => '2020-01-01',
        ]);
        SupervisorAssignment::query()->create([
            'employee_id' => $employee->id,
            'supervisor_id' => $supervisor->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);
        $user = User::factory()->state(['role' => 'pegawai'])->create(['employee_id' => $employee->id]);
        $chain = LeaveApprovalChain::query()->create([
            'employee_id' => $employee->id,
            'name' => 'Chain crash safety lampiran',
            'effective_from' => '2026-01-01',
            'change_reason' => 'Fixture lease creation-target.',
        ]);
        $chain->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $supervisor->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $pybmc->id,
                'is_final' => true,
            ],
        ]);

        return ['user' => $user, 'employee' => $employee, 'supervisor' => $supervisor];
    }

    private function leaveType(string $name): RefJenisCuti
    {
        return RefJenisCuti::query()->create([
            'nama' => $name,
            'code' => Str::slug($name, '_'),
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
    }

    /** @return array<string, mixed> */
    private function leavePayload(RefJenisCuti $leaveType, UploadedFile $document): array
    {
        return [
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => '2026-09-07',
            'tanggal_selesai' => '2026-09-09',
            'alasan' => 'Menguji lease intent lampiran.',
            'alamat_selama_cuti' => 'Jl. Crash Safety',
            'nomor_telepon' => '+62 431 100',
            'lampiran' => $document,
        ];
    }

    private function creationIntentLeaseHeldAfterGrace(string $path): bool
    {
        $intent = DB::connection(self::OBSERVER_CONNECTION)
            ->table('storage_recovery_tasks')
            ->where('operation', StorageRecoveryTask::OPERATION_CREATION_TARGET)
            ->where('status', StorageRecoveryTask::STATUS_PREPARED)
            ->where('category', StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT)
            ->where('path', $path)
            ->first();
        if ($intent === null) {
            return false;
        }
        Carbon::setTestNow(Carbon::parse((string) $intent->created_at)->addMinutes(2));

        try {
            DB::connection(self::OBSERVER_CONNECTION)->transaction(
                fn (): ?object => DB::connection(self::OBSERVER_CONNECTION)
                    ->table('storage_recovery_tasks')
                    ->where('id', $intent->id)
                    ->lock('FOR UPDATE NOWAIT')
                    ->first(),
            );
        } catch (QueryException $exception) {
            return $exception->getCode() === '55P03';
        }

        return false;
    }

    private function document(string $name): UploadedFile
    {
        return UploadedFile::fake()
            ->createWithContent($name, "%PDF-1.4\n% {$name}\n%%EOF\n")
            ->mimeType('application/pdf');
    }

    private function advancePastCreationGrace(StorageRecoveryTask $intent): void
    {
        $createdAt = $intent->fresh()->created_at;
        if (! $createdAt instanceof Carbon) {
            throw new LogicException('Timestamp creation intent tidak dicast sebagai Carbon.');
        }

        Carbon::setTestNow(Carbon::createFromTimestamp(
            $createdAt->getTimestamp() + 120,
            (string) config('app.timezone'),
        ));
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
