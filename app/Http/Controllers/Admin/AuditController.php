<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;

class AuditController extends Controller
{
    public function index()
    {

        $auditLogs = AuditLog::query()
            ->latest('created_at')
            ->get()
            ->map(fn (AuditLog $log): array => $this->mapForView($log))
            ->values()
            ->all();

        return view('admin.audit.index', compact('auditLogs'));
    }

    public function show(string $id)
    {

        $log = $this->mapForView(AuditLog::query()->findOrFail($id));

        return view('admin.audit.show', compact('log'));
    }

    /**
     * Menjaga kontrak data lama yang dipakai Blade/Alpine sambil mengambil
     * sumber datanya dari tabel audit_logs.
     */
    private function mapForView(AuditLog $log): array
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
            'kategori' => $this->categoryFor($module, $log->event),
            'modul' => $module,
            'record_id' => (string) $recordId,
            'ip_address' => $log->ip_address ?: '-',
            'user_agent' => $log->user_agent ?: '-',
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
        ];
    }

    private function categoryFor(string $module, string $event): string
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
