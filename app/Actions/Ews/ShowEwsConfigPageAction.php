<?php

namespace App\Actions\Ews;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EwsConfig;
use App\Models\EwsSchedulerRun;
use App\Services\Ews\EwsConfigCatalog;
use Illuminate\Pagination\LengthAwarePaginator;

class ShowEwsConfigPageAction
{
    /**
     * Menyusun data halaman konfigurasi EWS: nilai konfigurasi aktif, riwayat
     * audit perubahan konfigurasi, dan status eksekusi scheduler terakhir.
     *
     * @return array<string, mixed>
     */
    public function execute(int $logPerPage = 10): array
    {
        $configs = $this->currentConfigs();

        return [
            'configs' => $configs,
            'auditRows' => $this->auditRows($logPerPage),
            'schedulerStatus' => $this->schedulerStatus($configs['ews_scheduler_time']),
            'title' => 'Konfigurasi EWS',
        ];
    }

    /** @return array<string, string> */
    private function currentConfigs(): array
    {
        $configs = [];
        foreach (EwsConfigCatalog::DEFAULTS as $key => $default) {
            $configs[$key] = EwsConfig::getVal($key, $default);
        }

        return $configs;
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    private function auditRows(int $perPage): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, array<string, mixed>> $auditRows */
        $auditRows = AuditLog::where('auditable_type', 'EwsConfig')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage, ['*'], 'log_page')
            ->withQueryString()
            ->through(function (AuditLog $log): array {
                $configKey = $log->new_values['key'] ?? $log->old_values['key'] ?? 'Parameter';

                return [
                    'time' => $log->created_at ? $log->created_at->format('d M Y, H:i') : '-',
                    'actor' => $log->user_name ?? 'Sistem',
                    'event' => $log->event,
                    'field' => EwsConfigCatalog::labelFor($configKey),
                    'before' => $log->old_values['value'] ?? 'Tidak ada',
                    'after' => $log->new_values['value'] ?? 'Tidak ada',
                    'ip_address' => $log->ip_address ?? '127.0.0.1',
                    'user_agent' => $log->user_agent ?? '-',
                    'reason' => $log->new_values['reason'] ?? '-',
                ];
            });

        return $auditRows;
    }

    /**
     * Status scheduler dibaca jujur dari ews_scheduler_runs; tanpa data run,
     * halaman menampilkan status "belum pernah jalan" alih-alih angka palsu.
     *
     * @return array<string, mixed>
     */
    private function schedulerStatus(string $schedulerTime): array
    {
        [$hour, $minute] = array_map('intval', explode(':', $schedulerTime));
        $nextRun = now()->setTime($hour, $minute);
        if ($nextRun->lessThanOrEqualTo(now())) {
            $nextRun->addDay();
        }

        $latestRun = EwsSchedulerRun::latestRun();

        if ($latestRun === null) {
            return [
                'status' => 'belum_pernah_jalan',
                'status_label' => 'Belum Pernah Jalan',
                'last_run' => 'Belum ada data',
                'next_run' => $nextRun->format('d M Y, H:i').' WITA',
                'alerts_created' => 0,
                'employees_checked' => Employee::query()->count(),
                'error_message' => null,
            ];
        }

        return [
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
}
