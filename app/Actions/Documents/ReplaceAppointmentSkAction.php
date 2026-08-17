<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\BuildsDocumentAuditPayload;
use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefJenisPegawai;
use App\Models\SalaryHistory;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use App\Services\Employees\TmtCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReplaceAppointmentSkAction
{
    use BuildsDocumentAuditPayload;

    public function __construct(
        private readonly EmployeeFileStorageService $files,
        private readonly TmtCalculatorService $tmtCalculator,
    ) {}

    /**
     * Mengganti SK pengangkatan: satu appointment aktif dan satu dokumen arsip.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Employee $employee, array $data, ?Request $request = null): Document
    {
        /** @var UploadedFile $file */
        $file = $data['file_sk'];
        $newPath = $this->files->storeSk($file);

        $transactionCommitted = false;

        try {
            [$document, $oldFilePath, $oldDocumentPath] = DB::transaction(function () use ($employee, $data, $newPath, $request): array {
                $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();

                $appointment = $employee->appointments()
                    ->orderByDesc('tmt_pengangkatan')
                    ->orderByDesc('created_at')
                    ->lockForUpdate()
                    ->first();

                $oldFilePath = $appointment?->file_sk;
                $appointmentPayload = [
                    'jenis_pengangkatan' => $data['jenis_pengangkatan'],
                    'tmt_pengangkatan' => $data['tmt_pengangkatan'],
                    'no_sk' => $data['no_sk'],
                    'tanggal_sk' => $data['tanggal_sk'],
                    'file_sk' => $newPath,
                ];

                if ($appointment === null) {
                    $appointment = $employee->appointments()->create($appointmentPayload);
                    AuditService::logOrFail('CREATE', 'Appointment', $appointment->id, null, $appointment->toArray(), $request);
                } else {
                    $oldValues = $appointment->only([
                        'jenis_pengangkatan',
                        'tmt_pengangkatan',
                        'no_sk',
                        'tanggal_sk',
                        'file_sk',
                    ]);
                    $appointment->update($appointmentPayload);
                    AuditService::logOrFail(
                        'UPDATE',
                        'Appointment',
                        $appointment->id,
                        $oldValues,
                        $appointment->only([
                            'jenis_pengangkatan',
                            'tmt_pengangkatan',
                            'no_sk',
                            'tanggal_sk',
                            'file_sk',
                        ]),
                        $request,
                    );
                }

                [$document, $oldDocumentPath] = $this->replaceDocument($employee, $appointment, $oldFilePath, $newPath);

                $jenisPegawai = RefJenisPegawai::whereRaw('UPPER(nama) = ?', [
                    strtoupper((string) $appointment->jenis_pengangkatan),
                ])->first();
                if ($jenisPegawai) {
                    $employee->update(['jenis_pegawai_id' => $jenisPegawai->id]);
                }

                $this->tmtCalculator->syncForEmployee($employee);

                return [$document, $oldFilePath, $oldDocumentPath];
            });

            $transactionCommitted = true;
        } catch (\Throwable $exception) {
            // File baru hanya dihapus jika transaksi gagal sebelum commit
            Storage::disk(Document::STORAGE_DISK)->delete($newPath);

            throw $exception;
        }

        // Cleanup file lama dilakukan di luar blok try-catch transaksi:
        // kegagalan cleanup pasca-commit tidak boleh membatalkan file baru yang sudah sah di database.
        // Cleanup dilakukan untuk keduanya: path appointment lama DAN path dokumen lama yang diganti.
        try {
            foreach (array_filter([$oldFilePath, $oldDocumentPath]) as $stalePath) {
                if ($stalePath !== $newPath && ! $this->fileIsStillReferenced($stalePath)) {
                    Storage::disk(Document::STORAGE_DISK)->delete($stalePath);
                }
            }
        } catch (\Throwable $cleanupException) {
            // Catat log jika cleanup gagal, tetapi tidak mengganggu kembalian dokumen
            report($cleanupException);
        }

        return $document;
    }

    /**
     * @return array{0: Document, 1: string|null} 0: dokumen arsip, 1: path file lama dokumen yang diganti
     */
    private function replaceDocument(
        Employee $employee,
        Appointment $appointment,
        ?string $oldFilePath,
        string $newPath,
    ): array {
        $document = null;

        if (filled($oldFilePath)) {
            $document = $employee->documents()
                ->where('jenis_dokumen', 'sk_pengangkatan')
                ->where('file_path', $oldFilePath)
                ->lockForUpdate()
                ->first();
        }

        $document ??= $employee->documents()
            ->where('jenis_dokumen', 'sk_pengangkatan')
            ->orderByDesc('tanggal_dokumen')
            ->orderByDesc('created_at')
            ->lockForUpdate()
            ->first();

        // Path lama dokumen arsip sebelum di-update. Bisa berbeda dari oldFilePath
        // (path appointment) bila fallback ke latest dokumen sk_pengangkatan.
        $oldDocumentPath = $document?->file_path;

        $payload = [
            'jenis_dokumen' => 'sk_pengangkatan',
            'nama_dokumen' => 'SK Pengangkatan '.$appointment->jenis_pengangkatan,
            'nomor_dokumen' => $appointment->no_sk,
            'tanggal_dokumen' => $appointment->tanggal_sk,
            'file_path' => $newPath,
            'keterangan' => 'Diganti dari tab Dokumen SK.',
        ];

        if ($document === null) {
            $document = $employee->documents()->create($payload);
            AuditService::logOrFail('CREATE', 'Document', $document->id, null, $this->auditPayload($document));

            return [$document, null];
        }

        $oldValues = $this->auditPayload($document);
        $document->update($payload);
        AuditService::logOrFail(
            'UPDATE',
            'Document',
            $document->id,
            $oldValues,
            $this->auditPayload($document->fresh()),
        );

        return [$document, $oldDocumentPath];
    }

    /**
     * Referensi file dicek lengkap (setara UpdateDocumentAction) sebelum file lama
     * boleh dihapus: path yang sama dapat dipakai riwayat/disiplin/status pegawai.
     */
    private function fileIsStillReferenced(string $filePath): bool
    {
        return Document::query()->where('file_path', $filePath)->exists()
            || Employee::query()->where('status_berkas_path', $filePath)->exists()
            || EmployeeStatusHistory::query()->where('file_sk', $filePath)->exists()
            || RankHistory::query()->where('file_sk', $filePath)->exists()
            || PositionHistory::query()->where('file_sk', $filePath)->exists()
            || SalaryHistory::query()->where('file_sk', $filePath)->exists()
            || DisciplineRecord::query()->where('file_sk', $filePath)->exists()
            || Appointment::query()->where('file_sk', $filePath)->exists()
            || EducationHistory::query()->where('file_ijazah', $filePath)->exists();
    }
}
