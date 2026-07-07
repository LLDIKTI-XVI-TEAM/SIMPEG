<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeavePybmcGlobalConfig;
use App\Models\User;
use App\Services\AuditService;

/**
 * Menyimpan PYBMC global yang menjadi final approver default untuk chain baru.
 * Riwayat lama tetap disimpan agar perubahan pejabat final bisa diaudit lintas waktu.
 */
class ApplyGlobalPybmcAction
{
    public function execute(Employee $approver, User $actor, string $reason): LeavePybmcGlobalConfig
    {
        $config = LeavePybmcGlobalConfig::create([
            'approver_employee_id' => $approver->id,
            'effective_from' => today(),
            'created_by' => $actor->id,
            'change_reason' => $reason,
        ]);

        AuditService::log(
            'CREATE',
            'LeavePybmcGlobalConfig',
            $config->id,
            null,
            [
                'approver_employee_id' => $approver->id,
                'approver_name' => $approver->nama_lengkap,
                'reason' => $reason,
            ],
        );

        return $config;
    }
}
