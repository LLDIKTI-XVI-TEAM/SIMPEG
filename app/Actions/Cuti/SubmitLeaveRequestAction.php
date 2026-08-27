<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Cuti\ApprovalChainResolver;
use App\Services\Cuti\LeaveBalanceReservationService;
use App\Services\Cuti\LeaveEligibilityService;
use App\Services\Cuti\LeaveUsageOverlapService;
use App\Services\EmployeeFileStorageService;
use App\Services\NotificationService;
use App\Services\WorkdayCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        private readonly LeaveBalanceReservationService $reservations,
        private readonly LeaveEligibilityService $eligibility,
        private readonly LeaveUsageOverlapService $overlap,
    ) {}

    /**
     * Membuat pengajuan cuti untuk seorang pegawai.
     *
     * @param  array<string, mixed>  $data  Data tervalidasi: jenis_cuti_id, tanggal_mulai, tanggal_selesai, alasan
     */
    public function execute(Employee $employee, array $data, Request $request): LeaveRequest
    {
        // Cuti Kepala Lembaga diproses melalui kementerian, bukan lewat SIMPEG.
        // Guard ini fail-closed dan wajib berada sebelum perhitungan hari kerja maupun penyimpanan apa pun,
        // sehingga POST langsung tetap ditolak walau tampilan form sudah disembunyikan di sisi UI.
        if ($employee->is_kepala_lembaga) {
            throw ValidationException::withMessages([
                'jenis_cuti_id' => 'Pengajuan cuti Kepala Lembaga diproses melalui kementerian, bukan melalui SIMPEG.',
            ]);
        }

        $steps = $this->approvalChains->resolveEffectiveSteps($employee)
            // Pemohon tidak boleh menjadi approver pengajuannya sendiri; step konflik dihilangkan dari snapshot.
            ->reject(fn ($step) => $step->approver_employee_id === $employee->id)
            ->values();

        if ($steps->isEmpty()) {
            throw ValidationException::withMessages([
                'jenis_cuti_id' => 'Pengajuan cuti tidak dapat diproses karena tidak ada approver lain yang valid.',
            ]);
        }

        $mulai = Carbon::createFromFormat('Y-m-d', (string) $data['tanggal_mulai'])->startOfDay();
        $selesai = Carbon::createFromFormat('Y-m-d', (string) $data['tanggal_selesai'])->startOfDay();
        // Boundary Action menolak bypass FormRequest sebelum file maupun data pengajuan ditulis.
        $this->eligibility->assertSingleCalendarYear($mulai, $selesai);
        $leaveType = RefJenisCuti::query()->findOrFail($data['jenis_cuti_id']);

        // Hari kerja selalu dihitung ulang di server agar tidak bergantung pada nilai yang dikirim klien.
        $jumlahHariKerja = $this->workdayCalculator->calculate($mulai, $selesai);

        // Rentang tanpa hari kerja tidak boleh membentuk pengajuan, reservasi, maupun approval aktif.
        if ($jumlahHariKerja <= 0) {
            throw ValidationException::withMessages([
                'tanggal_selesai' => 'Rentang tanggal pengajuan tidak memiliki hari kerja. Pilih periode yang mencakup setidaknya satu hari kerja.',
            ]);
        }

        $lampiranPath = null;
        $storedLampiran = null;

        // Penyimpanan pengajuan dan notifikasi atasan dibungkus transaksi agar tidak ada pengajuan tersimpan
        // tanpa notifikasi pasangannya bila salah satu langkah gagal.
        try {
            $requestUser = $request->user();
            $actor = $requestUser instanceof User ? $requestUser : null;

            $leaveRequest = DB::transaction(function () use ($employee, $data, $mulai, $selesai, $jumlahHariKerja, &$lampiranPath, &$storedLampiran, $steps, $actor, $leaveType, $request) {
                $lockedEmployee = $this->overlap->lockEmployee($employee);
                $this->overlap->assertNoOverlap($lockedEmployee, $mulai, $selesai);

                if ($request->hasFile('lampiran')) {
                    $storedLampiran = $this->files->storeLampiran($request->file('lampiran'), $lockedEmployee->id);
                    $lampiranPath = $storedLampiran['path'];
                }

                // Kelayakan diulang di dalam transaksi dan rangkaian dikunci agar submit paralel
                // tidak melampaui batas kumulatif Melahirkan atau CLTN.
                [$leaveCase, $caseCreated] = $this->eligibility->resolveForNewSubmission(
                    $lockedEmployee,
                    $leaveType,
                    $mulai,
                    $selesai,
                    $data['leave_request_case_id'] ?? null,
                    $actor,
                );

                if ($caseCreated && $leaveCase !== null) {
                    // Keterkaitan baru harus menjadi bukti audit sebelum request
                    // disimpan. logOrFail membuat transaksi gagal tertutup bila
                    // jejak rangkaian tidak dapat dicatat.
                    AuditService::logOrFail(
                        'CREATE',
                        'LeaveRequestCase',
                        $leaveCase->id,
                        null,
                        [
                            'employee_id' => $lockedEmployee->id,
                            'jenis_cuti_id' => $leaveType->id,
                            'jenis_cuti_code' => $leaveType->code,
                            'tanggal_mulai_rangkaian' => $mulai->toDateString(),
                        ],
                        $request,
                    );
                }

                $leaveRequest = LeaveRequest::create([
                    'employee_id' => $lockedEmployee->id,
                    'jenis_cuti_id' => $data['jenis_cuti_id'],
                    'leave_request_case_id' => $leaveCase?->id,
                    'tanggal_mulai' => $mulai->toDateString(),
                    'tanggal_selesai' => $selesai->toDateString(),
                    'jumlah_hari_kerja' => $jumlahHariKerja,
                    'alasan' => $data['alasan'],
                    'alamat_selama_cuti' => $data['alamat_selama_cuti'],
                    'nomor_telepon' => $data['nomor_telepon'],
                    'lampiran_path' => $lampiranPath,
                    // Pengajuan baru selalu masuk engine snapshot dinamis; step aktif pertama disimpan di leave_request_steps.
                    'status' => 'menunggu_approval',
                ]);

                $latestOrderByApprover = $steps
                    ->groupBy('approver_employee_id')
                    ->map(fn ($approverSteps) => $approverSteps->max('step_order'));
                $firstActiveAssigned = false;

                foreach ($steps as $index => $step) {
                    $stepOrder = $index + 1;
                    $isEarlierDuplicate = $latestOrderByApprover[$step->approver_employee_id] !== $step->step_order;
                    $status = 'pending';

                    if ($isEarlierDuplicate) {
                        $status = 'skipped';
                    } elseif (! $firstActiveAssigned) {
                        $status = 'active';
                        $firstActiveAssigned = true;
                    }

                    $leaveRequest->steps()->create([
                        'step_order' => $stepOrder,
                        'step_type' => $step->step_type,
                        'role_label' => $step->role_label,
                        'approver_employee_id' => $step->approver_employee_id,
                        'status' => $status,
                        'is_final' => $index === $steps->count() - 1,
                        'skipped_reason' => $isEarlierDuplicate ? 'duplicate_approver' : null,
                        'decision_note' => $isEarlierDuplicate ? 'Dilewati otomatis karena approver muncul lagi pada step otoritas lebih akhir.' : null,
                    ]);
                }

                // Reservasi ditulis sebelum notifikasi. Bila saldo aktif tidak cukup, seluruh
                // pengajuan dan snapshot step ikut rollback sehingga tidak ada request setengah jadi.
                $this->reservations->reserveForNewRequest($leaveRequest, $actor);

                $this->notifyActiveApprover($leaveRequest);

                // Audit adalah bagian dari konsistensi pengajuan: kegagalan jejak harus
                // membatalkan request, step, reservasi, dan notifikasi dalam transaksi yang sama.
                AuditService::logOrFail(
                    'CREATE',
                    'LeaveRequest',
                    $leaveRequest->id,
                    null,
                    $this->sanitizedAuditValues($leaveRequest),
                    $request,
                );

                return $leaveRequest;
            });
        } catch (\Throwable $exception) {
            // File berada di luar transaksi DB; hapus lampiran baru bila persistensi berikutnya gagal.
            $this->files->deleteLeaveAttachment($lampiranPath, $employee->id);

            throw $exception;
        }

        if ($storedLampiran !== null) {
            $this->files->adoptLeaveAttachment(
                $storedLampiran['recovery_task_id'],
                $employee->id,
                $storedLampiran['path'],
            );
        }

        return $leaveRequest;
    }

    /** Menyimpan hanya indikator kontak/lampiran pada audit agar PII dan path privat tidak keluar dari batas domain. */
    private function sanitizedAuditValues(LeaveRequest $leaveRequest): array
    {
        return [
            'employee_id' => $leaveRequest->employee_id,
            'jenis_cuti_id' => $leaveRequest->jenis_cuti_id,
            'leave_request_case_id' => $leaveRequest->leave_request_case_id,
            'tanggal_mulai' => $leaveRequest->tanggal_mulai?->toDateString(),
            'tanggal_selesai' => $leaveRequest->tanggal_selesai?->toDateString(),
            'jumlah_hari_kerja' => $leaveRequest->jumlah_hari_kerja,
            'alasan' => $leaveRequest->alasan,
            'status' => $leaveRequest->status,
            'rollover_source_year' => $leaveRequest->rollover_source_year,
            'rollover_target_year' => $leaveRequest->rollover_target_year,
            'alamat_selama_cuti_diisi' => filled($leaveRequest->alamat_selama_cuti),
            'nomor_telepon_diisi' => filled($leaveRequest->nomor_telepon),
            'lampiran_diisi' => filled($leaveRequest->lampiran_path),
        ];
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
            // Approver diarahkan ke antrean approval; path relatif internal agar link aman dan tidak bergantung host.
            ['leave_request_id' => $leaveRequest->id, 'url' => route('cuti.approval', [], false)],
        );
    }
}
