<?php

namespace App\Support\Cuti;

use App\Data\Cuti\CutiRekapReadRow;
use App\Models\LeaveRequest;

class CutiReportStatusFormatter
{
    /**
     * Mengubah status internal menjadi label laporan resmi tanpa membuka detail keputusan approval.
     */
    public function format(string $status, ?string $currentStepLabel, string $sourceType): string
    {
        if ($sourceType === CutiRekapReadRow::SOURCE_MANUAL_EXTERNAL) {
            return 'Disetujui di luar SIMPEG';
        }

        $official = [
            'disetujui' => 'Disetujui melalui SIMPEG',
            'ditangguhkan' => 'Ditangguhkan',
            'ditangguhkan_tugas_dinas' => 'Ditangguhkan karena Tugas Dinas',
            LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER => 'Dikembalikan karena Rollover',
            'perlu_perubahan' => 'Perubahan',
            'tidak_disetujui' => 'Tidak Disetujui',
        ];

        if (isset($official[$status])) {
            return $official[$status];
        }

        return 'Menunggu '.($currentStepLabel ?? 'Approver');
    }

    /**
     * Label sumber tetap terpisah dari status agar keputusan dan asal pencatatan tidak tercampur.
     */
    public function sourceLabel(string $sourceType): string
    {
        return $sourceType === CutiRekapReadRow::SOURCE_MANUAL_EXTERNAL
            ? 'Di luar SIMPEG'
            : 'Melalui SIMPEG';
    }
}
