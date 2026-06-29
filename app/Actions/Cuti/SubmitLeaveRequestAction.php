<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use App\Services\NotificationService;
use App\Services\WorkdayCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mengoordinasikan pengajuan cuti oleh pegawai.
 * Action ini menjadi satu titik orkestrasi: hitung hari kerja di server, simpan pengajuan,
 * simpan lampiran (bila ada), beri tahu atasan langsung, dan catat audit.
 */
class SubmitLeaveRequestAction
{
    public function __construct(
        private readonly WorkdayCalculator $workdayCalculator,
        private readonly EmployeeFileStorageService $files,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Membuat pengajuan cuti untuk seorang pegawai.
     *
     * @param  array<string, mixed>  $data  Data tervalidasi: jenis_cuti_id, tanggal_mulai, tanggal_selesai, alasan
     */
    public function execute(Employee $employee, array $data, Request $request): LeaveRequest
    {
        $mulai = Carbon::createFromFormat('Y-m-d', (string) $data['tanggal_mulai']);
        $selesai = Carbon::createFromFormat('Y-m-d', (string) $data['tanggal_selesai']);

        // Hari kerja selalu dihitung ulang di server agar tidak bergantung pada nilai yang dikirim klien.
        $jumlahHariKerja = $this->workdayCalculator->calculate($mulai, $selesai);

        $lampiranPath = null;
        if ($request->hasFile('lampiran')) {
            $lampiranPath = $this->files->storeLampiran($request->file('lampiran'));
        }

        // Penyimpanan pengajuan dan notifikasi atasan dibungkus transaksi agar tidak ada pengajuan tersimpan
        // tanpa notifikasi pasangannya bila salah satu langkah gagal.
        $leaveRequest = DB::transaction(function () use ($employee, $data, $mulai, $selesai, $jumlahHariKerja, $lampiranPath) {
            $leaveRequest = LeaveRequest::create([
                'employee_id' => $employee->id,
                'jenis_cuti_id' => $data['jenis_cuti_id'],
                'tanggal_mulai' => $mulai->toDateString(),
                'tanggal_selesai' => $selesai->toDateString(),
                'jumlah_hari_kerja' => $jumlahHariKerja,
                'alasan' => $data['alasan'],
                'lampiran_path' => $lampiranPath,
                // Pengajuan baru langsung masuk antrean approval stage 1 (atasan langsung), bukan draft.
                'status' => 'Menunggu Atasan Langsung',
            ]);

            $this->notifySupervisor($employee, $leaveRequest);

            return $leaveRequest;
        });

        // Audit bersifat fire-and-forget sehingga sengaja di luar transaksi agar kegagalan audit tidak membatalkan pengajuan.
        AuditService::log('CREATE', 'LeaveRequest', $leaveRequest->id, null, $leaveRequest->toArray(), $request);

        return $leaveRequest;
    }

    /**
     * Mengirim notifikasi in-app ke atasan langsung aktif bahwa ada pengajuan cuti yang menunggu persetujuannya.
     * Atasan langsung dipastikan ada pada layer validasi, namun tetap dijaga defensif di sini.
     */
    private function notifySupervisor(Employee $employee, LeaveRequest $leaveRequest): void
    {
        $supervisor = $employee->currentSupervisor()?->supervisor;

        if ($supervisor === null) {
            return;
        }

        $this->notifications->createForEmployee(
            $supervisor,
            'cuti.pengajuan_baru',
            'Pengajuan Cuti Menunggu Persetujuan',
            "{$employee->nama_lengkap} mengajukan cuti dan menunggu persetujuan Anda.",
            ['leave_request_id' => $leaveRequest->id],
        );
    }
}
