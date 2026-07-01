<?php

namespace App\Actions\Documents;

use App\Models\Appointment;
use App\Models\Document;
use App\Models\Employee;
use App\Models\RefJenisPegawai;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StoreDocumentAction
{
    /**
     * Categories that should also create a history record.
     * Maps jenis_dokumen → history table class.
     */
    private const HISTORY_CATEGORIES = [
        'sk_pangkat'      => 'rank',
        'sk_jabatan'      => 'position',
        'sk_kgb'          => 'salary',
        'sk_pengangkatan' => 'appointment',
    ];

    public function execute(array $payload, UploadedFile $file): Document
    {
        $employee = Employee::findOrFail($payload['pegawai_id']);
        $category = $payload['kategori_dokumen'];
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $filename = $employee->id.'_'.$category.'_'.now()->format('YmdHis').'.'.$extension;
        $filePath = $file->storeAs($employee->id.'/'.$category, $filename, Document::STORAGE_DISK);

        return DB::transaction(function () use ($employee, $category, $payload, $filePath): Document {
            $document = Document::create([
                'employee_id'     => $employee->id,
                'jenis_dokumen'   => $category,
                'nama_dokumen'    => $payload['nama_dokumen'],
                'nomor_dokumen'   => $payload['nomor_dokumen'] ?? null,
                'tanggal_dokumen' => $payload['tanggal_terbit'] ?? null,
                'file_path'       => $filePath,
                'keterangan'      => $payload['deskripsi'] ?? null,
            ]);

            // Auto-sync ke tabel history yang relevan
            if (isset(self::HISTORY_CATEGORIES[$category])) {
                $this->syncHistory($employee, $document, $category, $payload);
            }

            return $document;
        });
    }

    private function syncHistory(Employee $employee, Document $document, string $category, array $payload): void
    {
        $noSk       = $document->nomor_dokumen;
        $tanggalSk  = $document->tanggal_dokumen;
        $fileSk     = $document->file_path;
        $keterangan = 'Dibuat otomatis dari unggahan Arsip Dokumen.';

        match ($category) {
            'sk_pangkat' => $this->syncRank($employee, $noSk, $tanggalSk, $fileSk),
            'sk_jabatan' => $this->syncPosition($employee, $noSk, $tanggalSk, $fileSk),
            'sk_kgb'     => $this->syncSalary($employee, $noSk, $tanggalSk, $fileSk),
            'sk_pengangkatan' => $this->syncAppointment($employee, $noSk, $tanggalSk, $fileSk),
            default      => null,
        };
    }

    private function syncRank(Employee $employee, ?string $noSk, mixed $tanggalSk, string $fileSk): void
    {
        // Buat history baru tanpa golongan & TMT (akan dilengkapi di Edit Pegawai)
        // Hanya update file_sk di history terbaru yang ada, atau buat entri pendahuluan tanpa is_latest
        $latest = $employee->rankHistories()->where('is_latest', true)->first();
        if ($latest && !$latest->file_sk) {
            $latest->update(['file_sk' => $fileSk, 'no_sk' => $noSk ?? $latest->no_sk, 'tanggal_sk' => $tanggalSk ?? $latest->tanggal_sk]);
        }
        // Jika tidak ada history atau sudah ada file — simpan referensi untuk nanti diisi di Edit Pegawai
    }

    private function syncPosition(Employee $employee, ?string $noSk, mixed $tanggalSk, string $fileSk): void
    {
        $latest = $employee->positionHistories()->where('is_latest', true)->first();
        if ($latest && !$latest->file_sk) {
            $latest->update(['file_sk' => $fileSk, 'no_sk' => $noSk ?? $latest->no_sk, 'tanggal_sk' => $tanggalSk ?? $latest->tanggal_sk]);
        }
    }

    private function syncSalary(Employee $employee, ?string $noSk, mixed $tanggalSk, string $fileSk): void
    {
        $latest = $employee->salaryHistories()->where('is_latest', true)->first();
        if ($latest && !$latest->file_sk) {
            $latest->update(['file_sk' => $fileSk, 'no_sk' => $noSk ?? $latest->no_sk, 'tanggal_sk' => $tanggalSk ?? $latest->tanggal_sk]);
        }
    }

    private function syncAppointment(Employee $employee, ?string $noSk, mixed $tanggalSk, string $fileSk): void
    {
        $appointment = $employee->appointment;
        if ($appointment && !$appointment->file_sk) {
            $appointment->update(['file_sk' => $fileSk, 'no_sk' => $noSk ?? $appointment->no_sk, 'tanggal_sk' => $tanggalSk ?? $appointment->tanggal_sk]);
        }

        // Sync jenis_pegawai_id jika ada nomor dokumen mengindikasikan jenis pengangkatan
        // (ini hanya bisa dilakukan ketika category = sk_pengangkatan dan ada appointment)
        if ($appointment && $appointment->jenis_pengangkatan) {
            $jenisPegawai = RefJenisPegawai::whereRaw('UPPER(nama) = ?', [strtoupper($appointment->jenis_pengangkatan)])->first();
            if ($jenisPegawai) {
                $employee->update(['jenis_pegawai_id' => $jenisPegawai->id]);
            }
        }
    }
}
