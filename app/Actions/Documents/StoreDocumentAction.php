<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\BuildsDocumentAuditPayload;
use App\Contracts\HasSkDocument;
use App\Models\Appointment;
use App\Models\Document;
use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\SalaryHistory;
use App\Services\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class StoreDocumentAction
{
    use BuildsDocumentAuditPayload;

    /**
     * Categories that should also create a history record.
     * Maps jenis_dokumen → history table class.
     */
    private const HISTORY_CATEGORIES = [
        'sk_pangkat' => 'rank',
        'sk_jabatan' => 'position',
        'sk_kgb' => 'salary',
        'sk_pengangkatan' => 'appointment',
    ];

    public function execute(array $payload, UploadedFile $file): Document
    {
        $employee = Employee::findOrFail($payload['pegawai_id']);
        $category = $payload['kategori_dokumen'];
        $repairHistory = $this->repairableHistory($employee, $category);
        $payload = $this->inheritHistoryMetadata($payload, $repairHistory);
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $filename = $employee->id.'_'.$category.'_'.Str::uuid().'.'.$extension;
        $filePath = $file->storeAs($employee->id.'/'.$category, $filename, Document::STORAGE_DISK);

        try {
            return DB::transaction(function () use ($employee, $category, $payload, $filePath, $repairHistory): Document {
                $document = Document::create([
                    'employee_id' => $employee->id,
                    'jenis_dokumen' => $category,
                    'nama_dokumen' => $payload['nama_dokumen'],
                    'nomor_dokumen' => $payload['nomor_dokumen'] ?? null,
                    'tanggal_dokumen' => $payload['tanggal_terbit'] ?? null,
                    'file_path' => $filePath,
                    'keterangan' => $payload['deskripsi'] ?? null,
                ]);

                // Auto-sync ke tabel history yang relevan
                if (isset(self::HISTORY_CATEGORIES[$category])) {
                    $this->syncHistory($repairHistory, $document);
                }

                $this->syncEmployeeStatus($employee, $category);

                // Payload audit dibatasi pada metadata arsip. Isi berkas tidak pernah masuk audit,
                // dan jalur berkas cukup untuk menelusuri dokumen mana yang dimaksud.
                AuditService::logOrFail(
                    'CREATE',
                    'Document',
                    $document->id,
                    null,
                    $this->auditPayload($document),
                );

                return $document;
            });
        } catch (Throwable $exception) {
            // Jika transaksi database/sinkronisasi gagal, file baru tidak boleh
            // tertinggal tanpa record dokumen yang menunjuk kepadanya.
            Storage::disk(Document::STORAGE_DISK)->delete($filePath);

            throw $exception;
        }
    }

    private function syncEmployeeStatus(Employee $employee, string $category): void
    {
        $statusName = match ($category) {
            'sk_mutasi' => 'Mutasi',
            'sk_pensiun' => 'Pensiun',
            default => null,
        };

        if ($statusName === null) {
            return;
        }

        $status = RefStatusPegawai::query()->where('nama', $statusName)->first();
        if ($status) {
            $employee->update([
                'status_pegawai_id' => $status->id,
                'status_aktif' => $status->nama,
            ]);
        }
    }

    /**
     * @param  RankHistory|PositionHistory|SalaryHistory|Appointment|null  $history
     */
    private function syncHistory(?HasSkDocument $history, Document $document): void
    {
        if ($history === null) {
            return;
        }

        $oldValues = $history->only(['file_sk', 'no_sk', 'tanggal_sk']);

        $history->update([
            'file_sk' => $document->file_path,
            'no_sk' => $document->nomor_dokumen ?? $history->no_sk,
            'tanggal_sk' => $document->tanggal_dokumen ?? $history->tanggal_sk,
        ]);

        AuditService::logOrFail(
            'UPDATE',
            class_basename($history),
            $history->id,
            $oldValues,
            $history->only(['file_sk', 'no_sk', 'tanggal_sk'])
        );

        if ($history instanceof Appointment && $history->jenis_pengangkatan) {
            $jenisPegawai = RefJenisPegawai::whereRaw('UPPER(nama) = ?', [strtoupper($history->jenis_pengangkatan)])->first();
            if ($jenisPegawai) {
                $history->employee->update(['jenis_pegawai_id' => $jenisPegawai->id]);
            }
        }
    }

    /**
     * Ambil riwayat SK yang membutuhkan berkas. Riwayat ini menjadi sumber
     * nomor dan tanggal SK agar unggahan perbaikan tidak mengubah metadata resmi.
     *
     * @return RankHistory|PositionHistory|SalaryHistory|Appointment|null
     */
    private function repairableHistory(Employee $employee, string $category): ?HasSkDocument
    {
        $histories = match ($category) {
            'sk_pangkat' => $employee->rankHistories()->orderByDesc('is_latest')->orderByDesc('tmt_pangkat')->get(),
            'sk_jabatan' => $employee->positionHistories()->orderByDesc('is_latest')->orderByDesc('tmt_jabatan')->get(),
            'sk_kgb' => $employee->salaryHistories()->orderByDesc('is_latest')->orderByDesc('tmt_kgb')->get(),
            'sk_pengangkatan' => $employee->appointments()->orderByDesc('tmt_pengangkatan')->get(),
            default => collect(),
        };

        /** @phpstan-var RankHistory|PositionHistory|SalaryHistory|Appointment|null */
        $latest = $histories->first();

        return $latest !== null && $this->needsFileRepair($latest->file_sk) ? $latest : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  RankHistory|PositionHistory|SalaryHistory|Appointment|null  $history
     * @return array<string, mixed>
     */
    private function inheritHistoryMetadata(array $payload, ?HasSkDocument $history): array
    {
        if ($history === null) {
            return $payload;
        }

        if (filled($history->no_sk)) {
            $payload['nomor_dokumen'] = $history->no_sk;
        }

        if ($history->tanggal_sk !== null) {
            $payload['tanggal_terbit'] = $history->tanggal_sk->toDateString();
        }

        return $payload;
    }

    private function needsFileRepair(?string $filePath): bool
    {
        return blank($filePath) || ! Storage::disk(Document::STORAGE_DISK)->exists($filePath);
    }

    private function syncRank(Employee $employee, ?string $noSk, mixed $tanggalSk, string $fileSk): void
    {
        // Buat history baru tanpa golongan & TMT (akan dilengkapi di Edit Pegawai)
        // Hanya update file_sk di history terbaru yang ada, atau buat entri pendahuluan tanpa is_latest
        $latest = $employee->rankHistories()->where('is_latest', true)->first();
        if ($latest && ! $latest->file_sk) {
            $latest->update(['file_sk' => $fileSk, 'no_sk' => $noSk ?? $latest->no_sk, 'tanggal_sk' => $tanggalSk ?? $latest->tanggal_sk]);
        }
        // Jika tidak ada history atau sudah ada file — simpan referensi untuk nanti diisi di Edit Pegawai
    }

    private function syncPosition(Employee $employee, ?string $noSk, mixed $tanggalSk, string $fileSk): void
    {
        $latest = $employee->positionHistories()->where('is_latest', true)->first();
        if ($latest && ! $latest->file_sk) {
            $latest->update(['file_sk' => $fileSk, 'no_sk' => $noSk ?? $latest->no_sk, 'tanggal_sk' => $tanggalSk ?? $latest->tanggal_sk]);
        }
    }

    private function syncSalary(Employee $employee, ?string $noSk, mixed $tanggalSk, string $fileSk): void
    {
        $latest = $employee->salaryHistories()->where('is_latest', true)->first();
        if ($latest && ! $latest->file_sk) {
            $latest->update(['file_sk' => $fileSk, 'no_sk' => $noSk ?? $latest->no_sk, 'tanggal_sk' => $tanggalSk ?? $latest->tanggal_sk]);
        }
    }

    private function syncAppointment(Employee $employee, ?string $noSk, mixed $tanggalSk, string $fileSk): void
    {
        $appointment = $employee->appointment;
        if ($appointment && ! $appointment->file_sk) {
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
