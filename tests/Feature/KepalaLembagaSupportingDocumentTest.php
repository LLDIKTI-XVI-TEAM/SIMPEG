<?php

namespace Tests\Feature;

use App\Actions\Cuti\DeleteKepalaLembagaSupportingDocumentAction;
use App\Actions\Cuti\ListKepalaLembagaSupportingDocumentsAction;
use App\Actions\Cuti\PrepareKepalaLembagaSupportingDocumentResponseAction;
use App\Actions\Cuti\PurgeKepalaLembagaSupportingDocumentFilesAction;
use App\Actions\Cuti\StoreKepalaLembagaSupportingDocumentAction;
use App\Http\Requests\Cuti\StoreKepalaLembagaSupportingDocumentRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\KepalaLembagaSupportingDocument;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery\Expectation;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class KepalaLembagaSupportingDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_model_persists_and_relates_to_employee_and_uploader(): void
    {
        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $uploader = User::factory()->adminKepegawaian()->create();

        $document = KepalaLembagaSupportingDocument::query()->create([
            'employee_id' => $employee->id,
            'uploaded_by' => $uploader->id,
            'stored_path' => "cuti/kepala-lembaga-support/{$employee->id}/document.pdf",
            'original_filename' => 'persetujuan-kementerian.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);

        $this->assertNotNull($document->id);
        $this->assertIsInt($document->fresh()->size_bytes);
        $this->assertTrue($document->employee->is($employee));
        $this->assertTrue($document->uploader->is($uploader));
        $this->assertFalse($document->trashed());
    }

    public function test_admin_can_upload_pdf_for_kepala_lembaga_with_uuid_path_and_audit(): void
    {

        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $user = User::factory()->adminKepegawaian()->create();

        $action = app(StoreKepalaLembagaSupportingDocumentAction::class);
        $document = $action->execute(
            $employee,
            UploadedFile::fake()->create('surat pendukung.pdf', 200, 'application/pdf'),
            $user,
        );

        $this->assertStringStartsWith('cuti/kepala-lembaga-support/'.$employee->id.'/', $document->stored_path);
        $this->assertStringEndsWith('.pdf', $document->stored_path);
        $this->assertStringNotContainsString('surat pendukung', $document->stored_path);
        $this->assertTrue(Storage::disk('local')->exists($document->stored_path));

        $audit = AuditLog::query()
            ->where('event', 'CREATE')
            ->where('auditable_type', 'KepalaLembagaSupportingDocument')
            ->where('auditable_id', $document->id)
            ->firstOrFail();

        $this->assertArrayNotHasKey('stored_path', $audit->new_values);
        $this->assertSame($employee->id, $audit->new_values['employee_id']);
        $this->assertSame($user->id, $audit->new_values['uploaded_by']);
    }

    public function test_upload_rejected_for_non_kepala_lembaga_employee(): void
    {

        $employee = Employee::factory()->create(['is_kepala_lembaga' => false]);
        $user = User::factory()->adminKepegawaian()->create();

        $this->expectException(ValidationException::class);
        app(StoreKepalaLembagaSupportingDocumentAction::class)
            ->execute($employee, UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'), $user);
    }

    public function test_upload_orphan_file_cleaned_when_audit_write_fails(): void
    {

        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $user = User::factory()->adminKepegawaian()->create();

        Schema::drop('audit_logs');

        try {
            app(StoreKepalaLembagaSupportingDocumentAction::class)
                ->execute($employee, UploadedFile::fake()->create('gagal.pdf', 30, 'application/pdf'), $user);
            $this->fail('Unggah harus melempar ketika penulisan audit gagal.');
        } catch (\Throwable) {
            // Kegagalan audit harus diteruskan setelah transaksi dibatalkan dan file orphan dibersihkan.
        }

        $this->assertSame(0, KepalaLembagaSupportingDocument::withTrashed()->count());
        $files = Storage::disk('local')->allFiles(KepalaLembagaSupportingDocument::PATH_PREFIX);
        $this->assertCount(0, $files, 'File orphan harus dibersihkan saat penyimpanan DB/audit gagal.');
    }

    public function test_upload_disallowed_mime_is_rejected_by_request_rules(): void
    {
        $rules = (new StoreKepalaLembagaSupportingDocumentRequest)->rules();

        $this->assertArrayHasKey('berkas', $rules);
        $this->assertContains('file', $rules['berkas']);
        $this->assertContains('mimes:pdf,doc,docx,jpg,jpeg,png', $rules['berkas']);
        $this->assertContains('max:10240', $rules['berkas']);
    }

    public function test_list_returns_only_active_documents_for_kepala_lembaga(): void
    {
        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);

        $active = KepalaLembagaSupportingDocument::query()->create([
            'employee_id' => $employee->id,
            'stored_path' => KepalaLembagaSupportingDocument::PATH_PREFIX.'/'.$employee->id.'/'.Str::uuid().'.pdf',
            'original_filename' => 'aktif.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);
        $tombstoned = KepalaLembagaSupportingDocument::query()->create([
            'employee_id' => $employee->id,
            'stored_path' => KepalaLembagaSupportingDocument::PATH_PREFIX.'/'.$employee->id.'/'.Str::uuid().'.pdf',
            'original_filename' => 'terhapus.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);
        $tombstoned->delete();

        $list = app(ListKepalaLembagaSupportingDocumentsAction::class)->execute($employee);

        $documentIds = collect($list->items())->pluck('id');
        $this->assertTrue($documentIds->contains($active->id));
        $this->assertFalse($documentIds->contains($tombstoned->id));
    }

    public function test_pdf_response_is_inline_and_missing_file_is_not_found(): void
    {
        Storage::fake(KepalaLembagaSupportingDocument::STORAGE_DISK);

        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $user = User::factory()->adminKepegawaian()->create();

        $document = app(StoreKepalaLembagaSupportingDocumentAction::class)
            ->execute($employee, UploadedFile::fake()->create('a.pdf', 20, 'application/pdf'), $user);

        $inline = app(PrepareKepalaLembagaSupportingDocumentResponseAction::class)
            ->execute($document, disposition: 'inline');
        $this->assertStringContainsString('inline', strtolower($inline->headers->get('Content-Disposition') ?? ''));

        Storage::disk('local')->delete($document->stored_path);
        $this->expectException(NotFoundHttpException::class);
        app(PrepareKepalaLembagaSupportingDocumentResponseAction::class)
            ->execute($document, disposition: 'inline');
    }

    public function test_response_aborts_404_when_employee_marker_flipped_off(): void
    {

        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $user = User::factory()->adminKepegawaian()->create();

        $document = app(StoreKepalaLembagaSupportingDocumentAction::class)
            ->execute($employee, UploadedFile::fake()->create('a.pdf', 20, 'application/pdf'), $user);
        $employee->update(['is_kepala_lembaga' => false]);
        $document->refresh();

        $this->expectException(NotFoundHttpException::class);
        app(PrepareKepalaLembagaSupportingDocumentResponseAction::class)
            ->execute($document, disposition: 'inline');
    }

    public function test_docx_response_forces_attachment_disposition(): void
    {

        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $user = User::factory()->adminKepegawaian()->create();

        $document = app(StoreKepalaLembagaSupportingDocumentAction::class)
            ->execute(
                $employee,
                UploadedFile::fake()->create('surat.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
                $user,
            );

        $response = app(PrepareKepalaLembagaSupportingDocumentResponseAction::class)
            ->execute($document, disposition: 'inline');

        $this->assertStringContainsString('attachment', strtolower($response->headers->get('Content-Disposition') ?? ''));
    }

    public function test_delete_soft_deletes_commits_audit_then_removes_file(): void
    {

        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $user = User::factory()->adminKepegawaian()->create();
        $document = app(StoreKepalaLembagaSupportingDocumentAction::class)
            ->execute($employee, UploadedFile::fake()->create('a.pdf', 20, 'application/pdf'), $user);
        $path = $document->stored_path;

        app(DeleteKepalaLembagaSupportingDocumentAction::class)->execute($document, $user);

        $this->assertSoftDeleted('kepala_lembaga_supporting_documents', ['id' => $document->id]);
        $tombstone = KepalaLembagaSupportingDocument::withTrashed()->findOrFail($document->id);
        $this->assertSame($path, $tombstone->stored_path);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'SOFT_DELETE',
            'auditable_type' => 'KepalaLembagaSupportingDocument',
            'auditable_id' => $document->id,
        ]);
        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_delete_rolls_back_soft_delete_and_keeps_file_when_audit_fails(): void
    {

        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $user = User::factory()->adminKepegawaian()->create();
        $document = app(StoreKepalaLembagaSupportingDocumentAction::class)
            ->execute($employee, UploadedFile::fake()->create('a.pdf', 20, 'application/pdf'), $user);
        $path = $document->stored_path;

        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::creating(function (): void {
            throw new \RuntimeException('Simulasi kegagalan audit.');
        });

        try {
            app(DeleteKepalaLembagaSupportingDocumentAction::class)->execute($document, $user);
            $this->fail('Penghapusan harus melempar ketika penulisan audit gagal.');
        } catch (\Throwable) {
            // Audit gagal harus membatalkan tombstone sebelum penghapusan fisik dimulai.
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertDatabaseHas('kepala_lembaga_supporting_documents', [
            'id' => $document->id,
            'deleted_at' => null,
        ]);
        $this->assertTrue(Storage::disk('local')->exists($path));
    }

    public function test_physical_delete_failure_logs_warning_and_keeps_committed_tombstone(): void
    {

        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $user = User::factory()->adminKepegawaian()->create();
        $document = app(StoreKepalaLembagaSupportingDocumentAction::class)
            ->execute($employee, UploadedFile::fake()->create('a.pdf', 20, 'application/pdf'), $user);
        $path = $document->stored_path;

        Log::shouldReceive('warning')->once()
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'Gagal menghapus file fisik')
                && ($context['document_id'] ?? null) === $document->id);

        $diskMock = \Mockery::mock(Filesystem::class);
        /** @var Expectation $existsExpectation */
        $existsExpectation = $diskMock->shouldReceive('exists');
        $existsExpectation->with($path)->andReturnTrue();
        /** @var Expectation $deleteExpectation */
        $deleteExpectation = $diskMock->shouldReceive('delete');
        $deleteExpectation->with($path)->andThrow(new \RuntimeException('storage down'));
        Storage::shouldReceive('disk')
            ->with(KepalaLembagaSupportingDocument::STORAGE_DISK)
            ->andReturn($diskMock);

        app(DeleteKepalaLembagaSupportingDocumentAction::class)->execute($document, $user);

        $this->assertSoftDeleted('kepala_lembaga_supporting_documents', ['id' => $document->id]);
        $tombstone = KepalaLembagaSupportingDocument::withTrashed()->findOrFail($document->id);
        $this->assertSame($path, $tombstone->stored_path);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'SOFT_DELETE',
            'auditable_id' => $document->id,
        ]);
    }

    public function test_authorized_admin_can_upload_via_http_and_unauthorized_denied(): void
    {

        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $admin = User::factory()->adminKepegawaian()->create();
        $pegawai = User::factory()->pegawai()->create();

        $this->actingAs($pegawai)
            ->withSession(['_token' => 'test-token'])
            ->post(route('cuti.dokumen-kepala-lembaga.store', $employee), [
                '_token' => 'test-token',
                'berkas' => UploadedFile::fake()->create('a.pdf', 50, 'application/pdf'),
            ], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->from(route('cuti.dokumen-kepala-lembaga.index'))
            ->withSession(['_token' => 'test-token'])
            ->post(route('cuti.dokumen-kepala-lembaga.store', $employee), [
                '_token' => 'test-token',
                'berkas' => UploadedFile::fake()->create('a.pdf', 50, 'application/pdf'),
            ], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertRedirect(route('cuti.dokumen-kepala-lembaga.index', ['employee' => $employee->id]));

        $this->assertDatabaseHas('kepala_lembaga_supporting_documents', ['employee_id' => $employee->id]);
    }

    public function test_disallowed_mime_rejected_via_http(): void
    {
        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($admin)
            ->from(route('cuti.dokumen-kepala-lembaga.index'))
            ->withSession(['_token' => 'test-token'])
            ->post(route('cuti.dokumen-kepala-lembaga.store', $employee), [
                '_token' => 'test-token',
                'berkas' => UploadedFile::fake()->create('script.sh', 5, 'text/x-shellscript'),
            ], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertSessionHasErrors('berkas');

        $this->assertDatabaseMissing('kepala_lembaga_supporting_documents', ['employee_id' => $employee->id]);
    }

    public function test_upload_rejects_file_over_10mb_boundary(): void
    {

        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($admin)
            ->from(route('cuti.dokumen-kepala-lembaga.index'))
            ->withSession(['_token' => 'test-token'])
            ->post(route('cuti.dokumen-kepala-lembaga.store', $employee), [
                '_token' => 'test-token',
                'berkas' => UploadedFile::fake()->create('besar.pdf', 10241, 'application/pdf'),
            ], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertSessionHasErrors('berkas');

        $this->assertDatabaseMissing('kepala_lembaga_supporting_documents', ['employee_id' => $employee->id]);
    }

    public function test_upload_accepts_file_at_10mb_boundary(): void
    {

        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($admin)
            ->from(route('cuti.dokumen-kepala-lembaga.index'))
            ->withSession(['_token' => 'test-token'])
            ->post(route('cuti.dokumen-kepala-lembaga.store', $employee), [
                '_token' => 'test-token',
                'berkas' => UploadedFile::fake()->create('pas.pdf', 10240, 'application/pdf'),
            ], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertRedirect(route('cuti.dokumen-kepala-lembaga.index', ['employee' => $employee->id]));

        $this->assertDatabaseHas('kepala_lembaga_supporting_documents', ['employee_id' => $employee->id]);
    }

    public function test_document_routes_reject_malformed_uuid(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $this->actingAs($admin);

        $this->get('/cuti/dokumen-kepala-lembaga/not-a-uuid/view')->assertNotFound();
        $this->get('/cuti/dokumen-kepala-lembaga/not-a-uuid/download')->assertNotFound();
    }

    public function test_store_denies_non_kepala_lembaga_target(): void
    {

        $notMarked = Employee::factory()->create(['is_kepala_lembaga' => false]);
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($admin)
            ->from(route('cuti.dokumen-kepala-lembaga.index'))
            ->withSession(['_token' => 'test-token'])
            ->post(route('cuti.dokumen-kepala-lembaga.store', $notMarked), [
                '_token' => 'test-token',
                'berkas' => UploadedFile::fake()->create('a.pdf', 50, 'application/pdf'),
            ], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertNotFound();

        $this->assertDatabaseMissing('kepala_lembaga_supporting_documents', ['employee_id' => $notMarked->id]);
    }

    public function test_index_selects_validated_employee_uuid_among_marked(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $first = Employee::factory()->create(['is_kepala_lembaga' => true, 'nama_lengkap' => 'Aaa Kepala']);
        $second = Employee::factory()->create(['is_kepala_lembaga' => true, 'nama_lengkap' => 'Zzz Kepala']);

        $this->actingAs($admin)
            ->get(route('cuti.dokumen-kepala-lembaga.index', ['employee' => $second->id]))
            ->assertOk()
            ->assertViewHas('selected', fn ($selected): bool => $selected !== null && $selected->id === $second->id);

        $unmarked = Employee::factory()->create(['is_kepala_lembaga' => false]);
        $this->actingAs($admin)
            ->get(route('cuti.dokumen-kepala-lembaga.index', ['employee' => $unmarked->id]))
            ->assertOk()
            ->assertViewHas('selected', fn ($selected): bool => $selected !== null && $selected->id === $first->id);
    }

    public function test_index_lists_uploaded_document_for_selected_kepala_lembaga(): void
    {

        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $admin = User::factory()->adminKepegawaian()->create();

        app(StoreKepalaLembagaSupportingDocumentAction::class)->execute(
            $employee,
            UploadedFile::fake()->create('persetujuan-kementerian.pdf', 200, 'application/pdf'),
            $admin,
        );

        $this->actingAs($admin)
            ->get(route('cuti.dokumen-kepala-lembaga.index', ['employee' => $employee->id]))
            ->assertOk()
            ->assertSee('persetujuan-kementerian.pdf')
            ->assertViewHas('documents', fn ($documents): bool => $documents->total() === 1);
    }

    public function test_retry_purges_physical_files_for_tombstoned_documents(): void
    {

        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $user = User::factory()->adminKepegawaian()->create();
        $document = app(StoreKepalaLembagaSupportingDocumentAction::class)
            ->execute($employee, UploadedFile::fake()->create('a.pdf', 20, 'application/pdf'), $user);
        $path = $document->stored_path;
        $document->delete();
        $this->assertTrue(Storage::disk('local')->exists($path));

        $auditCountBefore = AuditLog::query()->count();
        $purged = app(PurgeKepalaLembagaSupportingDocumentFilesAction::class)->execute();

        $this->assertSame(1, $purged);
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertSame($auditCountBefore, AuditLog::query()->count(), 'Retry tidak boleh menulis audit kedua.');
        $this->assertSame(0, app(PurgeKepalaLembagaSupportingDocumentFilesAction::class)->execute());
    }

    public function test_retry_logs_and_continues_when_a_file_delete_throws(): void
    {

        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $user = User::factory()->adminKepegawaian()->create();
        $document = app(StoreKepalaLembagaSupportingDocumentAction::class)
            ->execute($employee, UploadedFile::fake()->create('a.pdf', 20, 'application/pdf'), $user);
        $path = $document->stored_path;
        $document->delete();

        Log::shouldReceive('warning')->once()
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'Retry hapus file'));

        $diskMock = \Mockery::mock(Filesystem::class);
        /** @var Expectation $existsExpectation */
        $existsExpectation = $diskMock->shouldReceive('exists');
        $existsExpectation->with($path)->andReturnTrue();
        /** @var Expectation $deleteExpectation */
        $deleteExpectation = $diskMock->shouldReceive('delete');
        $deleteExpectation->with($path)->andThrow(new \RuntimeException('still down'));
        Storage::shouldReceive('disk')
            ->with(KepalaLembagaSupportingDocument::STORAGE_DISK)
            ->andReturn($diskMock);

        $purged = app(PurgeKepalaLembagaSupportingDocumentFilesAction::class)->execute();

        $this->assertSame(0, $purged);
        $this->assertSame(1, KepalaLembagaSupportingDocument::onlyTrashed()->count());
    }

    public function test_artisan_command_runs_retry(): void
    {

        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $user = User::factory()->adminKepegawaian()->create();
        $document = app(StoreKepalaLembagaSupportingDocumentAction::class)
            ->execute($employee, UploadedFile::fake()->create('a.pdf', 20, 'application/pdf'), $user);
        $document->delete();

        $this->artisan('cuti:purge-dokumen-kepala-lembaga')->assertSuccessful();
        $this->assertFalse(Storage::disk('local')->exists($document->stored_path));
    }
}
