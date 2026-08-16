<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Employee;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJenisPegawai;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression kontrak storage privat: URL berkas dokumen pegawai wajib memakai
 * route unduh terotorisasi dan tidak boleh mengekspos path publik /storage.
 */
class EmployeeDocumentAuthorizedUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_status_document_urls_use_authorized_history_attachment_route(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $pns = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);
        $rank = RefGolongan::where('kode', 'III/a')->firstOrFail();
        $history = RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $rank->id,
            'tmt_pangkat' => '2026-01-01',
            'no_sk' => 'SK-PANGKAT-URL',
            'tanggal_sk' => '2025-12-20',
            'file_sk' => "ranks/sk/{$employee->id}-url.pdf",
            'is_latest' => true,
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($history->file_sk, 'SK pangkat');

        $response = $this->actingAs($user)
            ->getJson("/api/v1/pegawai/{$employee->id}/status-dokumen")
            ->assertOk();

        $requiredSk = collect($response->json('document_status.required_sks'))
            ->firstWhere('jenis', 'sk_pangkat');

        $this->assertNotNull($requiredSk);
        $this->assertSame(
            route('pegawai.history-attachments.download', [
                'employee' => $employee,
                'type' => 'rank',
                'history' => $history,
            ]),
            $requiredSk['file_url'],
        );
        $this->assertStringNotContainsString('/storage/', $response->getContent());
    }

    public function test_status_document_urls_use_authorized_archive_download_route(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $pns = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);
        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pangkat',
            'nama_dokumen' => 'SK Pangkat Arsip',
            'nomor_dokumen' => 'SK-PANGKAT-ARSIP',
            'file_path' => "{$employee->id}/sk_pangkat/arsip-url.pdf",
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($document->file_path, 'SK pangkat arsip');

        $response = $this->actingAs($user)
            ->getJson("/api/v1/pegawai/{$employee->id}/status-dokumen")
            ->assertOk();

        $requiredSk = collect($response->json('document_status.required_sks'))
            ->firstWhere('jenis', 'sk_pangkat');

        $this->assertNotNull($requiredSk);
        $this->assertSame(route('dokumen.download', $document), $requiredSk['file_url']);
        $this->assertSame(
            $document->file_path,
            $response->json('document_status.documents.0.file_path'),
        );
        $this->assertStringNotContainsString('/storage/', $response->getContent());
    }

    public function test_document_upload_never_lands_on_public_disk(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake('public');
        $user = User::factory()->adminKepegawaian()->create();
        $pns = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);

        $this->actingAs($user)
            ->postJson("/api/v1/pegawai/{$employee->id}/dokumen", [
                'nama_dokumen' => 'SK Pangkat Privat',
                'kategori_dokumen' => 'sk_pangkat',
                'pegawai_id' => $employee->id,
                'berkas' => UploadedFile::fake()->create('sk-pangkat.pdf', 10, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $document = Document::query()
            ->where('employee_id', $employee->id)
            ->where('jenis_dokumen', 'sk_pangkat')
            ->firstOrFail();

        Storage::disk(Document::STORAGE_DISK)->assertExists($document->file_path);
        Storage::disk('public')->assertMissing($document->file_path);
    }
}
