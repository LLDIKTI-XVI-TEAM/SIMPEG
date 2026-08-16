<?php

namespace Tests\Unit\Documents;

use App\Actions\Documents\DeleteDocumentAction;
use App\Models\Document;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeleteDocumentActionTest extends TestCase
{
    use RefreshDatabase;

    private DeleteDocumentAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Document::STORAGE_DISK);
        $this->action = app(DeleteDocumentAction::class);
    }

    public function test_protected_sk_cannot_be_deleted_even_without_history(): void
    {
        $document = $this->createDocument('sk_pangkat', 'sk/pangkat.pdf');

        try {
            $this->action->execute($document);
            $this->fail('Protected SK should not be deletable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document', $exception->errors());
        }

        $this->assertTrue(Document::query()->whereKey($document->id)->exists());
        Storage::disk(Document::STORAGE_DISK)->assertExists('sk/pangkat.pdf');
    }

    public function test_sk_pengangkatan_cannot_be_deleted(): void
    {
        $document = $this->createDocument('sk_pengangkatan', 'sk/angkat.pdf');

        $this->expectException(ValidationException::class);
        $this->action->execute($document);
    }

    public function test_berkas_lainnya_can_be_deleted(): void
    {
        $document = $this->createDocument('lainnya', 'berkas/ktp.pdf');

        $this->action->execute($document);

        $this->assertFalse(Document::query()->whereKey($document->id)->exists());
        Storage::disk(Document::STORAGE_DISK)->assertMissing('berkas/ktp.pdf');
    }

    private function createDocument(string $jenis, string $path): Document
    {
        Storage::disk(Document::STORAGE_DISK)->put($path, 'isi');

        return Document::create([
            'employee_id' => Employee::factory()->create()->id,
            'jenis_dokumen' => $jenis,
            'nama_dokumen' => strtoupper($jenis),
            'file_path' => $path,
        ]);
    }
}
