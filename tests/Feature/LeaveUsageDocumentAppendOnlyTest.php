<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveUsageDocument;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class LeaveUsageDocumentAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgresql_menolak_update_metadata_target_path_dan_timestamp_dokumen(): void
    {
        [$document] = $this->documentFixture();

        foreach ([
            "original_name = 'nama-baru.pdf'",
            "stored_name = 'nama-tersimpan-baru.pdf'",
            "path = 'cuti/pemakaian/path-baru.pdf'",
            "disk = 'public'",
            "mime_type = 'image/png'",
            'size_bytes = 999',
            'leave_usage_record_id = NULL',
            "created_at = created_at + INTERVAL '1 second'",
            "updated_at = updated_at + INTERVAL '1 second'",
            'uploaded_by = NULL',
        ] as $assignment) {
            $this->assertStatementRejected(
                "UPDATE leave_usage_documents SET {$assignment} WHERE id = '{$document->id}'",
                'metadata dokumen pemakaian cuti bersifat append-only',
            );
        }
    }

    public function test_postgresql_menolak_delete_dan_truncate_dokumen(): void
    {
        [$document] = $this->documentFixture();

        foreach ([
            "DELETE FROM leave_usage_documents WHERE id = '{$document->id}'",
            'TRUNCATE TABLE leave_usage_documents',
        ] as $statement) {
            $this->assertStatementRejected(
                $statement,
                'metadata dokumen pemakaian cuti bersifat append-only',
            );
        }
    }

    public function test_model_menolak_update_dan_delete_dengan_error_domain_yang_jelas(): void
    {
        [$document] = $this->documentFixture();

        try {
            $document->update(['original_name' => 'nama-baru.pdf']);
            $this->fail('Model seharusnya menolak perubahan metadata dokumen pemakaian cuti.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Metadata dokumen pemakaian cuti bersifat append-only dan tidak dapat diubah.',
                $exception->getMessage(),
            );
        }

        try {
            $document->delete();
            $this->fail('Model seharusnya menolak penghapusan dokumen pemakaian cuti.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Metadata dokumen pemakaian cuti bersifat append-only dan tidak dapat dihapus.',
                $exception->getMessage(),
            );
        }
    }

    public function test_fk_penghapusan_uploader_hanya_menullkan_uploaded_by_tanpa_mutasi_lain(): void
    {
        [$document, $uploader] = $this->documentFixture();
        $before = (array) DB::table('leave_usage_documents')->where('id', $document->id)->sole();

        $uploader->delete();

        $after = (array) DB::table('leave_usage_documents')->where('id', $document->id)->sole();
        $this->assertNull($after['uploaded_by']);
        unset($before['uploaded_by'], $after['uploaded_by']);
        $this->assertSame($before, $after);
    }

    /**
     * @return array{LeaveUsageDocument, User}
     */
    private function documentFixture(): array
    {
        $employee = Employee::factory()->create();
        $uploader = User::factory()->adminKepegawaian()->create();
        $leaveType = RefJenisCuti::query()->create([
            'nama' => 'Cuti Dokumen Append Only',
            'code' => 'dokumen-append-only',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $record = LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'usage_year' => 2026,
            'effective_date' => '2026-08-20',
            'start_date' => '2026-08-20',
            'end_date' => '2026-08-20',
            'workdays' => 1,
            'administrative_note' => 'Fixture guard dokumen.',
            'recorded_by' => null,
        ]);
        $document = LeaveUsageDocument::query()->create([
            'leave_usage_record_id' => $record->id,
            'original_name' => 'bukti-awal.pdf',
            'stored_name' => '00000000-0000-4000-8000-000000000901.pdf',
            'path' => 'cuti/pemakaian/'.$employee->id.'/00000000-0000-4000-8000-000000000901.pdf',
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
            'uploaded_by' => $uploader->id,
        ]);

        return [$document, $uploader];
    }

    private function assertStatementRejected(string $statement, string $message): void
    {
        try {
            DB::transaction(fn (): bool => DB::statement($statement));
            $this->fail("Statement seharusnya ditolak: {$statement}");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
