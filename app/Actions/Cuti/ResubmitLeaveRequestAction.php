<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AuditService;
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
 * Memperbarui pengajuan sebelum tindakan approver atau setelah pengembalian rollover.
 */
class ResubmitLeaveRequestAction
{
    public function __construct(
        private readonly WorkdayCalculator $workdayCalculator,
        private readonly EmployeeFileStorageService $files,
        private readonly LeaveBalanceReservationService $reservations,
        private readonly LeaveEligibilityService $eligibility,
        private readonly NotificationService $notifications,
        private readonly LeaveUsageOverlapService $overlap,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(LeaveRequest $leaveRequest, array $data, Request $request): LeaveRequest
    {
        // Status Kepala Lembaga dapat berubah setelah submit awal; resubmit tetap wajib ditolak sebelum mutasi atau file ditulis.
        if ($leaveRequest->employee()->value('is_kepala_lembaga')) {
            throw ValidationException::withMessages([
                'jenis_cuti_id' => 'Pengajuan cuti Kepala Lembaga diproses melalui kementerian, bukan melalui SIMPEG.',
            ]);
        }

        $mulai = Carbon::createFromFormat('Y-m-d', (string) $data['tanggal_mulai'])->startOfDay();
        $selesai = Carbon::createFromFormat('Y-m-d', (string) $data['tanggal_selesai'])->startOfDay();
        // Boundary Action menolak bypass FormRequest sebelum file maupun data pengajuan ditulis.
        $this->eligibility->assertSingleCalendarYear($mulai, $selesai);
        $this->assertResubmissionStatusAndTargetYear($leaveRequest, $mulai);
        $newWorkdays = $this->workdayCalculator->calculate($mulai, $selesai);

        // Rentang tanpa hari kerja tidak boleh mengubah request yang dikembalikan atau mengganti lampirannya.
        if ($newWorkdays <= 0) {
            throw ValidationException::withMessages([
                'tanggal_selesai' => 'Rentang tanggal pengajuan tidak memiliki hari kerja. Pilih periode yang mencakup setidaknya satu hari kerja.',
            ]);
        }

        $newLampiranPath = null;
        $storedLampiran = null;

        try {
            $requestUser = $request->user();
            $actor = $requestUser instanceof User ? $requestUser : null;

            $transactionResult = DB::transaction(function () use ($leaveRequest, $data, $mulai, $selesai, $newWorkdays, &$newLampiranPath, &$storedLampiran, $actor, $request): array {
                $locked = LeaveRequest::query()
                    ->whereKey($leaveRequest->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $employee = $this->overlap->lockEmployee($locked->employee_id);

                $isRolloverReturn = $locked->status === LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER;
                $this->assertResubmissionStatusAndTargetYear($locked, $mulai);
                $this->assertExpectedRevisionVersion($locked, (int) $data['revision_version']);
                $this->overlap->assertNoOverlap($employee, $mulai, $selesai, null, $locked->id);

                if ($request->hasFile('lampiran')) {
                    $storedLampiran = $this->files->storeLampiran($request->file('lampiran'), $employee->id);
                    $newLampiranPath = $storedLampiran['path'];
                }

                // Pemeriksaan FormRequest hanya memberi umpan balik awal. Rangkaian
                // dikunci dan dihitung kembali di sini agar resubmit paralel tidak
                // dapat menggeser periode melewati batas kalender yang sama.
                $this->eligibility->assertResubmissionAllowed($locked, $employee, $mulai, $selesai);

                $oldValues = $locked->only([
                    'employee_id',
                    'jenis_cuti_id',
                    'leave_request_case_id',
                    'tanggal_mulai',
                    'tanggal_selesai',
                    'jumlah_hari_kerja',
                    'alasan',
                    'alamat_selama_cuti',
                    'nomor_telepon',
                    'lampiran_path',
                    'status',
                    'revision_version',
                    'rollover_source_year',
                    'rollover_target_year',
                ]);
                $cleanupTask = null;
                if ($newLampiranPath !== null
                    && $newLampiranPath !== ($oldValues['lampiran_path'] ?? null)) {
                    // Task dibuat sebelum save dan dari row terkunci agar stale model maupun file bersama tetap aman.
                    $cleanupTask = $this->files->scheduleReplacedLeaveAttachment(
                        is_string($oldValues['lampiran_path'] ?? null) ? $oldValues['lampiran_path'] : null,
                        (string) $locked->employee_id,
                    );
                }
                $locked->forceFill([
                    'tanggal_mulai' => $mulai->toDateString(),
                    'tanggal_selesai' => $selesai->toDateString(),
                    'jumlah_hari_kerja' => $newWorkdays,
                    'alasan' => $data['alasan'],
                    'alamat_selama_cuti' => $data['alamat_selama_cuti'],
                    'nomor_telepon' => $data['nomor_telepon'],
                    'lampiran_path' => $newLampiranPath ?? $locked->lampiran_path,
                    'status' => 'menunggu_approval',
                    'revision_version' => $locked->revision_version + 1,
                    'rollover_source_year' => null,
                    'rollover_target_year' => null,
                ])->save();

                $this->reservations->adjustForResubmission($locked, $mulai, $newWorkdays, $actor);

                // Setiap resubmit menghasilkan data tindakan baru untuk approver aktif.
                // Siklus sebelumnya tidak boleh dipakai kembali setelah pemohon memperbaiki pengajuan.
                $this->notifyActiveApprover($locked, $isRolloverReturn);

                $changedFlags = [
                    'alamat_selama_cuti_diubah' => ($oldValues['alamat_selama_cuti'] ?? null) !== $locked->alamat_selama_cuti,
                    'nomor_telepon_diubah' => ($oldValues['nomor_telepon'] ?? null) !== $locked->nomor_telepon,
                    'lampiran_diubah' => ($oldValues['lampiran_path'] ?? null) !== $locked->lampiran_path,
                ];
                $newValues = $locked->only(array_keys($oldValues));

                AuditService::logOrFail(
                    'UPDATE',
                    'LeaveRequest',
                    $locked->id,
                    $this->sanitizedAuditValues($oldValues, $changedFlags),
                    $this->sanitizedAuditValues($newValues, $changedFlags),
                    $request,
                );

                return [
                    'leaveRequest' => $locked,
                    'oldValues' => $oldValues,
                    'cleanupTaskId' => $cleanupTask?->id,
                ];
            });
        } catch (\Throwable $exception) {
            $this->files->deleteLeaveAttachment($newLampiranPath, $leaveRequest->employee_id);

            throw $exception;
        }

        $updated = $transactionResult['leaveRequest'];
        if ($storedLampiran !== null) {
            $this->files->adoptLeaveAttachment(
                $storedLampiran['recovery_task_id'],
                $leaveRequest->employee_id,
                $storedLampiran['path'],
            );
        }
        // Processor menunggu commit transaksi request terluar; tanpa scope middleware,
        // transaksi domain sudah selesai dan task langsung dicoba secara sinkron.
        $this->files->attemptRecoveryTask($transactionResult['cleanupTaskId']);

        return $updated;
    }

    /**
     * Membentuk snapshot audit UPDATE yang simetris tanpa menyimpan kontak mentah atau path berkas privat.
     * Indikator perubahan dihitung sekali lalu dipakai pada kedua sisi agar diff audit tidak ambigu.
     *
     * @param  array<string, mixed>  $values
     * @param  array{alamat_selama_cuti_diubah: bool, nomor_telepon_diubah: bool, lampiran_diubah: bool}  $changedFlags
     * @return array<string, mixed>
     */
    private function sanitizedAuditValues(array $values, array $changedFlags): array
    {
        return [
            'employee_id' => $values['employee_id'] ?? null,
            'jenis_cuti_id' => $values['jenis_cuti_id'] ?? null,
            'leave_request_case_id' => $values['leave_request_case_id'] ?? null,
            'tanggal_mulai' => $values['tanggal_mulai'] ?? null,
            'tanggal_selesai' => $values['tanggal_selesai'] ?? null,
            'jumlah_hari_kerja' => $values['jumlah_hari_kerja'] ?? null,
            'alasan' => $values['alasan'] ?? null,
            'status' => $values['status'] ?? null,
            'revision_version' => $values['revision_version'] ?? null,
            'rollover_source_year' => $values['rollover_source_year'] ?? null,
            'rollover_target_year' => $values['rollover_target_year'] ?? null,
            'alamat_selama_cuti_diisi' => filled($values['alamat_selama_cuti'] ?? null),
            'nomor_telepon_diisi' => filled($values['nomor_telepon'] ?? null),
            'lampiran_diisi' => filled($values['lampiran_path'] ?? null),
            ...$changedFlags,
        ];
    }

    /** Mengirim ulang notifikasi kepada approver yang sama pada snapshot langkah aktif. */
    private function notifyActiveApprover(LeaveRequest $leaveRequest, bool $isRolloverReturn): void
    {
        $activeStep = $leaveRequest->steps()
            ->with('approver')
            ->where('status', 'active')
            ->orderBy('step_order')
            ->first();
        $approver = $activeStep?->approver;

        if ($approver === null) {
            return;
        }

        $this->notifications->createForEmployee(
            $approver,
            'cuti.pengajuan_baru',
            'Pengajuan Cuti Menunggu Persetujuan',
            "{$leaveRequest->employee?->nama_lengkap} memperbarui pengajuan cuti yang menunggu persetujuan Anda.",
            [
                'leave_request_id' => $leaveRequest->id,
                'leave_request_step_id' => $activeStep->id,
                'leave_request_version' => (string) $leaveRequest->revision_version,
                // Counter revisi membedakan delivery meski beberapa resubmit terjadi pada detik yang sama.
                'notification_cycle_id' => sprintf(
                    '%s-resubmit:%s:%s',
                    $isRolloverReturn ? 'rollover' : 'revision',
                    $leaveRequest->id,
                    $leaveRequest->revision_version,
                ),
                'url' => route('cuti.approval', [], false),
            ],
        );
    }

    /** Menjaga bypass FormRequest tidak dapat menulis file atau data di luar lifecycle resubmit. */
    private function assertResubmissionStatusAndTargetYear(LeaveRequest $leaveRequest, Carbon $startDate): void
    {
        $isInitialRevision = $leaveRequest->status === 'menunggu_approval'
            && $leaveRequest->approvals()->doesntExist();

        if (! $isInitialRevision && $leaveRequest->status !== LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER) {
            throw ValidationException::withMessages([
                'status' => 'Pengajuan cuti ini tidak dapat dikirim ulang.',
            ]);
        }

        if ($leaveRequest->status === LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER
            && ($leaveRequest->rollover_target_year === null || $startDate->year !== $leaveRequest->rollover_target_year)) {
            throw ValidationException::withMessages([
                'tanggal_mulai' => "Pengajuan yang dikembalikan saat rollover wajib diajukan pada tahun {$leaveRequest->rollover_target_year}.",
            ]);
        }
    }

    /** Menolak formulir revisi lama setelah request berubah, sebelum file baru ditulis. */
    private function assertExpectedRevisionVersion(LeaveRequest $leaveRequest, int $expectedRevisionVersion): void
    {
        if ($leaveRequest->revision_version !== $expectedRevisionVersion) {
            throw ValidationException::withMessages([
                'revision_version' => 'Pengajuan cuti telah diperbarui. Muat ulang halaman sebelum menyimpan revisi.',
            ]);
        }
    }
}
