<?php

namespace App\Actions\Documents;

use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\EducationHistory;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\SalaryHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DeleteDocumentAction
{
    /**
     * Periksa seluruh riwayat pegawai yang menggunakan file dokumen.
     *
     * file_path adalah sumber kebenaran utama. Nomor SK hanya dipakai sebagai fallback
     * untuk kategori SK yang sejenis agar arsip lama tetap dapat dideteksi.
     *
     * @return array{has_blocked: bool, has_deletable: bool, blocked_impacts: array<string, list<array{id: string, label: string}>>, deletable_impacts: array<string, list<array{id: string, label: string}>>}
     */
    public function checkImpact(Document $document): array
    {
        $blocked = [];

        $rankHistories = $this->matchingSkRecords(RankHistory::query(), $document, 'file_sk', 'sk_pangkat')
            ->get(['id', 'no_sk', 'tanggal_sk']);
        if ($rankHistories->isNotEmpty()) {
            $blocked['Kenaikan Pangkat'] = $rankHistories->map(fn (RankHistory $record): array => [
                'id' => $record->id,
                'label' => 'No. SK: '.($record->no_sk ?: '-').' — Tanggal: '.($record->tanggal_sk?->format('d/m/Y') ?? '-'),
            ])->values()->all();
        }

        $positionHistories = $this->matchingSkRecords(PositionHistory::query(), $document, 'file_sk', 'sk_jabatan')
            ->get(['id', 'no_sk', 'tmt_jabatan']);
        if ($positionHistories->isNotEmpty()) {
            $blocked['Riwayat Jabatan'] = $positionHistories->map(fn (PositionHistory $record): array => [
                'id' => $record->id,
                'label' => 'No. SK: '.($record->no_sk ?: '-').' — TMT: '.($record->tmt_jabatan?->format('d/m/Y') ?? '-'),
            ])->values()->all();
        }

        $salaryHistories = $this->matchingSkRecords(SalaryHistory::query(), $document, 'file_sk', 'sk_kgb')
            ->get(['id', 'no_sk', 'tmt_kgb']);
        if ($salaryHistories->isNotEmpty()) {
            $blocked['Riwayat Gaji (KGB)'] = $salaryHistories->map(fn (SalaryHistory $record): array => [
                'id' => $record->id,
                'label' => 'No. SK: '.($record->no_sk ?: '-').' — TMT KGB: '.($record->tmt_kgb?->format('d/m/Y') ?? '-'),
            ])->values()->all();
        }

        $appointments = $this->matchingSkRecords(Appointment::query(), $document, 'file_sk', 'sk_pengangkatan')
            ->get(['id', 'jenis_pengangkatan', 'no_sk', 'tmt_pengangkatan']);
        if ($appointments->isNotEmpty()) {
            $blocked['Pengangkatan'] = $appointments->map(fn (Appointment $record): array => [
                'id' => $record->id,
                'label' => 'Jenis: '.($record->jenis_pengangkatan ?: '-').' — No. SK: '.($record->no_sk ?: '-').' — TMT: '.($record->tmt_pengangkatan?->format('d/m/Y') ?? '-'),
            ])->values()->all();
        }

        if ($this->isCurrentStatusSupportingDocument($document)) {
            $status = $document->employee?->statusPegawai?->nama ?? $document->employee?->status_aktif ?? '-';
            $blocked['Status Pegawai'] = [[
                'id' => $document->employee_id,
                'label' => 'Status pegawai saat ini: '.$status,
            ]];
        }

        $disciplineRecords = $this->matchingSkRecords(DisciplineRecord::query(), $document, 'file_sk', 'sk_hukuman_disiplin')
            ->get(['id', 'no_sk', 'jenis_hukuman']);
        if ($disciplineRecords->isNotEmpty()) {
            // Riwayat disiplin bersifat append-only agar jejak keputusan kepegawaian tidak dapat dihapus lewat arsip.
            $blocked['Hukuman Disiplin'] = $disciplineRecords->map(fn (DisciplineRecord $record): array => [
                'id' => $record->id,
                'label' => 'No. SK: '.($record->no_sk ?: '-').' — Jenis: '.$record->jenis_hukuman,
            ])->values()->all();
        }

        return [
            'has_blocked' => $blocked !== [],
            'has_deletable' => false,
            'blocked_impacts' => $blocked,
            'deletable_impacts' => [],
        ];
    }

    /**
     * Hapus dokumen hanya saat tidak dipakai oleh riwayat penting.
     * Penegakan dilakukan di server, bukan hanya pada modal pemeriksaan dampak.
     */
    public function execute(Document $document): void
    {
        $filePath = DB::transaction(function () use ($document): string {
            /** @var Document $lockedDocument */
            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            $impact = $this->checkImpact($lockedDocument);

            if ($impact['has_blocked']) {
                throw ValidationException::withMessages([
                    'document' => 'Dokumen tidak dapat dihapus karena masih digunakan oleh data kepegawaian.',
                ]);
            }

            $filePath = $lockedDocument->file_path;
            $lockedDocument->delete();

            return $filePath;
        });

        // File dihapus setelah transaksi sukses dan hanya bila tidak dipakai referensi lain.
        if ($filePath !== null && ! $this->fileIsStillReferenced($filePath)) {
            Storage::disk(Document::STORAGE_DISK)->delete($filePath);
        }
    }

    /**
     * Batasi fallback nomor SK hanya pada kategori yang memang menggunakan SK tersebut.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function matchingSkRecords(Builder $query, Document $document, string $fileColumn, string $category): Builder
    {
        return $query
            ->where('employee_id', $document->employee_id)
            ->where(function (Builder $query) use ($document, $fileColumn, $category): void {
                $query->where($fileColumn, $document->file_path);

                if ($document->jenis_dokumen === $category && filled($document->nomor_dokumen)) {
                    $query->orWhere('no_sk', $document->nomor_dokumen);
                }
            });
    }

    private function isCurrentStatusSupportingDocument(Document $document): bool
    {
        $expectedStatus = match ($document->jenis_dokumen) {
            'sk_mutasi' => 'Mutasi',
            'sk_pensiun' => 'Pensiun',
            // Dukungan arsip legacy yang sebelumnya disimpan sebagai kategori "lainnya".
            'lainnya' => match (mb_strtolower(trim($document->nama_dokumen))) {
                'sk mutasi' => 'Mutasi',
                'sk pensiun' => 'Pensiun',
                default => null,
            },
            default => null,
        };

        if ($expectedStatus === null) {
            return false;
        }

        $currentStatus = $document->employee?->statusPegawai?->nama ?? $document->employee?->status_aktif;

        return $currentStatus === $expectedStatus;
    }

    private function fileIsStillReferenced(string $filePath): bool
    {
        return Document::query()->where('file_path', $filePath)->exists()
            || RankHistory::query()->where('file_sk', $filePath)->exists()
            || PositionHistory::query()->where('file_sk', $filePath)->exists()
            || SalaryHistory::query()->where('file_sk', $filePath)->exists()
            || DisciplineRecord::query()->where('file_sk', $filePath)->exists()
            || Appointment::query()->where('file_sk', $filePath)->exists()
            || EducationHistory::query()->where('file_ijazah', $filePath)->exists();
    }
}
