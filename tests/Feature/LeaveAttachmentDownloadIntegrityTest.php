<?php

namespace Tests\Feature;

use App\Actions\Cuti\DownloadLeaveAttachmentAction;
use App\Models\Document;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\StorageRecoveryTask;
use App\Models\User;
use App\Services\EmployeeFileStorageService;
use App\Services\StorageRecoveryService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/** Regresi integritas unduhan untuk lampiran pengajuan cuti pada storage privat. */
#[Group('serial')]
class LeaveAttachmentDownloadIntegrityTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Integritas unduhan lampiran cuti wajib diverifikasi pada PostgreSQL.');
        }

        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake('public');
    }

    public function test_unduhan_menerima_creation_target_adopted_dengan_hash_yang_cocok(): void
    {
        [$leave, $actor] = $this->leaveFixture();
        $this->storeAdoptedCreationAttachment($leave);

        $response = app(DownloadLeaveAttachmentAction::class)->forOwnerOrReadAll($leave->fresh(), $actor);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_unduhan_menerima_migration_target_adopted_dengan_hash_yang_cocok(): void
    {
        [$leave, $actor] = $this->leaveFixture();
        $contents = "%PDF-1.4\n% lampiran hasil migrasi\n%%EOF\n";
        $targetPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$leave->employee_id.'/'.Str::uuid().'.pdf';
        $sourcePath = 'cuti/lampiran-migrasi.pdf';
        $recovery = app(StorageRecoveryService::class);
        $task = $recovery->prepareMigrationTarget(
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $targetPath,
            $leave->employee_id,
            'public',
            $sourcePath,
            hash('sha256', $contents),
        );
        Storage::disk('public')->put($sourcePath, $contents);
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put($targetPath, $contents);
        $leave->forceFill(['lampiran_path' => $targetPath])->save();
        $recovery->markMigrationTargetAdopted($task);

        $response = app(DownloadLeaveAttachmentAction::class)->forOwnerOrReadAll($leave->fresh(), $actor);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_unduhan_menolak_attachment_adopted_yang_hash_nya_berubah_dan_meminta_review_manual(): void
    {
        [$leave, $actor] = $this->leaveFixture();
        $task = $this->storeAdoptedCreationAttachment($leave);
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put(
            (string) $leave->lampiran_path,
            "%PDF-1.4\n% isi tidak lagi cocok dengan manifest\n%%EOF\n",
        );

        $this->assertDownloadNotFound($leave->fresh(), $actor);
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $task->fresh()->status);
    }

    public function test_unduhan_menolak_attachment_adopted_yang_hilang_dan_meminta_review_manual(): void
    {
        [$leave, $actor] = $this->leaveFixture();
        $task = $this->storeAdoptedCreationAttachment($leave);
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->delete((string) $leave->lampiran_path);

        $this->assertDownloadNotFound($leave->fresh(), $actor);
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $task->fresh()->status);
    }

    public function test_unduhan_menolak_attachment_yang_manifest_nya_masih_prepared(): void
    {
        [$leave, $actor] = $this->leaveFixture();
        $stored = app(EmployeeFileStorageService::class)->storeLampiran(
            $this->document('lampiran-prepared.pdf'),
            $leave->employee_id,
        );
        $leave->forceFill(['lampiran_path' => $stored['path']])->save();

        $this->assertDownloadNotFound($leave->fresh(), $actor);
        $this->assertSame(
            StorageRecoveryTask::STATUS_PREPARED,
            StorageRecoveryTask::query()->findOrFail($stored['recovery_task_id'])->status,
        );
    }

    public function test_unduhan_menolak_attachment_kanonis_tanpa_manifest(): void
    {
        [$leave, $actor] = $this->leaveFixture();
        $path = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$leave->employee_id.'/'.Str::uuid().'.pdf';
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put($path, "%PDF-1.4\n%%EOF\n");
        $leave->forceFill(['lampiran_path' => $path])->save();

        $this->assertDownloadNotFound($leave->fresh(), $actor);
        $this->assertDatabaseMissing('storage_recovery_tasks', [
            'category' => StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
            'path' => $path,
        ]);
    }

    public function test_command_mengadopsi_lampiran_kanonis_private_yang_direferensikan_tanpa_manifest(): void
    {
        [$leave, $actor] = $this->leaveFixture();
        $contents = "%PDF-1.4\n% lampiran canonical private upgrade\n%%EOF\n";
        $path = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$leave->employee_id.'/'.Str::uuid().'.pdf';
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put($path, $contents);
        $leave->forceFill(['lampiran_path' => $path])->save();

        $this->assertDownloadNotFound($leave->fresh(), $actor);
        $this->artisan('documents:migrate-to-private-storage')
            ->expectsOutputToContain('siap=1')
            ->assertSuccessful();
        $this->assertDatabaseMissing('storage_recovery_tasks', [
            'category' => StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
            'path' => $path,
        ]);
        $this->assertDownloadNotFound($leave->fresh(), $actor);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('storage_recovery_tasks', [
            'operation' => StorageRecoveryTask::OPERATION_CREATION_TARGET,
            'status' => StorageRecoveryTask::STATUS_ADOPTED,
            'category' => StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
            'disk' => LeaveRequest::ATTACHMENT_STORAGE_DISK,
            'path' => $path,
            'owner_id' => $leave->employee_id,
            'source_disk' => null,
            'source_path' => null,
            'sha256' => hash('sha256', $contents),
        ]);
        $this->assertSame(
            200,
            app(DownloadLeaveAttachmentAction::class)
                ->forOwnerOrReadAll($leave->fresh(), $actor)
                ->getStatusCode(),
        );

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->assertSuccessful();
        $this->assertSame(1, StorageRecoveryTask::query()
            ->where('category', StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT)
            ->where('disk', LeaveRequest::ATTACHMENT_STORAGE_DISK)
            ->where('path', $path)
            ->count());
    }

    public function test_command_melanjutkan_manifest_prepared_setelah_crash_sebelum_adopsi(): void
    {
        [$leave, $actor] = $this->leaveFixture();
        $contents = "%PDF-1.4\n% lampiran canonical private crash retry\n%%EOF\n";
        $path = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$leave->employee_id.'/'.Str::uuid().'.pdf';
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put($path, $contents);
        $leave->forceFill(['lampiran_path' => $path])->save();
        $intent = app(StorageRecoveryService::class)->prepareLeaveAttachmentCreationTarget(
            $path,
            $leave->employee_id,
            hash('sha256', $contents),
        );
        $this->assertSame(StorageRecoveryTask::STATUS_PREPARED, $intent->status);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->assertSuccessful();

        $this->assertSame(StorageRecoveryTask::STATUS_ADOPTED, $intent->fresh()->status);
        $this->assertSame(
            200,
            app(DownloadLeaveAttachmentAction::class)
                ->forOwnerOrReadAll($leave->fresh(), $actor)
                ->getStatusCode(),
        );
    }

    public function test_command_melanjutkan_migration_target_prepared_setelah_target_selesai_ditulis(): void
    {
        [$leave, $actor] = $this->leaveFixture();
        $contents = "%PDF-1.4\n% lampiran migration target crash retry\n%%EOF\n";
        $path = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$leave->employee_id.'/'.Str::uuid().'.pdf';
        Storage::disk('public')->put($path, $contents);
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put($path, $contents);
        $leave->forceFill(['lampiran_path' => $path])->save();
        $intent = app(StorageRecoveryService::class)->prepareMigrationTarget(
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $path,
            $leave->employee_id,
            'public',
            $path,
            hash('sha256', $contents),
        );

        $this->assertSame(StorageRecoveryTask::STATUS_PREPARED, $intent->status);
        $this->assertDownloadNotFound($leave->fresh(), $actor);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->assertSuccessful();

        $this->assertSame(StorageRecoveryTask::STATUS_ADOPTED, $intent->fresh()->status);
        Storage::disk('public')->assertMissing($path);
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->assertExists($path);
        $this->assertSame(
            200,
            app(DownloadLeaveAttachmentAction::class)
                ->forOwnerOrReadAll($leave->fresh(), $actor)
                ->getStatusCode(),
        );

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->assertSuccessful();
        $this->assertSame(1, StorageRecoveryTask::query()
            ->where('category', StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT)
            ->where('disk', LeaveRequest::ATTACHMENT_STORAGE_DISK)
            ->where('path', $path)
            ->count());
    }

    public function test_command_tidak_mengadopsi_file_kanonis_yang_tidak_direferensikan(): void
    {
        [$leave] = $this->leaveFixture();
        $contents = "%PDF-1.4\n% file canonical tanpa referensi\n%%EOF\n";
        $path = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$leave->employee_id.'/'.Str::uuid().'.pdf';
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put($path, $contents);

        try {
            app(StorageRecoveryService::class)->adoptReferencedCanonicalLeaveAttachment(
                $path,
                $leave->employee_id,
                hash('sha256', $contents),
            );
            $this->fail('Recovery API tidak boleh mengadopsi path kanonis tanpa referensi DB yang cocok.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('tidak direferensikan', $exception->getMessage());
        }

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('storage_recovery_tasks', [
            'category' => StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
            'path' => $path,
        ]);
    }

    /** @return array{LeaveRequest, User} */
    private function leaveFixture(): array
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $leaveType = RefJenisCuti::query()->create([
            'code' => 'integritas_'.Str::lower(Str::random(8)),
            'nama' => 'Cuti Integritas Lampiran',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leave = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => '2026-08-27',
            'tanggal_selesai' => '2026-08-27',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Verifikasi integritas lampiran privat.',
            'status' => 'menunggu_approval',
        ]);

        return [$leave, $actor];
    }

    private function storeAdoptedCreationAttachment(LeaveRequest $leave): StorageRecoveryTask
    {
        $files = app(EmployeeFileStorageService::class);
        $stored = $files->storeLampiran($this->document('lampiran-adopted.pdf'), $leave->employee_id);
        $leave->forceFill(['lampiran_path' => $stored['path']])->save();
        $this->assertTrue($files->adoptLeaveAttachment(
            $stored['recovery_task_id'],
            $leave->employee_id,
            $stored['path'],
        ));

        return StorageRecoveryTask::query()->findOrFail($stored['recovery_task_id']);
    }

    private function document(string $name): UploadedFile
    {
        return UploadedFile::fake()
            ->createWithContent($name, "%PDF-1.4\n% {$name}\n%%EOF\n")
            ->mimeType('application/pdf');
    }

    private function assertDownloadNotFound(LeaveRequest $leave, User $actor): void
    {
        try {
            app(DownloadLeaveAttachmentAction::class)->forOwnerOrReadAll($leave, $actor);
            $this->fail('Unduhan lampiran tanpa integritas final wajib ditolak.');
        } catch (NotFoundHttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }
}
