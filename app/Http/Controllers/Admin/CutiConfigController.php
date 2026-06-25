<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ApprovalConfig;
use Illuminate\Http\Request;

class CutiConfigController extends Controller
{
    /**
     * Display the approval configuration page.
     */
    public function index()
    {
        // Enforce Super Admin authorization
        if (session('active_role') !== 'super_admin') {
            abort(403, 'Aksi tidak diizinkan. Halaman ini hanya untuk Super Admin.');
        }

        // Get all users who can potentially be approvers
        $eligibleUsers = User::whereIn('role', ['admin_kepegawaian', 'pimpinan', 'atasan_langsung'])->get();

        // Get current configuration values
        $stage2Id = ApprovalConfig::getVal('stage2_approver_id');
        $stage3Id = ApprovalConfig::getVal('stage3_approver_id');

        $stage2User = User::find($stage2Id);
        $stage3User = User::find($stage3Id);

        // Map session dynamic audit logs
        $auditRows = [];
        $dynamicLogs = session('dynamic_audit_logs', []);
        
        foreach ($dynamicLogs as $log) {
            if (isset($log['modul']) && $log['modul'] === 'ApprovalConfig') {
                $field = 'Unknown';
                if ($log['event'] === 'UPDATE_CONFIG_STAGE2') $field = 'Stage 2';
                if ($log['event'] === 'UPDATE_CONFIG_STAGE3') $field = 'Stage 3';
                if ($log['event'] === 'UPDATE_CONFIG_SKIP') $field = 'Skip duplikat';

                $auditRows[] = [
                    'time' => date('d Jun Y, H:i', strtotime($log['timestamp'])),
                    'actor' => $log['operator'],
                    'field' => $field,
                    'before' => $log['old_values']['value'] ?? 'Tidak ada',
                    'after' => $log['new_values']['value'] ?? 'Tidak ada',
                ];
            }
        }

        // Base/mock history as defined in the spec
        $baseLogs = [
            ['time' => '22 Jun 2026, 16:12', 'actor' => 'super_admin', 'field' => 'Stage 2',       'before' => 'Riza Hamzah',     'after' => 'Dra. Merlina Rahman'],
            ['time' => '20 Jun 2026, 09:40', 'actor' => 'super_admin', 'field' => 'Stage 3',       'before' => 'Dr. Abdul Kadir', 'after' => 'Dr. Abdul Kadir'],
            ['time' => '18 Jun 2026, 14:25', 'actor' => 'super_admin', 'field' => 'Skip duplikat', 'before' => 'Tidak aktif',     'after' => 'Aktif'],
        ];

        // Merge, dynamic logs first (most recent)
        $mergedAudit = array_merge($auditRows, $baseLogs);

        return view('admin.cuti.konfigurasi', [
            'eligibleUsers' => $eligibleUsers,
            'stage2Id' => $stage2Id,
            'stage3Id' => $stage3Id,
            'stage2User' => $stage2User,
            'stage3User' => $stage3User,
            'auditRows' => $mergedAudit,
        ]);
    }

    /**
     * Update the configuration.
     */
    public function update(Request $request)
    {
        // Enforce Super Admin authorization
        if (session('active_role') !== 'super_admin') {
            abort(403, 'Aksi tidak diizinkan. Halaman ini hanya untuk Super Admin.');
        }

        $request->validate([
            'stage2_approver_id' => 'required|exists:users,id',
            'stage3_approver_id' => 'required|exists:users,id',
            'reason' => 'required|string|min:5',
        ], [
            'stage2_approver_id.required' => 'Approver Stage 2 wajib dipilih.',
            'stage2_approver_id.exists' => 'Approver Stage 2 tidak valid.',
            'stage3_approver_id.required' => 'Approver Stage 3 wajib dipilih.',
            'stage3_approver_id.exists' => 'Approver Stage 3 tidak valid.',
            'reason.required' => 'Alasan perubahan wajib diisi.',
            'reason.min' => 'Alasan perubahan minimal berisi 5 karakter.',
        ]);

        $newStage2Id = $request->input('stage2_approver_id');
        $newStage3Id = $request->input('stage3_approver_id');
        $reason = $request->input('reason');

        $oldStage2Id = ApprovalConfig::getVal('stage2_approver_id');
        $oldStage3Id = ApprovalConfig::getVal('stage3_approver_id');

        $oldStage2User = User::find($oldStage2Id);
        $oldStage3User = User::find($oldStage3Id);
        $newStage2User = User::find($newStage2Id);
        $newStage3User = User::find($newStage3Id);

        $oldStage2Name = $oldStage2User ? $oldStage2User->name : 'Tidak ada';
        $oldStage3Name = $oldStage3User ? $oldStage3User->name : 'Tidak ada';
        $newStage2Name = $newStage2User ? $newStage2User->name : 'Tidak ada';
        $newStage3Name = $newStage3User ? $newStage3User->name : 'Tidak ada';

        $dynamicLogs = session('dynamic_audit_logs', []);
        $operator = auth()->user()->name ?? 'super_admin';
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        // 1. Stage 2 change log
        if ((string)$oldStage2Id !== (string)$newStage2Id) {
            $newId = count($dynamicLogs) + 1;
            $dynamicLogs[] = [
                'id' => $newId,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'operator' => $operator,
                'event' => 'UPDATE_CONFIG_STAGE2',
                'kategori' => 'konfigurasi_sistem',
                'modul' => 'ApprovalConfig',
                'record_id' => 'stage2',
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'old_values' => ['value' => $oldStage2Name],
                'new_values' => ['value' => $newStage2Name, 'reason' => $reason]
            ];
            ApprovalConfig::setVal('stage2_approver_id', $newStage2Id);
        }

        // 2. Stage 3 change log
        if ((string)$oldStage3Id !== (string)$newStage3Id) {
            $newId = count($dynamicLogs) + 1;
            $dynamicLogs[] = [
                'id' => $newId,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'operator' => $operator,
                'event' => 'UPDATE_CONFIG_STAGE3',
                'kategori' => 'konfigurasi_sistem',
                'modul' => 'ApprovalConfig',
                'record_id' => 'stage3',
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'old_values' => ['value' => $oldStage3Name],
                'new_values' => ['value' => $newStage3Name, 'reason' => $reason]
            ];
            ApprovalConfig::setVal('stage3_approver_id', $newStage3Id);
        }

        // 3. Skip duplikat change log
        $oldSkip = ((string)$oldStage2Id === (string)$oldStage3Id) ? 'Aktif' : 'Tidak aktif';
        $newSkip = ((string)$newStage2Id === (string)$newStage3Id) ? 'Aktif' : 'Tidak aktif';

        if ($oldSkip !== $newSkip) {
            $newId = count($dynamicLogs) + 1;
            $dynamicLogs[] = [
                'id' => $newId,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'operator' => $operator,
                'event' => 'UPDATE_CONFIG_SKIP',
                'kategori' => 'konfigurasi_sistem',
                'modul' => 'ApprovalConfig',
                'record_id' => 'skip_duplicate',
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'old_values' => ['value' => $oldSkip],
                'new_values' => ['value' => $newSkip, 'reason' => $reason]
            ];
        }

        if (!empty($dynamicLogs)) {
            session(['dynamic_audit_logs' => $dynamicLogs]);
        }

        return redirect()->route('cuti.config')->with('success', 'Konfigurasi Approval Cuti berhasil diperbarui.');
    }
}
