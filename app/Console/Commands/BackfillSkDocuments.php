<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackfillSkDocuments extends Command
{
    protected $signature = 'dokumen:backfill-sk';

    protected $description = 'Backfill dokumen arsip dari file SK yang sudah ada di riwayat pegawai';

    public function handle(): int
    {
        $employees = Employee::with(['rankHistories', 'positionHistories', 'salaryHistories', 'appointment'])->get();
        $created = 0;

        foreach ($employees as $employee) {
            foreach ($employee->rankHistories as $rank) {
                if ($rank->file_sk && Storage::disk(Document::STORAGE_DISK)->exists($rank->file_sk)) {
                    if ($this->ensureMirror(
                        $employee,
                        $rank->id,
                        'sk_pangkat',
                        'SK Kenaikan Pangkat',
                        $rank->no_sk,
                        $rank->tanggal_sk,
                        $rank->file_sk,
                        'Backfill otomatis dari riwayat pangkat',
                        'Pangkat'
                    )) {
                        $created++;
                    }
                }
            }

            foreach ($employee->positionHistories as $pos) {
                if ($pos->file_sk && Storage::disk(Document::STORAGE_DISK)->exists($pos->file_sk)) {
                    if ($this->ensureMirror(
                        $employee,
                        $pos->id,
                        'sk_jabatan',
                        'SK Jabatan '.($pos->nama_jabatan ?? ''),
                        $pos->no_sk,
                        $pos->tanggal_sk,
                        $pos->file_sk,
                        'Backfill otomatis dari riwayat jabatan',
                        'Jabatan'
                    )) {
                        $created++;
                    }
                }
            }

            foreach ($employee->salaryHistories as $sal) {
                if ($sal->file_sk && Storage::disk(Document::STORAGE_DISK)->exists($sal->file_sk)) {
                    if ($this->ensureMirror(
                        $employee,
                        $sal->id,
                        'sk_kgb',
                        'SK KGB',
                        $sal->no_sk,
                        $sal->tanggal_sk,
                        $sal->file_sk,
                        'Backfill otomatis dari riwayat KGB',
                        'KGB'
                    )) {
                        $created++;
                    }
                }
            }

            if ($employee->appointment && $employee->appointment->file_sk) {
                $appoint = $employee->appointment;
                if (Storage::disk(Document::STORAGE_DISK)->exists($appoint->file_sk)) {
                    if ($this->ensureMirror(
                        $employee,
                        $appoint->id,
                        'sk_pengangkatan',
                        'SK Pengangkatan '.($appoint->jenis_pengangkatan ?? ''),
                        $appoint->no_sk,
                        $appoint->tanggal_sk,
                        $appoint->file_sk,
                        'Backfill otomatis dari data pengangkatan',
                        'Pengangkatan'
                    )) {
                        $created++;
                    }
                }
            }
        }

        $this->info("Selesai. Total {$created} dokumen baru ditambahkan ke arsip.");

        return self::SUCCESS;
    }

    /**
     * Memastikan mirror dokumen untuk riwayat tertentu tersedia di arsip.
     * Menggunakan history_id sebagai canonical identity, mengklaim row legacy
     * yang history_id-nya null, atau membuat row baru jika belum ada.
     */
    private function ensureMirror(
        Employee $employee,
        string|int $historyId,
        string $jenisDokumen,
        string $namaDokumen,
        ?string $nomorDokumen,
        mixed $tanggalDokumen,
        string $filePath,
        string $keterangan,
        string $label
    ): bool {
        // Step A — existing canonical mirror
        $canonical = Document::query()
            ->where('employee_id', $employee->id)
            ->where('jenis_dokumen', $jenisDokumen)
            ->where('history_id', $historyId)
            ->first();

        if ($canonical) {
            return false;
        }

        // Step B — claim row legacy
        $legacy = Document::query()
            ->where('employee_id', $employee->id)
            ->where('jenis_dokumen', $jenisDokumen)
            ->whereNull('history_id')
            ->where('file_path', $filePath)
            ->first();

        if ($legacy) {
            $legacy->update(['history_id' => $historyId]);
            $this->line("[{$label}] {$employee->nama_lengkap}");

            return false;
        }

        // Step C — create mirror bila tidak ada candidate legacy
        Document::create([
            'employee_id' => $employee->id,
            'history_id' => $historyId,
            'jenis_dokumen' => $jenisDokumen,
            'nama_dokumen' => $namaDokumen,
            'nomor_dokumen' => $nomorDokumen,
            'tanggal_dokumen' => $tanggalDokumen,
            'file_path' => $filePath,
            'keterangan' => $keterangan,
        ]);
        $this->line("[{$label}] {$employee->nama_lengkap}");

        return true;
    }
}
