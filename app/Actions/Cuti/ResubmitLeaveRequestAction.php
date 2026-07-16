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

        /** @var array{leaveRequest: LeaveRequest, oldValues: array<string, mixed>} $transactionResult */
        $transactionResult = DB::transaction(function () use ($leaveRequest, $data, $request, $mulai, $selesai): array {
            $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();
            $oldValues = $locked->only([
                'tanggal_mulai',
                'tanggal_selesai',
                'jumlah_hari_kerja',
                'alasan',
                'alamat_selama_cuti',
                'nomor_telepon',
                'lampiran_path',
                'status',
            ]);
            $lampiranPath = $locked->lampiran_path;

            if ($request->hasFile('lampiran')) {
                $lampiranPath = $this->files->storeLampiran($request->file('lampiran'));
            }

            $locked->forceFill([
                'tanggal_mulai' => $mulai->toDateString(),
                'tanggal_selesai' => $selesai->toDateString(),
                'jumlah_hari_kerja' => $this->workdayCalculator->calculate($mulai, $selesai),
                'alasan' => $data['alasan'],
                'alamat_selama_cuti' => $data['alamat_selama_cuti'],
                'nomor_telepon' => $data['nomor_telepon'],
                'lampiran_path' => $lampiranPath,
                'status' => 'menunggu_approval',
            ])->save();

            return ['leaveRequest' => $locked, 'oldValues' => $oldValues];
        });
        $updated = $transactionResult['leaveRequest'];
        $oldValues = $transactionResult['oldValues'];

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
}
