<?php

namespace App\Actions\Employees;

use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\RefStatusPegawai;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Satu-satunya jalur untuk mengubah status kepegawaian (Status Pegawai).
 *
 * Sistemnya append/history: setiap perubahan status membuat record baru di
 * employee_status_histories dengan is_latest flag. Semua file SK disimpan
 * dan tidak pernah dihapus. Field employee.status_* tetap di-sync dengan
 * status terkini untuk backward compatibility.
 */
class ChangeEmployeeStatusAction
{
    public function __construct(
        private readonly EmployeeFileStorageService $files,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array{status_pegawai_id: string, alasan: string, deskripsi: ?string, tanggal: string}  $data
     */
    public function execute(Employee $employee, array $data, Request $request, ?UploadedFile $berkas = null): Employee
    {
        $status = RefStatusPegawai::findOrFail($data['status_pegawai_id']);

        $employee = DB::transaction(function () use ($employee, $status, $data, $request, $berkas): Employee {
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $oldValues = $employee->getRawOriginal();

            // Mark semua history record lama sebagai not latest
            EmployeeStatusHistory::where('employee_id', $employee->id)
                ->where('is_latest', true)
                ->update(['is_latest' => false]);

            $filePath = null;
            $nomorBerkas = null;

            if ($berkas !== null) {
                // Store file dengan naming pattern: {employee_id}/sk_status_pegawai/{employee_id}_sk_status_pegawai_{uuid}.{ext}
                $extension = $berkas->getClientOriginalExtension();
                $filename = $employee->id.'_sk_status_pegawai_'.Str::uuid().'.'.$extension;
                $filePath = $berkas->storeAs(
                    $employee->id.'/sk_status_pegawai',
                    $filename,
                    'public'
                );
                $nomorBerkas = $this->generateNomorBerkas($status);

                // Create Document record agar muncul di Arsip Dokumen
                Document::create([
                    'employee_id' => $employee->id,
                    'jenis_dokumen' => 'sk_status_pegawai',
                    'nama_dokumen' => 'SK Perubahan Status — '.$status->nama,
                    'nomor_dokumen' => $nomorBerkas,
                    'tanggal_dokumen' => $data['tanggal'],
                    'file_path' => $filePath,
                    'keterangan' => $data['deskripsi'] ?? null,
                ]);
            }

            // Create new history record dengan is_latest = true
            EmployeeStatusHistory::create([
                'employee_id' => $employee->id,
                'status_pegawai_id' => $status->id,
                'status_nama' => $status->nama,
                'alasan' => $data['alasan'],
                'deskripsi' => $data['deskripsi'] ?? null,
                'tanggal_efektif' => $data['tanggal'],
                'nomor_berkas' => $nomorBerkas,
                'file_sk' => $filePath,
                'changed_by_user_id' => auth()->id(),
                'is_latest' => true,
            ]);

            // Sync employee snapshot fields untuk backward compatibility
            $employee->update([
                'status_pegawai_id' => $status->id,
                'status_aktif' => $status->nama,
                'status_alasan' => $data['alasan'],
                'status_deskripsi' => $data['deskripsi'] ?? null,
                'status_tanggal' => $data['tanggal'],
                'status_berkas_path' => $filePath,
                'status_nomor_berkas' => $nomorBerkas,
            ]);

            $employee->refresh();
            AuditService::log('UPDATE', 'Employee', $employee->id, $oldValues, $employee->getAttributes(), $request);

            return $employee;
        });

        // Notifikasi bersifat fire-and-forget setelah transaksi berhasil, agar kegagalan
        // pengiriman notifikasi tidak membatalkan perubahan status yang sudah tersimpan.
        try {
            $this->notifications->createForEmployee(
                $employee,
                'status_pegawai.diubah',
                'Status Kepegawaian Anda Diperbarui',
                'Status kepegawaian Anda telah diubah menjadi "'.$status->nama.'". Alasan: '.$data['alasan'],
                ['status_pegawai_id' => $status->id, 'url' => route('profil', [], false)],
            );
        } catch (\Throwable $e) {
            Log::error('Notification failed after status change', [
                'employee_id' => $employee->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $employee;
    }

    private function generateNomorBerkas(RefStatusPegawai $status): string
    {
        $kode = $status->kode !== null && $status->kode !== '' ? $status->kode : 'STATUS';

        return 'SK-'.$kode.'-'.now()->format('Ymd').'-'.strtoupper(substr(uniqid(), -5));
    }
}
