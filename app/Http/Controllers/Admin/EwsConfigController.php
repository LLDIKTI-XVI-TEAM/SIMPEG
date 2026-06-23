<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EwsConfig;
use Illuminate\Http\Request;

class EwsConfigController extends Controller
{
    /**
     * Display the EWS configuration page.
     */
    public function index()
    {
        // Enforce Super Admin authorization
        if (session('active_role') !== 'Super Admin') {
            abort(403, 'Aksi tidak diizinkan. Halaman ini hanya untuk Super Admin.');
        }

        $configs = [
            'ews_scheduler_time' => EwsConfig::getVal('ews_scheduler_time', '07:00'),
            
            'pangkat_h90' => EwsConfig::getVal('pangkat_h90', '90'),
            'pangkat_h60' => EwsConfig::getVal('pangkat_h60', '60'),
            'pangkat_h30' => EwsConfig::getVal('pangkat_h30', '30'),
            
            'kgb_h60' => EwsConfig::getVal('kgb_h60', '60'),
            'kgb_h30' => EwsConfig::getVal('kgb_h30', '30'),
            'kgb_h14' => EwsConfig::getVal('kgb_h14', '14'),
            
            'pensiun_y1' => EwsConfig::getVal('pensiun_y1', '365'),
            'pensiun_m6' => EwsConfig::getVal('pensiun_m6', '180'),
            'pensiun_m3' => EwsConfig::getVal('pensiun_m3', '90'),
            
            'pppk_m6' => EwsConfig::getVal('pppk_m6', '180'),
            'pppk_m3' => EwsConfig::getVal('pppk_m3', '90'),
            'pppk_m1' => EwsConfig::getVal('pppk_m1', '30'),
        ];

        // Map session dynamic audit logs
        $auditRows = [];
        $dynamicLogs = session('dynamic_audit_logs', []);
        
        foreach ($dynamicLogs as $log) {
            if (isset($log['modul']) && $log['modul'] === 'EwsConfig') {
                $auditRows[] = [
                    'time' => date('d Jun Y, H:i', strtotime($log['timestamp'])),
                    'actor' => $log['operator'],
                    'event' => $log['event'] ?? 'UPDATE_EWS_CONFIG',
                    'field' => $log['record_id'] ?? 'Parameter',
                    'before' => $log['old_values']['value'] ?? 'Tidak ada',
                    'after' => $log['new_values']['value'] ?? 'Tidak ada',
                    'ip_address' => $log['ip_address'] ?? '127.0.0.1',
                    'user_agent' => $log['user_agent'] ?? '-',
                    'reason' => $log['new_values']['reason'] ?? '-',
                ];
            }
        }

        // Base/mock history as defined in the spec
        $baseLogs = [
            [
                'time' => '22 Jun 2026, 10:00', 'actor' => 'Super Admin', 'event' => 'UPDATE_EWS_CONFIG',
                'field' => 'KGB Tahap 3 (Hari)', 'before' => '14', 'after' => '14',
                'ip_address' => '192.168.1.10', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0.0.0',
                'reason' => 'Verifikasi ulang parameter KGB setelah rapat koordinasi',
            ],
            [
                'time' => '21 Jun 2026, 09:30', 'actor' => 'Super Admin', 'event' => 'UPDATE_EWS_CONFIG',
                'field' => 'Scheduler Time', 'before' => '08:00', 'after' => '07:00',
                'ip_address' => '192.168.1.10', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0.0.0',
                'reason' => 'Memajukan waktu scheduler agar alert terkirim sebelum jam kerja dimulai',
            ],
        ];

        // Merge, dynamic logs first (most recent)
        $mergedAudit = array_merge($auditRows, $baseLogs);

        return view('admin.ews.konfigurasi', [
            'configs' => $configs,
            'auditRows' => $mergedAudit,
            'title' => 'Konfigurasi EWS'
        ]);
    }

    /**
     * Update the configuration.
     */
    public function update(Request $request)
    {
        // Enforce Super Admin authorization
        if (session('active_role') !== 'Super Admin') {
            abort(403, 'Aksi tidak diizinkan. Halaman ini hanya untuk Super Admin.');
        }

        $request->validate([
            'ews_scheduler_time' => 'required|date_format:H:i',
            
            'pangkat_h90' => 'required|integer|min:1',
            'pangkat_h60' => 'required|integer|min:1',
            'pangkat_h30' => 'required|integer|min:1',
            
            'kgb_h60' => 'required|integer|min:1',
            'kgb_h30' => 'required|integer|min:1',
            'kgb_h14' => 'required|integer|min:1',
            
            'pensiun_y1' => 'required|integer|min:1',
            'pensiun_m6' => 'required|integer|min:1',
            'pensiun_m3' => 'required|integer|min:1',
            
            'pppk_m6' => 'required|integer|min:1',
            'pppk_m3' => 'required|integer|min:1',
            'pppk_m1' => 'required|integer|min:1',
            
            'reason' => 'required|string|min:5',
        ], [
            'ews_scheduler_time.required' => 'Waktu eksekusi scheduler wajib diisi.',
            'ews_scheduler_time.date_format' => 'Format waktu eksekusi scheduler tidak valid (wajib HH:MM).',
            'reason.required' => 'Alasan perubahan wajib diisi.',
            'reason.min' => 'Alasan perubahan minimal berisi 5 karakter.',
        ]);

        $keys = [
            'ews_scheduler_time' => 'Scheduler Time',
            'pangkat_h90' => 'Pangkat Tahap 1 (Hari)',
            'pangkat_h60' => 'Pangkat Tahap 2 (Hari)',
            'pangkat_h30' => 'Pangkat Tahap 3 (Hari)',
            'kgb_h60' => 'KGB Tahap 1 (Hari)',
            'kgb_h30' => 'KGB Tahap 2 (Hari)',
            'kgb_h14' => 'KGB Tahap 3 (Hari)',
            'pensiun_y1' => 'Pensiun Tahap 1 (Hari)',
            'pensiun_m6' => 'Pensiun Tahap 2 (Hari)',
            'pensiun_m3' => 'Pensiun Tahap 3 (Hari)',
            'pppk_m6' => 'PPPK Tahap 1 (Hari)',
            'pppk_m3' => 'PPPK Tahap 2 (Hari)',
            'pppk_m1' => 'PPPK Tahap 3 (Hari)',
        ];

        $dynamicLogs = session('dynamic_audit_logs', []);
        $operator = auth()->user()->name ?? 'Super Admin';
        $ip = $request->ip();
        $userAgent = $request->userAgent();
        $reason = $request->input('reason');
        $changed = false;

        foreach ($keys as $key => $label) {
            $oldVal = EwsConfig::getVal($key);
            $newVal = $request->input($key);

            if ((string)$oldVal !== (string)$newVal) {
                $newId = count($dynamicLogs) + count(AuditController::$auditLogs) + 1;
                $dynamicLogs[] = [
                    'id' => $newId,
                    'timestamp' => now()->format('Y-m-d H:i:s'),
                    'operator' => $operator,
                    'event' => 'UPDATE_EWS_CONFIG',
                    'kategori' => 'konfigurasi_sistem',
                    'modul' => 'EwsConfig',
                    'record_id' => $label,
                    'ip_address' => $ip,
                    'user_agent' => $userAgent,
                    'old_values' => ['value' => (string)$oldVal],
                    'new_values' => ['value' => (string)$newVal, 'reason' => $reason]
                ];
                EwsConfig::setVal($key, $newVal);
                $changed = true;
            }
        }

        if ($changed) {
            session(['dynamic_audit_logs' => $dynamicLogs]);
        }

        return redirect()->route('ews.config')->with('success', 'Konfigurasi EWS berhasil diperbarui.');
    }
}
