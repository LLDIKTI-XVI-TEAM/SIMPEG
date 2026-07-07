<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use App\Services\WorkdayCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mengirim ulang pengajuan yang dikembalikan untuk perubahan tanpa membuat snapshot approval baru.
 */
class ResubmitLeaveRequestAction
{
    public function __construct(
        private readonly WorkdayCalculator $workdayCalculator,
        private readonly EmployeeFileStorageService $files,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(LeaveRequest $leaveRequest, array $data, Request $request): LeaveRequest
    {
        $mulai = Carbon::createFromFormat('Y-m-d', (string) $data['tanggal_mulai'])->startOfDay();
        $selesai = Carbon::createFromFormat('Y-m-d', (string) $data['tanggal_selesai'])->startOfDay();
        $oldValues = $leaveRequest->only(['tanggal_mulai', 'tanggal_selesai', 'jumlah_hari_kerja', 'alasan', 'lampiran_path', 'status']);

        $updated = DB::transaction(function () use ($leaveRequest, $data, $request, $mulai, $selesai): LeaveRequest {
            $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();
            $lampiranPath = $locked->lampiran_path;

            if ($request->hasFile('lampiran')) {
                $lampiranPath = $this->files->storeLampiran($request->file('lampiran'));
            }

            $locked->forceFill([
                'tanggal_mulai' => $mulai->toDateString(),
                'tanggal_selesai' => $selesai->toDateString(),
                'jumlah_hari_kerja' => $this->workdayCalculator->calculate($mulai, $selesai),
                'alasan' => $data['alasan'],
                'lampiran_path' => $lampiranPath,
                'status' => 'menunggu_approval',
            ])->save();

            return $locked;
        });

        AuditService::log('UPDATE', 'LeaveRequest', $updated->id, $oldValues, $updated->toArray(), $request);

        return $updated;
    }
}
