<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\AuditService;
use App\Services\Cuti\ApprovalChainResolver;
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
        private readonly ApprovalChainResolver $approvalChains,
    ) {}

    /**
     * Membuat pengajuan cuti untuk seorang pegawai.
     *
     * @param  array<string, mixed>  $data  Data tervalidasi: jenis_cuti_id, tanggal_mulai, tanggal_selesai, alasan
     */
    public function execute(Employee $employee, array $data, Request $request): LeaveRequest
    {
        $mulai = Carbon::createFromFormat('Y-m-d', (string) $data['tanggal_mulai'])->startOfDay();
        $selesai = Carbon::createFromFormat('Y-m-d', (string) $data['tanggal_selesai'])->startOfDay();

        // Hari kerja selalu dihitung ulang di server agar tidak bergantung pada nilai yang dikirim klien.
        $jumlahHariKerja = $this->workdayCalculator->calculate($mulai, $selesai);

        $lampiranPath = null;
        if ($request->hasFile('lampiran')) {
            $lampiranPath = $this->files->storeLampiran($request->file('lampiran'));
        }

        $steps = $this->approvalChains->resolveEffectiveSteps($employee);

        // Penyimpanan pengajuan dan notifikasi atasan dibungkus transaksi agar tidak ada pengajuan tersimpan
        // tanpa notifikasi pasangannya bila salah satu langkah gagal.
        $leaveRequest = DB::transaction(function () use ($employee, $data, $mulai, $selesai, $jumlahHariKerja, $lampiranPath, $steps) {
            $leaveRequest = LeaveRequest::create([
                'employee_id' => $employee->id,
                'jenis_cuti_id' => $data['jenis_cuti_id'],
                'tanggal_mulai' => $mulai->toDateString(),
                'tanggal_selesai' => $selesai->toDateString(),
                'jumlah_hari_kerja' => $jumlahHariKerja,
                'alasan' => $data['alasan'],
                'lampiran_path' => $lampiranPath,
                // Pengajuan baru selalu masuk engine snapshot dinamis; step aktif pertama disimpan di leave_request_steps.
                'status' => 'menunggu_approval',
            ]);

            foreach ($steps as $index => $step) {
                $leaveRequest->steps()->create([
                    'step_order' => $index + 1,
                    'step_type' => $step->step_type,
                    'role_label' => $step->role_label,
                    'approver_employee_id' => $step->approver_employee_id,
                    'status' => $index === 0 ? 'active' : 'pending',
                    'is_final' => $step->is_final,
                ]);
            }

            $this->notifyActiveApprover($leaveRequest);

            return $leaveRequest;
        });

        // Audit bersifat fire-and-forget sehingga sengaja di luar transaksi agar kegagalan audit tidak membatalkan pengajuan.
        AuditService::log('CREATE', 'LeaveRequest', $leaveRequest->id, null, $leaveRequest->toArray(), $request);

        return $leaveRequest;
    }

    /**
     * Mengirim notifikasi in-app ke approver pada snapshot step aktif pertama.
     * Assignment dibaca dari snapshot agar perubahan konfigurasi setelah submit tidak mengubah penerima awal.
     */
    private function notifyActiveApprover(LeaveRequest $leaveRequest): void
    {
        $approver = $leaveRequest->steps()
            ->with('approver')
            ->where('status', 'active')
            ->orderBy('step_order')
            ->first()?->approver;

        if ($approver === null) {
            return;
        }

        $this->notifications->createForEmployee(
            $approver,
            'cuti.pengajuan_baru',
            'Pengajuan Cuti Menunggu Persetujuan',
            "{$leaveRequest->employee?->nama_lengkap} mengajukan cuti dan menunggu persetujuan Anda.",
            ['leave_request_id' => $leaveRequest->id],
        );
    }
}
