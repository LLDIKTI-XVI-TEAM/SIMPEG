<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
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
        $oldLampiranPath = $leaveRequest->lampiran_path;
        $newLampiranPath = null;

        if ($request->hasFile('lampiran')) {
            $newLampiranPath = $this->files->storeLampiran($request->file('lampiran'));
        }

        try {
            $transactionResult = DB::transaction(function () use ($leaveRequest, $data, $mulai, $selesai, $newLampiranPath): array {
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
                $locked->forceFill([
                    'tanggal_mulai' => $mulai->toDateString(),
                    'tanggal_selesai' => $selesai->toDateString(),
                    'jumlah_hari_kerja' => $this->workdayCalculator->calculate($mulai, $selesai),
                    'alasan' => $data['alasan'],
                    'alamat_selama_cuti' => $data['alamat_selama_cuti'],
                    'nomor_telepon' => $data['nomor_telepon'],
                    'lampiran_path' => $newLampiranPath ?? $locked->lampiran_path,
                    'status' => 'menunggu_approval',
                ])->save();

                return ['leaveRequest' => $locked, 'oldValues' => $oldValues];
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
}
