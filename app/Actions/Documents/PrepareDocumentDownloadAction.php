<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Services\Employees\EmployeeHistoryAttachmentService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrepareDocumentDownloadAction
{
    public function __construct(private readonly EmployeeHistoryAttachmentService $attachments) {}

    /**
     * Menyiapkan file unduhan dengan pembatas pegawai dan kategori sesuai surface pemanggil.
     *
     * @param  list<string>|null  $allowedCategories
     * @param  bool  $rejectAmbiguousMetadata  Tolak path yang diklaim metadata lintas pemilik atau kategori.
     * @return array{path: string, filename: string}
     */
    public function execute(
        string $id,
        ?string $employeeId = null,
        ?array $allowedCategories = null,
        bool $rejectAmbiguousMetadata = false,
    ): array {
        $document = Document::query()
            ->with('employee:id,nama_lengkap')
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->when($allowedCategories !== null, fn ($query) => $query->whereIn('jenis_dokumen', $allowedCategories))
            ->findOrFail($id);

        if ($rejectAmbiguousMetadata) {
            // Surface read-only Pimpinan wajib menolak path yang juga diklaim metadata lintas scope.
            abort_unless(
                $document->employee !== null
                    && $allowedCategories !== null
                    && $this->attachments->availableDocumentPath(
                        $document->employee,
                        $document,
                        $allowedCategories,
                    ) !== null,
                404,
            );
        }

        if (! Storage::disk(Document::STORAGE_DISK)->exists($document->file_path)) {
            abort(404);
        }

        $extension = pathinfo($document->file_path, PATHINFO_EXTENSION);
        $employeeName = $document->employee ? $document->employee->nama_lengkap : 'pegawai';
        $filename = Str::slug($employeeName).'-'.Str::slug($document->nama_dokumen).'.'.$extension;

        return [
            'path' => $document->file_path,
            'filename' => $filename,
        ];
    }
}
