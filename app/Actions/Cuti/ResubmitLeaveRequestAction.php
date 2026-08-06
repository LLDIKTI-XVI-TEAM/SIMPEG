<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Cuti\LeaveBalanceReservationService;
use App\Services\Cuti\LeaveEligibilityService;
use App\Services\EmployeeFileStorageService;
use App\Services\NotificationService;
use App\Services\WorkdayCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mengirim ulang pengajuan yang dikembalikan untuk perubahan tanpa membuat snapshot approval baru.
 */
class ResubmitLeaveRequestAction
{
    public function __construct(
        private readonly WorkdayCalculator $workdayCalculator,
        private readonly EmployeeFileStorageService $files,
        private readonly LeaveBalanceReservationService $reservations,
        private readonly LeaveEligibilityService $eligibility,
        private readonly NotificationService $notifications,
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
        $oldLampiranPath = $leaveRequest->lampiran_path;
        $newLampiranPath = null;

        if ($request->hasFile('lampiran')) {
            $newLampiranPath = $this->files->storeLampiran($request->file('lampiran'));
        }

        try {
            $requestUser = $request->user();
            $actor = $requestUser instanceof User ? $requestUser : null;

            $transactionResult = DB::transaction(function () use ($leaveRequest, $data, $mulai, $selesai, $newLampiranPath, $actor): array {
                $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();
                $employee = $locked->employee()->firstOrFail();

                $isRolloverReturn = $locked->status === LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER;
                $this->assertResubmissionStatusAndTargetYear($locked, $mulai);

                // Pemeriksaan FormRequest hanya memberi umpan balik awal. Rangkaian
                // dikunci dan dihitung kembali di sini agar resubmit paralel tidak
                // dapat menggeser periode melewati batas kalender yang sama.
                $this->eligibility->assertResubmissionAllowed($locked, $employee, $mulai, $selesai);

                $oldValues = $locked->only([
                    'tanggal_mulai',
                    'tanggal_selesai',
                    'jumlah_hari_kerja',
                    'alasan',
                    'alamat_selama_cuti',
                    'nomor_telepon',
                    'lampiran_path',
                    'status',
                    'rollover_source_year',
                    'rollover_target_year',
                ]);
                $newWorkdays = $this->workdayCalculator->calculate($mulai, $selesai);

                $locked->forceFill([
                    'tanggal_mulai' => $mulai->toDateString(),
                    'tanggal_selesai' => $selesai->toDateString(),
                    'jumlah_hari_kerja' => $newWorkdays,
                    'alasan' => $data['alasan'],
                    'alamat_selama_cuti' => $data['alamat_selama_cuti'],
                    'nomor_telepon' => $data['nomor_telepon'],
                    'lampiran_path' => $newLampiranPath ?? $locked->lampiran_path,
                    'status' => 'menunggu_approval',
                    'rollover_source_year' => null,
                    'rollover_target_year' => null,
                ])->save();

                $this->reservations->adjustForResubmission($locked, $mulai, $newWorkdays, $actor);

                if ($isRolloverReturn) {
                    // Notifikasi approver adalah bagian transaksi resubmit agar status dan reservasi tidak berubah sendiri.
                    $this->notifyActiveApprover($locked);
                }

                return [
                    'leaveRequest' => $locked,
                    'oldValues' => $oldValues,
                ];
            });
        } catch (\Throwable $exception) {
            $this->files->deletePublicFile($newLampiranPath);

            throw $exception;
        }

        $updated = $transactionResult['leaveRequest'];
        $oldValues = $transactionResult['oldValues'];

        // File lama baru dihapus setelah commit berhasil agar rollback selalu menyisakan path yang masih valid.
        if ($newLampiranPath !== null && $newLampiranPath !== $oldLampiranPath) {
            $this->files->deletePublicFile($oldLampiranPath);
        }

        // Audit mencatat perubahan kontak sebagai penanda boolean tanpa menyimpan nilai kontak yang bersifat PII.
        $alamatDiubah = $oldValues['alamat_selama_cuti'] !== $updated->alamat_selama_cuti;
        $nomorTeleponDiubah = $oldValues['nomor_telepon'] !== $updated->nomor_telepon;
        unset($oldValues['alamat_selama_cuti'], $oldValues['nomor_telepon']);
        $oldValues['alamat_selama_cuti_diubah'] = $alamatDiubah;
        $oldValues['nomor_telepon_diubah'] = $nomorTeleponDiubah;

        $newValues = $updated->toArray();
        unset($newValues['alamat_selama_cuti'], $newValues['nomor_telepon']);
        $newValues['alamat_selama_cuti_diubah'] = $alamatDiubah;
        $newValues['nomor_telepon_diubah'] = $nomorTeleponDiubah;

        AuditService::log('UPDATE', 'LeaveRequest', $updated->id, $oldValues, $newValues, $request);

        return $updated;
    }

    /** Mengirim ulang notifikasi kepada approver yang sama pada snapshot langkah aktif. */
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
            "{$leaveRequest->employee?->nama_lengkap} mengajukan ulang cuti setelah rollover dan menunggu persetujuan Anda.",
            ['leave_request_id' => $leaveRequest->id, 'url' => route('cuti.approval', [], false)],
        );
    }

    /** Menjaga bypass FormRequest tidak dapat menulis file atau data di luar lifecycle resubmit. */
    private function assertResubmissionStatusAndTargetYear(LeaveRequest $leaveRequest, Carbon $startDate): void
    {
        if (! in_array($leaveRequest->status, ['perlu_perubahan', LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER], true)) {
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
}
