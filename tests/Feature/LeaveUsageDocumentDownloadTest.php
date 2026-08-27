<?php

namespace Tests\Feature;

use App\Actions\Cuti\DownloadLeaveUsageDocumentAction;
use App\Models\Employee;
use App\Models\LeaveUsageDocument;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\LeaveUsageRecord;
use App\Models\Permission;
use App\Models\RefJenisCuti;
use App\Models\Role;
use App\Models\StorageRecoveryTask;
use App\Models\User;
use App\Services\StorageRecoveryService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class LeaveUsageDocumentDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Storage::fake(LeaveUsageDocument::STORAGE_DISK);
    }

    public function test_download_guard_admin_dijalankan_sebelum_lookup_dokumen_sensitif(): void
    {
        [$record, $document] = $this->manualDocument();
        $unknownUsage = (string) Str::uuid();
        $unknownDocument = (string) Str::uuid();

        $this->get($this->downloadUrl($record, $document))->assertRedirect(route('login'));

        foreach (['super_admin', 'pimpinan', 'kepala_bagian', 'pegawai'] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $this->actingAs($actor)
                ->get("/cuti/pemakaian-manual/{$unknownUsage}/dokumen/{$unknownDocument}")
                ->assertForbidden();

            try {
                app(DownloadLeaveUsageDocumentAction::class)->execute(
                    $unknownUsage,
                    $unknownDocument,
                    $actor,
                );
                $this->fail("Action download seharusnya menolak {$role} sebelum lookup.");
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        $adminRole = Role::query()->where('name', 'admin_kepegawaian')->firstOrFail();
        $permission = Permission::query()->where('name', 'cuti.manual.manage')->firstOrFail();
        $adminRole->permissions()->detach($permission);
        $adminWithoutPermission = User::factory()->adminKepegawaian()->create();
        $this->actingAs($adminWithoutPermission)
            ->get($this->downloadUrl($record, $document))
            ->assertForbidden();
    }

    public function test_admin_mendapat_download_privat_dengan_header_anti_cache_dan_nosniff(): void
    {
        [$record, $document] = $this->manualDocument('isi-dokumen-rahasia');
        $admin = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($admin)->get($this->downloadUrl($record, $document));

        $response->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('isi-dokumen-rahasia', $response->streamedContent());
    }

    public function test_download_menolak_artifact_adopted_yang_byte_nya_berubah_dan_mengarantina_manifest(): void
    {
        [$record, $document] = $this->manualDocument('byte bukti manual asli');
        $admin = User::factory()->adminKepegawaian()->create();
        $task = StorageRecoveryTask::query()
            ->where('category', StorageRecoveryService::CATEGORY_LEAVE_USAGE_DOCUMENT)
            ->where('owner_id', $record->employee_id)
            ->where('path', $document->path)
            ->sole();

        $this->assertSame(StorageRecoveryTask::STATUS_ADOPTED, $task->status);
        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->put($document->path, 'byte bukti manual telah diubah');

        $this->actingAs($admin)
            ->get($this->downloadUrl($record, $document))
            ->assertNotFound()
            ->assertDontSee('byte bukti manual telah diubah');

        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $task->fresh()->status);
    }

    public function test_admin_malformed_uuid_dan_document_milik_record_lain_mendapat_404(): void
    {
        [$record, $document] = $this->manualDocument();
        [$otherRecord, $otherDocument] = $this->manualDocument();
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($admin)
            ->get('/cuti/pemakaian-manual/bukan-uuid/dokumen/bukan-uuid')
            ->assertNotFound();

        foreach ([
            ['bukan-uuid', $document->id],
            [$record->id, 'bukan-uuid'],
        ] as [$usageId, $documentId]) {
            try {
                app(DownloadLeaveUsageDocumentAction::class)->execute($usageId, $documentId, $admin);
                $this->fail('UUID malformed seharusnya menghasilkan 404 setelah otorisasi.');
            } catch (NotFoundHttpException) {
                $this->assertTrue(true);
            }
        }
        $this->actingAs($admin)
            ->get($this->downloadUrl($record, $otherDocument))
            ->assertNotFound();
        $this->actingAs($admin)
            ->get($this->downloadUrl($otherRecord, $document))
            ->assertNotFound();
    }

    public function test_path_disk_basename_source_dan_file_hilang_ditolak_fail_closed(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        foreach ([
            ['path' => 'dokumen-lain/rahasia.pdf'],
            ['path' => 'cuti/pemakaian/../rahasia.pdf'],
            ['path' => 'cuti/pemakaian-lain/rahasia.pdf'],
            ['disk' => 'public'],
            ['stored_name' => 'nama-lain.pdf'],
        ] as $changes) {
            [$record, $document] = $this->manualDocument(documentOverrides: $changes);

            $this->actingAs($admin)->get($this->downloadUrl($record, $document))->assertNotFound();
        }

        [$missingRecord, $missingDocument] = $this->manualDocument();
        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->delete($missingDocument->path);
        $this->actingAs($admin)->get($this->downloadUrl($missingRecord, $missingDocument))->assertNotFound();

        [$reconciliationRecord, $reconciliationDocument] = $this->reconciliationDocument();
        $this->actingAs($admin)
            ->get($this->downloadUrl($reconciliationRecord, $reconciliationDocument))
            ->assertNotFound();
    }

    public function test_path_nested_dalam_folder_pegawai_yang_sama_tetap_ditolak(): void
    {
        [$record, $document] = $this->manualDocument();
        $admin = User::factory()->adminKepegawaian()->create();
        $nestedPath = LeaveUsageDocument::PATH_PREFIX.'/'.$record->employee_id.'/nested/'.$document->stored_name;
        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->put($nestedPath, 'dokumen nested tidak kanonis');
        $nestedDocument = LeaveUsageDocument::query()->create([
            'leave_usage_record_id' => $record->id,
            'original_name' => $document->original_name,
            'stored_name' => $document->stored_name,
            'path' => $nestedPath,
            'disk' => $document->disk,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'uploaded_by' => $document->uploaded_by,
        ]);

        $this->actingAs($admin)->get($this->downloadUrl($record, $nestedDocument))->assertNotFound();
    }

    public function test_dokumen_fact_superseded_tetap_dapat_diunduh_admin(): void
    {
        [$record, $document] = $this->manualDocument('dokumen-versi-lama');
        $record->forceFill([
            'record_status' => LeaveUsageRecord::STATUS_SUPERSEDED,
            'correction_reason' => 'Digantikan versi koreksi.',
        ])->save();
        $admin = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($admin)->get($this->downloadUrl($record, $document));

        $response->assertOk();
        $this->assertSame('dokumen-versi-lama', $response->streamedContent());
    }

    /**
     * @param  array<string, mixed>  $documentOverrides
     * @return array{LeaveUsageRecord, LeaveUsageDocument}
     */
    private function manualDocument(string $contents = 'dokumen-manual', array $documentOverrides = []): array
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();
        $type = RefJenisCuti::query()->firstOrCreate(
            ['code' => 'sakit'],
            ['nama' => 'Cuti Sakit', 'mengurangi_saldo_tahunan' => false, 'khusus_pns' => false],
        );
        $record = LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'usage_year' => 2026,
            'effective_date' => '2026-01-05',
            'start_date' => '2026-01-05',
            'end_date' => '2026-01-05',
            'workdays' => 1,
            'administrative_note' => 'Fixture dokumen manual.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $actor->id,
        ]);
        $this->attachValidManualApprovalSnapshot($record);
        $storedName = Str::uuid().'.pdf';
        $path = LeaveUsageDocument::PATH_PREFIX.'/'.$employee->id.'/'.$storedName;
        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->put($path, $contents);
        $document = LeaveUsageDocument::query()->create(array_merge([
            'leave_usage_record_id' => $record->id,
            'original_name' => 'bukti-cuti.pdf',
            'stored_name' => $storedName,
            'path' => $path,
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents),
            'uploaded_by' => $actor->id,
        ], $documentOverrides));

        if ($documentOverrides === []) {
            $this->adoptUsageDocumentArtifact($document, $employee->id, $contents);
        }

        return [$record, $document];
    }

    /** Membentuk manifest lewat jalur produksi agar fixture unduh merepresentasikan artifact yang sah. */
    private function adoptUsageDocumentArtifact(
        LeaveUsageDocument $document,
        string $employeeId,
        string $contents,
    ): StorageRecoveryTask {
        $sha256 = hash('sha256', $contents);
        $task = StorageRecoveryTask::query()->create([
            'idempotency_key' => hash('sha256', implode('|', [$employeeId, $document->path, $sha256])),
            'operation' => StorageRecoveryTask::OPERATION_CREATION_TARGET,
            'status' => StorageRecoveryTask::STATUS_PREPARED,
            'category' => StorageRecoveryService::CATEGORY_LEAVE_USAGE_DOCUMENT,
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'path' => $document->path,
            'owner_id' => $employeeId,
            'source_disk' => null,
            'source_path' => null,
            'sha256' => $sha256,
            'attempts' => 0,
            'last_error' => null,
            'last_attempted_at' => null,
            'completed_at' => null,
        ]);

        app(StorageRecoveryService::class)->markLeaveUsageDocumentCreationTargetAdopted(
            $task->id,
            $employeeId,
            $document->path,
        );

        return $task;
    }

    /** @return array{LeaveUsageRecord, LeaveUsageDocument} */
    private function reconciliationDocument(): array
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();
        $type = RefJenisCuti::query()->firstOrCreate(
            ['code' => 'tahunan'],
            ['nama' => 'Cuti Tahunan', 'mengurangi_saldo_tahunan' => true, 'khusus_pns' => false],
        );
        $set = LeaveUsageReconciliationSet::query()->create([
            'employee_id' => $employee->id,
            'balance_year' => 2026,
            'reconciled_at' => '2026-08-18',
            'status' => LeaveUsageReconciliationSet::STATUS_ACTIVE,
            'administrative_note' => 'Fixture rekonsiliasi.',
            'recorded_by' => $actor->id,
        ]);
        $record = LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'source_type' => LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION,
            'reconciliation_set_id' => $set->id,
            'usage_year' => 2026,
            'effective_date' => '2026-08-18',
            'workdays' => 1,
            'administrative_note' => 'Fixture deklarasi rekonsiliasi.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $actor->id,
        ]);
        $storedName = Str::uuid().'.pdf';
        $path = LeaveUsageDocument::PATH_PREFIX.'/'.$employee->id.'/'.$storedName;
        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->put($path, 'rekonsiliasi');
        $document = LeaveUsageDocument::query()->create([
            'leave_usage_record_id' => $record->id,
            'original_name' => 'rekonsiliasi.pdf',
            'stored_name' => $storedName,
            'path' => $path,
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
            'uploaded_by' => $actor->id,
        ]);

        return [$record, $document];
    }

    private function downloadUrl(LeaveUsageRecord $record, LeaveUsageDocument $document): string
    {
        return "/cuti/pemakaian-manual/{$record->id}/dokumen/{$document->id}";
    }
}
