<?php

namespace App\Actions\Histories;

use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\Employee;
use App\Services\AuditService;
use App\Support\Histories\DisciplineRecordPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteDisciplineRecordAction
{
    public function __construct(private readonly DisciplineRecordPayload $payload) {}

    /**
     * Menghapus riwayat hukuman disiplin beserta dokumen arsip otomatis dan file fisiknya.
     *
     * Dua skenario file_sk:
     * (a) File diunggah baru saat tambah disiplin → hapus entry dokumen otomatis + hapus file fisik
     *     (jika tidak ada dokumen lain yang masih mereferensikan path yang sama).
     * (b) File dipilih dari arsip yang sudah ada → hapus entry duplikat otomatis, tetapi
     *     dokumen asli dan file fisiknya tetap dipertahankan.
     */
    public function execute(Employee $employee, DisciplineRecord $record, Request $request): void
    {
        $this->abortIfRecordOutsideEmployee($employee, $record);

        $oldValues = $this->payload->response($record);
        $recordId  = $record->id;
        $filePath  = $record->file_sk;

        DB::transaction(function () use ($employee, $record, $filePath): void {
            // Hapus entry dokumen yang dibuat otomatis (keterangan "Unggah otomatis...")
            // saat record ini pertama kali disimpan, baik dari unggah baru maupun dari arsip.
            if ($filePath) {
                $employee->documents()
                    ->where('file_path', $filePath)
                    ->where('jenis_dokumen', 'sk_hukuman_disiplin')
                    ->where('keterangan', 'Unggah otomatis dari riwayat hukuman disiplin.')
                    ->delete();
            }

            // Hapus record disiplin terlebih dahulu sebelum cek referensi file.
            $record->delete();

            // Hapus file fisik hanya jika tidak ada dokumen lain yang masih
            // mereferensikan path yang sama (mis. dokumen arsip yang dipilih pengguna).
            if ($filePath && ! Document::where('file_path', $filePath)->exists()) {
                Storage::disk(Document::STORAGE_DISK)->delete($filePath);
            }
        });

        AuditService::log(
            'DELETE',
            'DisciplineRecord',
            $recordId,
            $oldValues,
            null,
            $request,
        );
    }

    private function abortIfRecordOutsideEmployee(Employee $employee, DisciplineRecord $record): void
    {
        abort_unless($record->employee_id === $employee->id, 404);
    }
}
