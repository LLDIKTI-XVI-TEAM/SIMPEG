<?php

namespace App\Actions\Employees;

use App\Models\Document;
use App\Models\Employee;
use App\Services\EmployeeDocumentStatusService;
use App\Services\Employees\EmployeeHistoryAttachmentService;
use App\Support\Documents\DocumentCategory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ShowEmployeeDocumentStatusAction
{
    public function __construct(
        private readonly EmployeeHistoryAttachmentService $attachments,
        private readonly EmployeeDocumentStatusService $employeeDocumentStatusService,
    ) {}

    /**
     * Menyusun rincian status SK setiap riwayat pegawai berdasarkan file aktual.
     *
     * @return array{
     *     status_kelengkapan: string,
     *     is_lengkap: bool,
     *     total_wajib: int,
     *     tersedia_count: int,
     *     belum_ada_count: int,
     *     perlu_perbaikan_count: int,
     *     required_sks: list<array<string, mixed>>,
     *     total_riwayat: int,
     *     file_tersedia: int,
     *     records: list<array<string, mixed>>,
     *     total_dokumen: int,
     *     dokumen_tersedia: int,
     *     documents: list<array<string, mixed>>
     * }
     */
    public function execute(Employee $employee): array
    {
        $employee->load([
            'jenisPegawai:id,nama',
            'rankHistories' => fn ($query) => $query
                ->select(['id', 'employee_id', 'golongan_id', 'no_sk', 'tanggal_sk', 'tmt_pangkat', 'file_sk', 'is_latest'])
                ->with('golongan:id,kode,nama')
                ->orderByDesc('is_latest')
                ->orderByDesc('tmt_pangkat'),
            'positionHistories' => fn ($query) => $query
                ->select(['id', 'employee_id', 'nama_jabatan', 'no_sk', 'tanggal_sk', 'tmt_jabatan', 'file_sk', 'is_latest'])
                ->orderByDesc('is_latest')
                ->orderByDesc('tmt_jabatan'),
            'salaryHistories' => fn ($query) => $query
                ->select(['id', 'employee_id', 'gaji_pokok', 'no_sk', 'tanggal_sk', 'tmt_kgb', 'file_sk', 'is_latest'])
                ->orderByDesc('is_latest')
                ->orderByDesc('tmt_kgb'),
            'appointments' => fn ($query) => $query
                ->select(['id', 'employee_id', 'jenis_pengangkatan', 'no_sk', 'tanggal_sk', 'tmt_pengangkatan', 'file_sk'])
                ->orderByDesc('tmt_pengangkatan'),
            'documents' => fn ($query) => $query
                ->select(['id', 'employee_id', 'jenis_dokumen', 'nama_dokumen', 'nomor_dokumen', 'tanggal_dokumen', 'file_path', 'keterangan', 'created_at'])
                ->orderByDesc('tanggal_dokumen')
                ->orderByDesc('created_at'),
        ]);

        $requiredSkSummary = $this->employeeDocumentStatusService->summarize($employee);
        $disk = Storage::disk(Document::STORAGE_DISK);
        $records = collect();
        $this->attachments->primeDocumentReferences(collect()
            ->concat($employee->rankHistories->pluck('file_sk'))
            ->concat($employee->positionHistories->pluck('file_sk'))
            ->concat($employee->salaryHistories->pluck('file_sk'))
            ->concat($employee->appointments->pluck('file_sk')));

        foreach ($employee->rankHistories as $history) {
            $records->push($this->record(
                $employee,
                $history,
                'rank',
                'Pangkat',
                $history->golongan?->nama ?? $history->golongan?->kode ?? 'Riwayat Pangkat',
                'TMT Pangkat: '.$history->tmt_pangkat?->format('d/m/Y'),
                $history->no_sk,
                $history->tanggal_sk?->format('d/m/Y'),
                $history->file_sk,
            ));
        }

        foreach ($employee->positionHistories as $history) {
            $records->push($this->record(
                $employee,
                $history,
                'position',
                'Jabatan',
                $history->nama_jabatan,
                'TMT Jabatan: '.$history->tmt_jabatan?->format('d/m/Y'),
                $history->no_sk,
                $history->tanggal_sk?->format('d/m/Y'),
                $history->file_sk,
            ));
        }

        foreach ($employee->salaryHistories as $history) {
            $records->push($this->record(
                $employee,
                $history,
                'salary',
                'KGB',
                'Gaji Pokok: Rp '.number_format((float) $history->gaji_pokok, 0, ',', '.'),
                'TMT KGB: '.$history->tmt_kgb?->format('d/m/Y'),
                $history->no_sk,
                $history->tanggal_sk?->format('d/m/Y'),
                $history->file_sk,
            ));
        }

        foreach ($employee->appointments as $history) {
            $records->push($this->record(
                $employee,
                $history,
                'appointment',
                'Pengangkatan',
                $history->jenis_pengangkatan,
                'TMT Pengangkatan: '.$history->tmt_pengangkatan?->format('d/m/Y'),
                $history->no_sk,
                $history->tanggal_sk?->format('d/m/Y'),
                $history->file_sk,
            ));
        }

        $archiveDocuments = $employee->documents
            ->map(fn (Document $document): array => $this->documentRecord($disk, $document));
        $historyDocuments = $records->map(fn (array $record): array => $this->historyDocumentRecord($record));
        $documents = $archiveDocuments
            ->concat($historyDocuments)
            // Arsip Document menjadi sumber utama bila file juga tersambung ke riwayat.
            ->unique(fn (array $document): string => filled($document['file_path'])
                ? 'file:'.$document['file_path']
                : 'record:'.($document['id'] ?? $document['kategori'].'|'.$document['nama'].'|'.$document['nomor']))
            ->values();

        $totalRiwayat = $records->count();
        $fileTersediaCount = $records->where('file_tersedia', true)->count();

        return $requiredSkSummary + [
            'total_riwayat' => $totalRiwayat,
            'file_tersedia' => $fileTersediaCount,
            'records' => $records->values()->all(),
            'total_dokumen' => $documents->count(),
            'dokumen_tersedia' => $documents->where('file_tersedia', true)->count(),
            'documents' => $documents->all(),
        ];
    }

    /**
     * @return array{id: string, kategori: string, nama: string, nomor: string, tanggal: string, keterangan: string, file_path: string|null, file_url: string|null, file_tersedia: bool, status_label: string}
     */
    private function documentRecord(Filesystem $disk, Document $document): array
    {
        $fileTersedia = filled($document->file_path) && $disk->exists($document->file_path);

        return [
            'id' => $document->id,
            'kategori' => DocumentCategory::label($document->jenis_dokumen),
            'nama' => $document->nama_dokumen,
            'nomor' => $document->nomor_dokumen ?: '-',
            'tanggal' => $document->tanggal_dokumen?->format('d/m/Y') ?: '-',
            'keterangan' => $document->keterangan ?: '-',
            'file_path' => $document->file_path,
            'file_url' => $fileTersedia ? route('dokumen.download', $document) : null,
            'file_tersedia' => $fileTersedia,
            'status_label' => $fileTersedia ? 'File tersedia' : 'File tidak ditemukan',
        ];
    }

    /**
     * Menyamakan format file riwayat dengan arsip Document agar modal cukup
     * merender satu daftar dokumen.
     *
     * @param  array<string, mixed>  $record
     * @return array{id: null, kategori: string, nama: string, nomor: string, tanggal: string, keterangan: string, file_path: string|null, file_url: string|null, file_tersedia: bool, status_label: string}
     */
    private function historyDocumentRecord(array $record): array
    {
        return [
            'id' => null,
            'kategori' => $record['jenis'],
            'nama' => $record['judul'],
            'nomor' => $record['nomor_sk'],
            'tanggal' => $record['tanggal_sk'],
            'keterangan' => $record['detail'],
            'file_path' => $record['file_path'],
            'file_url' => $record['file_url'],
            'file_tersedia' => $record['file_tersedia'],
            'status_label' => $record['status_label'],
        ];
    }

    /**
     * @return array{jenis: string, judul: string, detail: string, nomor_sk: string, tanggal_sk: string, file_path: string|null, file_url: string|null, file_tersedia: bool, status_label: string}
     */
    private function record(
        Employee $employee,
        Model $history,
        string $historyType,
        string $jenis,
        ?string $judul,
        string $detail,
        ?string $nomorSk,
        ?string $tanggalSk,
        ?string $filePath,
    ): array {
        $fileUrl = $this->attachments->downloadUrl(
            $employee,
            $historyType,
            $history,
            'pegawai.history-attachments.download',
        );
        $fileTersedia = $fileUrl !== null;

        return [
            'jenis' => $jenis,
            'judul' => $judul ?: 'Riwayat '.$jenis,
            'detail' => $detail,
            'nomor_sk' => $nomorSk ?: '-',
            'tanggal_sk' => $tanggalSk ?: '-',
            'file_path' => $filePath,
            'file_url' => $fileUrl,
            'file_tersedia' => $fileTersedia,
            'status_label' => $fileTersedia ? 'File tersedia' : (blank($filePath) ? 'File belum diunggah' : 'File tidak ditemukan'),
        ];
    }
}
