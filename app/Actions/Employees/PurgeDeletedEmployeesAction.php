<?php

namespace App\Actions\Employees;

use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\SalaryHistory;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Menghapus permanen pegawai yang sudah ≥ 30 hari di trash (soft deleted).
 *
 * Urutan operasi per pegawai:
 *  1. Kumpulkan semua path file dari relasi (foto, SK, ijazah, dokumen arsip)
 *  2. ForceDelete employee dalam transaksi DB (cascade menghapus semua child rows)
 *  3. Hapus semua file fisik dari storage setelah transaksi sukses
 *  4. Catat audit log PURGE_DELETE
 */
class PurgeDeletedEmployeesAction
{
    /** Jumlah hari retensi sebelum data dihapus permanen. */
    public const RETENTION_DAYS = 30;

    /**
     * Purge semua pegawai yang deleted_at sudah ≥ RETENTION_DAYS hari lalu.
     *
     * @return array{purged: int, errors: int, names: list<string>}
     */
    public function execute(): array
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);

        $employees = Employee::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->with([
                'rankHistories',
                'positionHistories',
                'salaryHistories',
                'disciplineRecords',
                'educationHistories',
                'documents',
                'appointments',
            ])
            ->get();

        $purged = 0;
        $errors = 0;
        $names = [];

        foreach ($employees as $employee) {
            try {
                $filePaths = $this->collectFilePaths($employee);

                DB::transaction(function () use ($employee): void {
                    $oldValues = $employee->getRawOriginal();
                    $employeeId = $employee->id;

                    // forceDelete cascade hapus semua child rows di DB
                    $employee->forceDelete();

                    AuditService::log('PURGE_DELETE', 'Employee', $employeeId, $oldValues, null);
                });

                // Hapus file fisik setelah transaksi DB sukses
                $this->deleteFiles($filePaths);

                $purged++;
                $names[] = $employee->nama_lengkap.' (NIP: '.$employee->nip.')';
            } catch (\Throwable $e) {
                $errors++;
                report($e);
            }
        }

        return compact('purged', 'errors', 'names');
    }

    /**
     * Kumpulkan semua path file yang perlu dihapus dari storage.
     *
     * @return list<string>
     */
    private function collectFilePaths(Employee $employee): array
    {
        $paths = [];

        // Foto profil
        if ($employee->foto_public_path) {
            $paths[] = $employee->foto_public_path;
        }

        // SK Kepangkatan
        foreach ($employee->rankHistories as $rank) {
            /** @var RankHistory $rank */
            if ($rank->file_sk) {
                $paths[] = $rank->file_sk;
            }
        }

        // SK Jabatan
        foreach ($employee->positionHistories as $pos) {
            /** @var PositionHistory $pos */
            if ($pos->file_sk) {
                $paths[] = $pos->file_sk;
            }
        }

        // SK KGB
        foreach ($employee->salaryHistories as $sal) {
            /** @var SalaryHistory $sal */
            if ($sal->file_sk) {
                $paths[] = $sal->file_sk;
            }
        }

        // SK Hukuman Disiplin
        foreach ($employee->disciplineRecords as $disc) {
            /** @var DisciplineRecord $disc */
            if ($disc->file_sk) {
                $paths[] = $disc->file_sk;
            }
        }

        // Ijazah Pendidikan
        foreach ($employee->educationHistories as $edu) {
            /** @var EducationHistory $edu */
            if ($edu->file_ijazah) {
                $paths[] = $edu->file_ijazah;
            }
        }

        // Dokumen Arsip SK (Document model)
        foreach ($employee->documents as $doc) {
            /** @var Document $doc */
            if ($doc->file_path) {
                $paths[] = $doc->file_path;
            }
        }

        // SK Pengangkatan (Appointment)
        foreach ($employee->appointments as $appt) {
            /** @var Appointment $appt */
            if ($appt->file_sk) {
                $paths[] = $appt->file_sk;
            }
        }

        // Deduplikasi path agar tidak hapus file yang sama dua kali
        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * Hapus file-file fisik dari disk 'public'.
     *
     * @param  list<string>  $paths
     */
    private function deleteFiles(array $paths): void
    {
        $disk = Storage::disk(Document::STORAGE_DISK);

        foreach ($paths as $path) {
            try {
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            } catch (\Throwable $e) {
                // File gagal dihapus tidak boleh membatalkan proses keseluruhan — log saja
                report($e);
            }
        }
    }
}
