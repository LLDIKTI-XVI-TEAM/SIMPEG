<?php

namespace App\Actions\Documents;

use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\SalaryHistory;
use Illuminate\Support\Facades\Storage;

class DeleteDocumentAction
{
    /**
     * Periksa riwayat data pegawai yang terhubung dengan dokumen ini (via nomor SK).
     *
     * - blocked_impacts: riwayat yang TIDAK boleh dihapus (jabatan, pangkat, KGB) → blokir penghapusan
     * - deletable_impacts: riwayat yang BOLEH ikut dihapus (hukuman disiplin)
     *
     * @return array{has_blocked: bool, has_deletable: bool, blocked_impacts: array, deletable_impacts: array}
     */
    public function checkImpact(Document $document): array
    {
        if (! $document->nomor_dokumen) {
            return [
                'has_blocked' => false,
                'has_deletable' => false,
                'blocked_impacts' => [],
                'deletable_impacts' => [],
            ];
        }

        $nomorSk = $document->nomor_dokumen;
        $employeeId = $document->employee_id;

        $blocked = [];
        $deletable = [];

        // ── BLOKIR: Kenaikan Pangkat ──────────────────────────────────────────
        $rankHistories = RankHistory::where('employee_id', $employeeId)
            ->where('no_sk', $nomorSk)
            ->get(['id', 'no_sk', 'tanggal_sk']);

        if ($rankHistories->isNotEmpty()) {
            $blocked['Kenaikan Pangkat'] = $rankHistories->map(fn ($r) => [
                'id' => $r->id,
                'label' => 'No. SK: '.$r->no_sk.' — Tanggal: '.($r->tanggal_sk?->format('d/m/Y') ?? '-'),
            ])->values()->all();
        }

        // ── BLOKIR: Riwayat Jabatan ───────────────────────────────────────────
        $positionHistories = PositionHistory::where('employee_id', $employeeId)
            ->where('no_sk', $nomorSk)
            ->get(['id', 'no_sk', 'tmt_jabatan']);

        if ($positionHistories->isNotEmpty()) {
            $blocked['Riwayat Jabatan'] = $positionHistories->map(fn ($r) => [
                'id' => $r->id,
                'label' => 'No. SK: '.$r->no_sk.' — TMT: '.($r->tmt_jabatan?->format('d/m/Y') ?? '-'),
            ])->values()->all();
        }

        // ── BLOKIR: Riwayat Gaji (KGB) ───────────────────────────────────────
        $salaryHistories = SalaryHistory::where('employee_id', $employeeId)
            ->where('no_sk', $nomorSk)
            ->get(['id', 'no_sk', 'tmt_kgb']);

        if ($salaryHistories->isNotEmpty()) {
            $blocked['Riwayat Gaji (KGB)'] = $salaryHistories->map(fn ($r) => [
                'id' => $r->id,
                'label' => 'No. SK: '.$r->no_sk.' — TMT KGB: '.($r->tmt_kgb?->format('d/m/Y') ?? '-'),
            ])->values()->all();
        }

        // ── BISA DIHAPUS: Hukuman Disiplin ────────────────────────────────────
        $disciplineRecords = DisciplineRecord::where('employee_id', $employeeId)
            ->where('no_sk', $nomorSk)
            ->get(['id', 'no_sk', 'jenis_hukuman']);

        if ($disciplineRecords->isNotEmpty()) {
            $deletable['Hukuman Disiplin'] = $disciplineRecords->map(fn ($r) => [
                'id' => $r->id,
                'label' => 'No. SK: '.$r->no_sk.' — Jenis: '.$r->jenis_hukuman,
            ])->values()->all();
        }

        return [
            'has_blocked' => count($blocked) > 0,
            'has_deletable' => count($deletable) > 0,
            'blocked_impacts' => $blocked,
            'deletable_impacts' => $deletable,
        ];
    }

    /**
     * Hapus dokumen beserta file fisiknya.
     * Hanya hukuman disiplin yang boleh ikut dihapus (force_delete_discipline).
     * Jabatan, pangkat, dan KGB TIDAK bisa dihapus melalui sini.
     */
    public function execute(Document $document, bool $forceDeleteDiscipline = false): void
    {
        // Hanya hapus hukuman disiplin terkait jika dikonfirmasi
        if ($forceDeleteDiscipline && $document->nomor_dokumen) {
            $nomorSk = $document->nomor_dokumen;
            $employeeId = $document->employee_id;

            DisciplineRecord::where('employee_id', $employeeId)
                ->where('no_sk', $nomorSk)
                ->each(function (DisciplineRecord $record) {
                    if ($record->file_sk) {
                        Storage::disk(Document::STORAGE_DISK)->delete($record->file_sk);
                    }
                    $record->delete();
                });
        }

        // Hapus file fisik dokumen dari storage
        if ($document->fileExists()) {
            Storage::disk(Document::STORAGE_DISK)->delete($document->file_path);
        }

        // Hapus record dokumen
        $document->delete();
    }
}
