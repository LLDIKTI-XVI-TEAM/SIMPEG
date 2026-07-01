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
                    $exists = Document::where('employee_id', $employee->id)->where('jenis_dokumen', 'sk_pangkat')->where('file_path', $rank->file_sk)->exists();
                    if (! $exists) {
                        Document::create(['employee_id' => $employee->id, 'jenis_dokumen' => 'sk_pangkat', 'nama_dokumen' => 'SK Kenaikan Pangkat', 'nomor_dokumen' => $rank->no_sk, 'tanggal_dokumen' => $rank->tanggal_sk, 'file_path' => $rank->file_sk, 'keterangan' => 'Backfill otomatis dari riwayat pangkat']);
                        $created++;
                        $this->line("[Pangkat] {$employee->nama_lengkap}");
                    }
                }
            }
            foreach ($employee->positionHistories as $pos) {
                if ($pos->file_sk && Storage::disk(Document::STORAGE_DISK)->exists($pos->file_sk)) {
                    $exists = Document::where('employee_id', $employee->id)->where('jenis_dokumen', 'sk_jabatan')->where('file_path', $pos->file_sk)->exists();
                    if (! $exists) {
                        Document::create(['employee_id' => $employee->id, 'jenis_dokumen' => 'sk_jabatan', 'nama_dokumen' => 'SK Jabatan '.($pos->nama_jabatan ?? ''), 'nomor_dokumen' => $pos->no_sk, 'tanggal_dokumen' => $pos->tanggal_sk, 'file_path' => $pos->file_sk, 'keterangan' => 'Backfill otomatis dari riwayat jabatan']);
                        $created++;
                        $this->line("[Jabatan] {$employee->nama_lengkap}");
                    }
                }
            }
            foreach ($employee->salaryHistories as $sal) {
                if ($sal->file_sk && Storage::disk(Document::STORAGE_DISK)->exists($sal->file_sk)) {
                    $exists = Document::where('employee_id', $employee->id)->where('jenis_dokumen', 'sk_kgb')->where('file_path', $sal->file_sk)->exists();
                    if (! $exists) {
                        Document::create(['employee_id' => $employee->id, 'jenis_dokumen' => 'sk_kgb', 'nama_dokumen' => 'SK KGB', 'nomor_dokumen' => $sal->no_sk, 'tanggal_dokumen' => $sal->tanggal_sk, 'file_path' => $sal->file_sk, 'keterangan' => 'Backfill otomatis dari riwayat KGB']);
                        $created++;
                        $this->line("[KGB] {$employee->nama_lengkap}");
                    }
                }
            }
            if ($employee->appointment && $employee->appointment->file_sk) {
                $appoint = $employee->appointment;
                if (Storage::disk(Document::STORAGE_DISK)->exists($appoint->file_sk)) {
                    $exists = Document::where('employee_id', $employee->id)->where('jenis_dokumen', 'sk_pengangkatan')->where('file_path', $appoint->file_sk)->exists();
                    if (! $exists) {
                        Document::create(['employee_id' => $employee->id, 'jenis_dokumen' => 'sk_pengangkatan', 'nama_dokumen' => 'SK Pengangkatan '.($appoint->jenis_pengangkatan ?? ''), 'nomor_dokumen' => $appoint->no_sk, 'tanggal_dokumen' => $appoint->tanggal_sk, 'file_path' => $appoint->file_sk, 'keterangan' => 'Backfill otomatis dari data pengangkatan']);
                        $created++;
                        $this->line("[Pengangkatan] {$employee->nama_lengkap}");
                    }
                }
            }
        }

        $this->info("Selesai. Total {$created} dokumen baru ditambahkan ke arsip.");

        return self::SUCCESS;
    }
}
