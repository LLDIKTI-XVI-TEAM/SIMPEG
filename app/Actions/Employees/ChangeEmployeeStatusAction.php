<?php

namespace App\Actions\Employees;

use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusTransition;
use App\Models\RefStatusPegawai;
use App\Services\EmployeeFileStorageService;
use App\Services\Employees\EmployeeStatusChangeResult;
use App\Services\Employees\EmployeeStatusLifecycleService;
use App\Services\Employees\EmployeeStatusTransitionService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** Mengorkestrasi perubahan status generik, attachment, dan penjadwalan. */
class ChangeEmployeeStatusAction
{
    public function __construct(
        private readonly EmployeeFileStorageService $files,
        private readonly EmployeeStatusTransitionService $transitions,
        private readonly EmployeeStatusLifecycleService $lifecycle,
    ) {}

    /**
     * @param  array{status_pegawai_id: string, keterangan: string, tanggal: string, deskripsi?: string}  $data
     */
    public function execute(
        Employee $employee,
        array $data,
        Request $request,
        ?UploadedFile $berkas = null,
        ?Document $statusDocument = null,
    ): EmployeeStatusChangeResult {
        $status = RefStatusPegawai::query()->find($data['status_pegawai_id']);
        if (! $status instanceof RefStatusPegawai) {
            throw ValidationException::withMessages([
                'status_pegawai_id' => 'Status tujuan tidak tersedia atau sudah dinonaktifkan.',
            ]);
        }
        $this->assertValidStatusDocument($employee, $berkas, $statusDocument);

        if ($this->transitions->isFuture((string) $data['tanggal'])) {
            return $this->schedule($employee, $status, $data, $request, $berkas);
        }

        $storedFilePath = null;

        try {
            $result = $this->lifecycle->mutate(
                $employee,
                $status,
                (string) $data['tanggal'],
                (string) ($data['keterangan'] ?? ''),
                $request,
                replaceDocumentSnapshot: true,
                documentFactory: function (Employee $lockedEmployee, RefStatusPegawai $lockedStatus) use ($data, $berkas, $statusDocument, &$storedFilePath): array {
                    if ($statusDocument !== null) {
                        $lockedDocument = Document::query()
                            ->whereKey($statusDocument->id)
                            ->lockForUpdate()
                            ->firstOrFail();
                        $this->assertValidStatusDocument($lockedEmployee, null, $lockedDocument);

                        return [
                            'file_path' => $lockedDocument->file_path,
                            'document_number' => $lockedDocument->nomor_dokumen,
                        ];
                    }

                    if ($berkas === null) {
                        return ['file_path' => null, 'document_number' => null];
                    }

                    $storedFilePath = $this->files->storeEmployeeDocument(
                        $berkas,
                        $lockedEmployee->id.'/sk_status_pegawai',
                    );
                    $nomorBerkas = $this->generateNomorBerkas($lockedStatus);

                    Document::create([
                        'employee_id' => $lockedEmployee->id,
                        'jenis_dokumen' => 'sk_status_pegawai',
                        'nama_dokumen' => 'SK Perubahan Status — '.$lockedStatus->nama,
                        'nomor_dokumen' => $nomorBerkas,
                        'tanggal_dokumen' => $data['tanggal'],
                        'file_path' => $storedFilePath,
                        'keterangan' => $data['deskripsi'] ?? null,
                    ]);

                    return [
                        'file_path' => $storedFilePath,
                        'document_number' => $nomorBerkas,
                    ];
                },
            );
        } catch (\Throwable $exception) {
            // File berada di luar transaksi database, sehingga rollback perlu kompensasi.
            $this->files->deleteEmployeeDocumentFile($storedFilePath);

            throw $exception;
        }

        if (! $result->changed) {
            throw ValidationException::withMessages([
                'status_pegawai_id' => 'Pegawai sudah berstatus '.$result->targetStatus->nama.' pada tanggal '.$data['tanggal'].'.',
            ]);
        }

        $this->lifecycle->notify($result, EmployeeStatusLifecycleService::CONTEXT_GENERIC);

        return new EmployeeStatusChangeResult(
            $result->employee,
            EmployeeStatusChangeResult::STATE_APPLIED,
            (string) $data['tanggal'],
        );
    }

    /**
     * @param  array{status_pegawai_id: string, keterangan: string, tanggal: string, deskripsi?: string}  $data
     */
    private function schedule(
        Employee $employee,
        RefStatusPegawai $status,
        array $data,
        Request $request,
        ?UploadedFile $berkas,
    ): EmployeeStatusChangeResult {
        if ($this->transitions->isScheduled($employee, (string) $data['tanggal'])) {
            throw ValidationException::withMessages([
                'tanggal' => 'Perubahan status pada tanggal tersebut sudah dijadwalkan.',
            ]);
        }

        $storedFilePath = null;
        $recoveryTaskId = null;

        try {
            $this->transitions->scheduleWithDocumentFactory(
                $employee,
                $status,
                (string) $data['tanggal'],
                EmployeeStatusTransition::KIND_STATUS,
                $data['keterangan'] ?? null,
                null,
                function (Employee $lockedEmployee, RefStatusPegawai $lockedStatus) use ($data, $berkas, &$storedFilePath, &$recoveryTaskId): ?Document {
                    if ($berkas === null) {
                        return null;
                    }

                    $stored = $this->files->storeEmployeeStatusDocument($berkas, $lockedEmployee->id);
                    $storedFilePath = $stored['path'];
                    $recoveryTaskId = $stored['recovery_task_id'];
                    $nomorBerkas = $this->generateNomorBerkas($lockedStatus);

                    return Document::create([
                        'employee_id' => $lockedEmployee->id,
                        'jenis_dokumen' => 'sk_status_pegawai',
                        'nama_dokumen' => 'SK Perubahan Status — '.$lockedStatus->nama,
                        'nomor_dokumen' => $nomorBerkas,
                        'tanggal_dokumen' => $data['tanggal'],
                        'file_path' => $storedFilePath,
                        'keterangan' => $data['deskripsi'] ?? null,
                    ]);
                },
                $request,
            );

            if ($recoveryTaskId !== null && $storedFilePath !== null) {
                $this->files->adoptEmployeeStatusDocument($recoveryTaskId, $employee->id, $storedFilePath);
            }
        } catch (\Throwable $exception) {
            $this->files->deleteEmployeeStatusDocument($storedFilePath, $employee->id);

            throw $exception;
        }

        return new EmployeeStatusChangeResult(
            $employee,
            EmployeeStatusChangeResult::STATE_SCHEDULED,
            (string) $data['tanggal'],
        );
    }

    private function generateNomorBerkas(RefStatusPegawai $status): string
    {
        $kode = $status->kode !== null && $status->kode !== '' ? $status->kode : 'STATUS';

        return 'SK-'.$kode.'-'.now()->format('Ymd').'-'.strtoupper(substr(uniqid(), -5));
    }

    /** Dokumen existing hanya boleh berupa SK status milik pegawai yang sama. */
    private function assertValidStatusDocument(
        Employee $employee,
        ?UploadedFile $berkas,
        ?Document $statusDocument,
    ): void {
        if ($berkas !== null && $statusDocument !== null) {
            throw ValidationException::withMessages([
                'berkas' => 'Berkas baru dan dokumen status existing tidak boleh dipakai bersamaan.',
            ]);
        }

        if ($statusDocument === null) {
            return;
        }

        if ($statusDocument->employee_id !== $employee->id) {
            throw ValidationException::withMessages([
                'berkas' => 'Dokumen status harus dimiliki oleh pegawai yang sama.',
            ]);
        }

        if ($statusDocument->jenis_dokumen !== 'sk_status_pegawai') {
            throw ValidationException::withMessages([
                'berkas' => 'Dokumen status harus berkategori sk_status_pegawai.',
            ]);
        }
    }
}
