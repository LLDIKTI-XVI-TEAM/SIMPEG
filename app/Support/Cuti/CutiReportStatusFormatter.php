<?php

namespace App\Support\Cuti;

use App\Models\LeaveRequest;

class CutiReportStatusFormatter
{
    /**
     * Mengubah status internal menjadi label laporan resmi tanpa membuka detail keputusan approval.
     */
    public function format(LeaveRequest $leaveRequest): string
    {
        $official = [
            'disetujui' => 'Disetujui',
            'ditangguhkan' => 'Ditangguhkan',
            'ditangguhkan_tugas_dinas' => 'Ditangguhkan karena Tugas Dinas',
            LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER => 'Dikembalikan karena Rollover',
            'perlu_perubahan' => 'Perubahan',
            'tidak_disetujui' => 'Tidak Disetujui',
        ];

        if (isset($official[$leaveRequest->status])) {
            return $official[$leaveRequest->status];
        }

        $roleLabel = $leaveRequest->steps
            ->firstWhere('status', 'active')?->role_label ?? 'Approver';

        return 'Menunggu '.$roleLabel;
    }
}
