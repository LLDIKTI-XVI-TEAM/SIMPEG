<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EwsConfig;
use App\Models\EwsSchedulerRun;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EwsConfigController extends Controller
{
    private static array $configKeys = [
        'ews_scheduler_time' => 'Scheduler Time',
        'pangkat_h90' => 'Pangkat Tahap 1 (Hari)',
        'pangkat_h60' => 'Pangkat Tahap 2 (Hari)',
        'pangkat_h30' => 'Pangkat Tahap 3 (Hari)',
        'kgb_h60' => 'KGB Tahap 1 (Hari)',
        'kgb_h30' => 'KGB Tahap 2 (Hari)',
        'kgb_h14' => 'KGB Tahap 3 (Hari)',
        'pensiun_y1' => 'Pensiun Tahap 1 (Hari)',
        'pensiun_m6' => 'Pensiun Tahap 2 (Hari)',
        'pensiun_m3' => 'Pensiun Tahang 3 (Hari)',
        'pppk_m6' => 'PPPK Tahap 1 (Hari)',
        'pppk_m3' => 'PPPK Tahap 2 (Hari)',
        'pppk_m1' => 'PPPK Tahap 3 (Hari)',
        'satyalancana_h180' => 'Satyalancana Tahap 1 (Hari)',
        'satyalancana_h90' => 'Satyalancana Tahap 2 (Hari)',
        'satyalancana_h30' => 'Satyalancana Tahap 3 (Hari)',
    ];

    /**
     * Display the EWS configuration page.
     */
    public function index()
    {

        $schedulerTime = EwsConfig::getVal('ews_scheduler_time', '07:00');

        $configs = [
            'ews_scheduler_time' => $schedulerTime,

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

            'satyalancana_h180' => EwsConfig::getVal('satyalancana_h180', '180'),
            'satyalancana_h90' => EwsConfig::getVal('satyalancana_h90', '90'),
            'satyalancana_h30' => EwsConfig::getVal('satyalancana_h30', '30'),
        ];

        // Map persistent DB audit logs
        $dbLogs = AuditLog::where('auditable_type', 'EwsConfig')
            ->orderBy('created_at', 'desc')
            ->get();

        $auditRows = [];
        foreach ($dbLogs as $log) {
            $configKey = $log->new_values['key'] ?? $log->old_values['key'] ?? 'Parameter';
            $label = self::$configKeys[$configKey] ?? $configKey;
            $auditRows[] = [
                'time' => $log->created_at ? $log->created_at->format('d M Y, H:i') : '-',
                'actor' => $log->user_name ?? 'Sistem',
                'event' => $log->event,
                'field' => $label,
                'before' => $log->old_values['value'] ?? 'Tidak ada',
                'after' => $log->new_values['value'] ?? 'Tidak ada',
                'ip_address' => $log->ip_address ?? '127.0.0.1',
                'user_agent' => $log->user_agent ?? '-',
                'reason' => $log->new_values['reason'] ?? '-',
            ];
        }

        [$hour, $minute] = array_map('intval', explode(':', $schedulerTime));
        $nextRun = now()->setTime($hour, $minute);
        if ($nextRun->lessThanOrEqualTo(now())) {
            $nextRun->addDay();
        }

        // ── Scheduler status: honest, from ews_scheduler_runs ─────────────
        $latestRun = EwsSchedulerRun::latestRun();

        if ($latestRun === null) {
            $schedulerStatus = [
                'status' => 'belum_pernah_jalan',
                'status_label' => 'Belum Pernah Jalan',
                'last_run' => 'Belum ada data',
                'next_run' => $nextRun->format('d M Y, H:i').' WITA',
                'alerts_created' => 0,
                'employees_checked' => Employee::query()->count(),
                'error_message' => null,
            ];
        } else {
            $schedulerStatus = [
                'status' => $latestRun->status,
                'status_label' => $latestRun->status === 'berhasil' ? 'Berhasil' : 'Gagal',
                'last_run' => $latestRun->started_at
                    ? $latestRun->started_at->format('d M Y, H:i').' WITA'
                    : 'Belum ada data',
                'next_run' => $nextRun->format('d M Y, H:i').' WITA',
                'alerts_created' => $latestRun->alerts_created,
                'employees_checked' => $latestRun->employees_checked,
                'error_message' => $latestRun->status === 'gagal' ? $latestRun->error_message : null,
            ];
        }

        return view('admin.ews.konfigurasi', [
            'configs' => $configs,
            'auditRows' => $auditRows,
            'schedulerStatus' => $schedulerStatus,
            'title' => 'Konfigurasi EWS',
        ]);
    }

    /**
     * Update the configuration.
     */
    public function update(Request $request)
    {

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

            'satyalancana_h180' => 'required|integer|min:1',
            'satyalancana_h90' => 'required|integer|min:1',
            'satyalancana_h30' => 'required|integer|min:1',

            'reason' => 'required|string|min:5',
        ], [
            'required' => ':attribute wajib diisi.',
            'integer' => ':attribute harus berupa bilangan bulat.',
            'min' => ':attribute minimal :min.',
            'ews_scheduler_time.required' => 'Waktu eksekusi scheduler wajib diisi.',
            'ews_scheduler_time.date_format' => 'Format waktu eksekusi scheduler tidak valid (wajib HH:MM).',
            'reason.required' => 'Alasan perubahan wajib diisi.',
            'reason.min' => 'Alasan perubahan minimal berisi 5 karakter.',
        ], [
            'ews_scheduler_time' => 'Waktu eksekusi scheduler',
            'pangkat_h90' => 'Pangkat Tahap 1',
            'pangkat_h60' => 'Pangkat Tahap 2',
            'pangkat_h30' => 'Pangkat Tahap 3',
            'kgb_h60' => 'KGB Tahap 1',
            'kgb_h30' => 'KGB Tahap 2',
            'kgb_h14' => 'KGB Tahap 3',
            'pensiun_y1' => 'Pensiun Tahap 1',
            'pensiun_m6' => 'Pensiun Tahap 2',
            'pensiun_m3' => 'Pensiun Tahap 3',
            'pppk_m6' => 'PPPK Tahap 1',
            'pppk_m3' => 'PPPK Tahap 2',
            'pppk_m1' => 'PPPK Tahap 3',
            'satyalancana_h180' => 'Satyalancana Tahap 1',
            'satyalancana_h90' => 'Satyalancana Tahap 2',
            'satyalancana_h30' => 'Satyalancana Tahap 3',
            'reason' => 'Alasan perubahan',
        ]);

        // ── Backend threshold order validation ────────────────────────────
        $thresholdErrors = [];

        $p90 = (int) $request->input('pangkat_h90');
        $p60 = (int) $request->input('pangkat_h60');
        $p30 = (int) $request->input('pangkat_h30');
        if ($p90 <= $p60) {
            $thresholdErrors['pangkat_h60'][] = 'Pangkat Tahap 1 harus lebih besar dari Tahap 2.';
        }
        if ($p60 <= $p30) {
            $thresholdErrors['pangkat_h30'][] = 'Pangkat Tahap 2 harus lebih besar dari Tahap 3.';
        }

        $k60 = (int) $request->input('kgb_h60');
        $k30 = (int) $request->input('kgb_h30');
        $k14 = (int) $request->input('kgb_h14');
        if ($k60 <= $k30) {
            $thresholdErrors['kgb_h30'][] = 'KGB Tahap 1 harus lebih besar dari Tahap 2.';
        }
        if ($k30 <= $k14) {
            $thresholdErrors['kgb_h14'][] = 'KGB Tahap 2 harus lebih besar dari Tahap 3.';
        }

        $py1 = (int) $request->input('pensiun_y1');
        $pm6 = (int) $request->input('pensiun_m6');
        $pm3 = (int) $request->input('pensiun_m3');
        if ($py1 <= $pm6) {
            $thresholdErrors['pensiun_m6'][] = 'Pensiun Tahap 1 harus lebih besar dari Tahap 2.';
        }
        if ($pm6 <= $pm3) {
            $thresholdErrors['pensiun_m3'][] = 'Pensiun Tahap 2 harus lebih besar dari Tahap 3.';
        }

        $pp6 = (int) $request->input('pppk_m6');
        $pp3 = (int) $request->input('pppk_m3');
        $pp1 = (int) $request->input('pppk_m1');
        if ($pp6 <= $pp3) {
            $thresholdErrors['pppk_m3'][] = 'PPPK Tahap 1 harus lebih besar dari Tahap 2.';
        }
        if ($pp3 <= $pp1) {
            $thresholdErrors['pppk_m1'][] = 'PPPK Tahap 2 harus lebih besar dari Tahap 3.';
        }

        $sl180 = (int) $request->input('satyalancana_h180');
        $sl90 = (int) $request->input('satyalancana_h90');
        $sl30 = (int) $request->input('satyalancana_h30');
        if ($sl180 <= $sl90) {
            $thresholdErrors['satyalancana_h90'][] = 'Satyalancana Tahap 1 harus lebih besar dari Tahap 2.';
        }
        if ($sl90 <= $sl30) {
            $thresholdErrors['satyalancana_h30'][] = 'Satyalancana Tahap 2 harus lebih besar dari Tahap 3.';
        }

        if (! empty($thresholdErrors)) {
            throw ValidationException::withMessages($thresholdErrors);
        }

        $dynamicLogs = session('dynamic_audit_logs', []);
        $operator = auth()->user()->name ?? 'super_admin';
        $ip = $request->ip();
        $userAgent = $request->userAgent();
        $reason = $request->input('reason');
        $changed = false;

        foreach (self::$configKeys as $key => $label) {
            $oldVal = EwsConfig::getVal($key);
            $newVal = $request->input($key);

            if ((string) $oldVal !== (string) $newVal) {
                // Persistent DB audit log
                AuditService::log(
                    'UPDATE',
                    'EwsConfig',
                    null, // Config key is not a UUID
                    ['key' => $key, 'value' => (string) $oldVal],
                    ['key' => $key, 'value' => (string) $newVal, 'reason' => $reason],
                    $request
                );

                // Mirror to session for test compatibility
                $newId = count($dynamicLogs) + 1;
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
                    'old_values' => ['value' => (string) $oldVal],
                    'new_values' => ['value' => (string) $newVal, 'reason' => $reason],
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
