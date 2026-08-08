<?php

namespace App\Support\Audit;

use App\Models\AuditLog;

/**
 * Bentuk data audit untuk permukaan tampilan.
 *
 * Dipisahkan dari controller supaya halaman daftar dan halaman detail memakai kontrak yang
 * sama, sehingga kolom yang ditampilkan tidak bergeser di antara keduanya.
 */
class AuditLogViewPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function forView(AuditLog $log): array
    {
        $module = class_basename($log->auditable_type);
        $recordId = $log->auditable_id
            ?? data_get($log->new_values, 'key')
            ?? data_get($log->old_values, 'key')
            ?? '-';

        return [
            'id' => $log->id,
            'timestamp' => $log->created_at?->format('Y-m-d H:i:s') ?? '-',
            'operator' => $log->user_name ?: 'Sistem',
            'event' => $log->event,
            'kategori' => self::categoryFor($module, $log->event),
            'modul' => $module,
            'record_id' => (string) $recordId,
            'ip_address' => $log->ip_address ?: '-',
            'user_agent' => $log->user_agent ?: '-',
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
        ];
    }

    private static function categoryFor(string $module, string $event): string
    {
        if (in_array($event, ['LOGIN', 'LOGOUT', 'SESSION_TIMEOUT'], true)) {
            return 'autentikasi';
        }

        return match ($module) {
            'Employee', 'RankHistory', 'PositionHistory', 'SalaryHistory',
            'DisciplineRecord', 'FamilyMember', 'Education' => 'data_pegawai',
            'LeaveRequest', 'LeaveApproval', 'LeaveBalance' => 'transaksi_cuti',
            'EwsConfig', 'Role', 'Permission', 'RefHariLibur', 'Setting' => 'konfigurasi_sistem',
            default => 'aktivitas_sistem',
        };
    }
}
