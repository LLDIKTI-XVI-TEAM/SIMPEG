<?php

namespace App\Actions\Employees;

use App\Models\Document;
use App\Models\Employee;
use App\Models\RefStatusPegawai;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya jalur untuk mengubah status kepegawaian (Status Pegawai).
 *
 * Sistemnya replace/timpa: tidak ada riwayat historis. Setiap eksekusi menimpa
 * detail status yang tersimpan pada Employee. Dokumen SK (tabel `documents`)
 * hanya ada satu per pegawai untuk kategori ini — bila admin melampirkan berkas
 * baru, dokumen dan file lama otomatis dihapus (database + storage) lalu diganti
 * dengan yang baru. Tanpa berkas baru, dokumen dan file SK lama tetap dihapus
 * karena detail status sepenuhnya ditimpa oleh input saat ini.
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

        [$employee, $oldBerkasPath] = DB::transaction(function () use ($employee, $status, $data, $request, $berkas): array {
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $oldValues = $employee->getRawOriginal();
            $oldBerkasPath = $employee->status_berkas_path;

            // Dokumen SK status sebelumnya (jika ada) selalu ditimpa — baik diganti dokumen baru
            // maupun dihapus total ketika perubahan status kali ini tidak melampirkan berkas.
            Document::where('employee_id', $employee->id)
                ->where('jenis_dokumen', 'sk_status_pegawai')
                ->delete();

            $berkasPath = null;
            $nomorBerkas = null;

            if ($berkas !== null) {
                $berkasPath = $this->files->storeBerkasLainnya($berkas, $employee->id);
                $nomorBerkas = $this->generateNomorBerkas($status);

                Document::create([
                    'employee_id' => $employee->id,
                    'jenis_dokumen' => 'sk_status_pegawai',
                    'nama_dokumen' => 'SK Perubahan Status — '.$status->nama,
                    'nomor_dokumen' => $nomorBerkas,
                    'tanggal_dokumen' => $data['tanggal'],
                    'file_path' => $berkasPath,
                    'keterangan' => $data['deskripsi'] ?? null,
                ]);
            }

            $employee->update([
                'status_pegawai_id' => $status->id,
                'status_aktif' => $status->nama,
                'status_alasan' => $data['alasan'],
                'status_deskripsi' => $data['deskripsi'] ?? null,
                'status_tanggal' => $data['tanggal'],
                'status_berkas_path' => $berkasPath,
                'status_nomor_berkas' => $nomorBerkas,
            ]);

            $employee->refresh();
            AuditService::log('UPDATE', 'Employee', $employee->id, $oldValues, $employee->getAttributes(), $request);

            return [$employee, $oldBerkasPath];
        });

        // File lama dihapus dari storage setelah transaksi database berhasil, supaya
        // kegagalan transaksi tidak meninggalkan file baru tanpa record atau menghapus
        // file lama yang masih dibutuhkan bila proses di atas gagal.
        if ($oldBerkasPath !== null && $oldBerkasPath !== $employee->status_berkas_path) {
            $this->files->deletePublicFile($oldBerkasPath);
        }

        // Notifikasi bersifat fire-and-forget setelah transaksi berhasil, agar kegagalan
        // pengiriman notifikasi tidak membatalkan perubahan status yang sudah tersimpan.
        $this->notifications->createForEmployee(
            $employee,
            'status_pegawai.diubah',
            'Status Kepegawaian Anda Diperbarui',
            'Status kepegawaian Anda telah diubah menjadi "'.$status->nama.'". Alasan: '.$data['alasan'],
            ['status_pegawai_id' => $status->id, 'url' => route('profil', [], false)],
        );

        return $employee;
    }

    private function generateNomorBerkas(RefStatusPegawai $status): string
    {
        $kode = $status->kode !== null && $status->kode !== '' ? $status->kode : 'STATUS';

        return 'SK-'.$kode.'-'.now()->format('Ymd').'-'.strtoupper(substr(uniqid(), -5));
    }
}
