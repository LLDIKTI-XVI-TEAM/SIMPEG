<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Cuti\AdministrativeLeavePostponementAccess;
use App\Services\Cuti\AnnualLeaveBusinessClock;
use App\Services\Cuti\LeaveUsageRecordService;
use App\Services\NotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Mengakhiri cuti approved sekali tanpa mengubah histori approval, bukti, atau reservasi final. */
final class RecordAdministrativeLeavePostponementAction
{
    public function __construct(
        private readonly AdministrativeLeavePostponementAccess $access,
        private readonly AnnualLeaveBusinessClock $businessClock,
        private readonly LeaveUsageRecordService $usage,
        private readonly NotificationService $notifications,
    ) {}

    /** Aktor eksplisit wajib berwenang juga untuk pemanggilan internal yang tidak melalui FormRequest. */
    public function execute(LeaveRequest $leaveRequest, User $actor, string $reason, ?Request $request = null): LeaveRequest
    {
        if ($request !== null && (! $request->user() instanceof User || $request->user()->id !== $actor->id)) {
            throw new AuthorizationException('Identitas pelaksana penangguhan administratif tidak sesuai.');
        }

        return DB::transaction(function () use ($leaveRequest, $actor, $reason, $request): LeaveRequest {
            // Request lebih dahulu, lalu employee: sama dengan approval dan seluruh penulisan saldo.
            $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();
            $locked->load('steps');
            $currentActor = User::query()->find($actor->id);
            if ($currentActor === null || ! $this->access->canManage($locked, $currentActor)) {
                throw new AuthorizationException('Anda tidak berwenang menangguhkan pengajuan cuti ini secara administratif.');
            }
            $employee = Employee::query()->whereKey($locked->employee_id)->lockForUpdate()->firstOrFail();
            // Penantian mutex tidak boleh mempertahankan permission atau role yang sudah dicabut.
            $currentActor->refresh();
            if (! $this->access->canManage($locked, $currentActor)) {
                throw new AuthorizationException('Anda tidak berwenang menangguhkan pengajuan cuti ini secara administratif.');
            }

            $validator = Validator::make(['alasan' => trim($reason)], ['alasan' => ['required', 'string', 'max:500']], [], ['alasan' => 'alasan penangguhan administratif']);
            if ($validator->fails()) {
                throw (new ValidationException($validator))->errorBag('administrativePostponement');
            }
            $reason = trim($reason);
            $this->assertFinalFutureRequest($locked);

            $decidedAt = $this->businessClock->now();
            $locked->forceFill([
                'status' => LeaveRequest::STATUS_ADMINISTRATIVELY_POSTPONED,
                // Kolom tanpa timezone mengikuti zona aplikasi saat Eloquent reload; instant keputusan tetap WITA pada audit.
                'administratively_postponed_at' => $decidedAt->copy()->setTimezone((string) config('app.timezone')),
                'administratively_postponed_by' => $currentActor->id,
                'administrative_postponement_reason' => $reason,
            ])->save();
            $fact = $this->usage->reverseApprovedRequest($locked, $currentActor, $request);

            // Alasan bebas hanya pada record keputusan dan audit khusus, bukan fact/ledger/notifikasi.
            AuditService::logAsOrFail(
                $currentActor->id, $currentActor->name, 'UPDATE', 'LeaveRequest', $locked->id,
                ['status' => 'disetujui'],
                [
                    'operation' => 'administrative_postponement', 'status' => $locked->status,
                    'employee_id' => $locked->employee_id, 'leave_usage_record_id' => $fact->id,
                    'administratively_postponed_by' => $currentActor->id,
                    'administratively_postponed_at' => $decidedAt->toIso8601String(),
                    'reason' => $reason, '_effective_role' => $currentActor->getEffectiveRole(),
                ],
                $request,
            );

            // Callback menunggu commit terluar, termasuk bila Action dipanggil dari transaksi lain.
            $requestId = $locked->id;
            DB::afterCommit(function () use ($employee, $requestId): void {
                try {
                    $this->notifications->createForEmployee(
                        $employee, 'cuti.ditangguhkan_administratif', 'Cuti Ditangguhkan Secara Administratif',
                        'Cuti Anda telah ditangguhkan secara administratif dan tidak lagi aktif. Silakan lihat detail pengajuan.',
                        ['leave_request_id' => $requestId, 'url' => route('cuti.show', ['id' => $requestId], false)],
                    );
                } catch (\Throwable $exception) {
                    // Keputusan telah committed; laporkan delivery gagal tanpa menyamarkannya sebagai mutasi gagal.
                    report($exception);
                }
            });

            return $locked;
        });
    }

    /** Final approved tidak boleh disamarkan oleh step aktif atau total reservasi lintas tahun yang saling menutup. */
    private function assertFinalFutureRequest(LeaveRequest $leaveRequest): void
    {
        if ($leaveRequest->status === LeaveRequest::STATUS_ADMINISTRATIVELY_POSTPONED) {
            $this->invalid('status', 'Cuti ini sudah ditangguhkan secara administratif.');
        }
        if ($leaveRequest->status !== 'disetujui') {
            $this->invalid('status', 'Pengajuan cuti ini sudah tidak dapat ditangguhkan secara administratif.');
        }
        if ($leaveRequest->tanggal_mulai === null || $leaveRequest->tanggal_mulai->toDateString() <= $this->businessClock->today()->toDateString()) {
            $this->invalid('tanggal_mulai', 'Penangguhan administratif hanya dapat dilakukan sebelum tanggal mulai cuti menurut waktu WITA.');
        }
        $steps = $leaveRequest->steps;
        if ($steps->isEmpty()
            || $steps->filter(fn (LeaveRequestStep $step): bool => $step->is_final)->count() !== 1
            || $steps->contains(fn (LeaveRequestStep $step): bool => ! in_array($step->status, ['approved', 'skipped'], true))
            || ! $steps->contains(fn (LeaveRequestStep $step): bool => $step->is_final && $step->status === 'approved')) {
            $this->invalid('status', 'Riwayat persetujuan final cuti belum lengkap. Silakan hubungi pengelola kepegawaian.');
        }
        $hasOutstandingReservation = $leaveRequest->balanceReservationEvents()
            ->select('tahun')->groupBy('tahun')->havingRaw('SUM(amount) <> 0')->exists();
        if ($hasOutstandingReservation) {
            $this->invalid('status', 'Reservasi saldo pada persetujuan final tidak sesuai. Silakan hubungi pengelola kepegawaian.');
        }
    }

    /** Semua error domain kembali ke form administratif, termasuk stale POST ketika tombol sudah hilang. */
    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message])->errorBag('administrativePostponement');
    }
}
