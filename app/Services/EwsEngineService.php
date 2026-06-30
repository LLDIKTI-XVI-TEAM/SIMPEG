<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\EwsConfig;
use App\Models\EwsSchedulerRun;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class EwsEngineService
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Run the EWS engine scan.
     */
    public function run(): void
    {
        $startedAt = now();
        $alertsCreated = 0;
        $employeesChecked = 0;

        // Initialize scheduler run
        $run = EwsSchedulerRun::create([
            'status' => 'sedang_berjalan',
            'started_at' => $startedAt,
            'employees_checked' => 0,
            'alerts_created' => 0,
        ]);

        try {
            // Retrieve config values for alert thresholds
            $configDays = fn (string $key, int $default): int => (int) EwsConfig::getVal($key, (string) $default);

            $pangkatDays = [
                $configDays('pangkat_h90', 90),
                $configDays('pangkat_h60', 60),
                $configDays('pangkat_h30', 30),
            ];

            $kgbDays = [
                $configDays('kgb_h60', 60),
                $configDays('kgb_h30', 30),
                $configDays('kgb_h14', 14),
            ];

            $pensiunDays = [
                $configDays('pensiun_y1', 365),
                $configDays('pensiun_m6', 180),
                $configDays('pensiun_m3', 90),
            ];

            $pppkDays = [
                $configDays('pppk_m6', 180),
                $configDays('pppk_m3', 90),
                $configDays('pppk_m1', 30),
            ];

            // Scan all active employees
            $employees = Employee::with(['jenisPegawai', 'disciplineRecords'])
                ->where('status_aktif', 'Aktif')
                ->get();

            $employeesChecked = $employees->count();

            foreach ($employees as $employee) {
                // 1. Kenaikan Pangkat
                if ($employee->tanggal_kenaikan_pangkat_berikutnya) {
                    $targetDate = Carbon::parse($employee->tanggal_kenaikan_pangkat_berikutnya);
                    $diffDays = (int) now()->startOfDay()->diffInDays($targetDate->startOfDay(), false);

                    foreach ($pangkatDays as $days) {
                        if ($diffDays === $days) {
                            $created = $this->createAlertIfNotExist(
                                $employee,
                                'KENAIKAN_PANGKAT',
                                $targetDate->toDateString(),
                                $days,
                                'Kenaikan Pangkat'
                            );
                            if ($created) {
                                $alertsCreated++;
                            }
                        }
                    }
                }

                // 2. KGB
                if ($employee->tanggal_kgb_berikutnya) {
                    $targetDate = Carbon::parse($employee->tanggal_kgb_berikutnya);
                    $diffDays = (int) now()->startOfDay()->diffInDays($targetDate->startOfDay(), false);

                    foreach ($kgbDays as $days) {
                        if ($diffDays === $days) {
                            $created = $this->createAlertIfNotExist(
                                $employee,
                                'KGB',
                                $targetDate->toDateString(),
                                $days,
                                'KGB'
                            );
                            if ($created) {
                                $alertsCreated++;
                            }
                        }
                    }
                }

                // 3. Pensiun
                if ($employee->tanggal_pensiun) {
                    $targetDate = Carbon::parse($employee->tanggal_pensiun);
                    $diffDays = (int) now()->startOfDay()->diffInDays($targetDate->startOfDay(), false);

                    foreach ($pensiunDays as $days) {
                        if ($diffDays === $days) {
                            $created = $this->createAlertIfNotExist(
                                $employee,
                                'PENSIUN',
                                $targetDate->toDateString(),
                                $days,
                                'Pensiun'
                            );
                            if ($created) {
                                $alertsCreated++;
                            }
                        }
                    }
                }

                // 4. Kontrak PPPK
                $isPppk = $employee->jenisPegawai && strtolower($employee->jenisPegawai->nama) === 'pppk';
                if ($isPppk) {
                    $targetDate = null;

                    // Read from employees.tanggal_akhir_kontrak first
                    if ($employee->tanggal_akhir_kontrak) {
                        $targetDate = Carbon::parse($employee->tanggal_akhir_kontrak);
                    } else {
                        // Fallback to tmt_pengangkatan + 5 years from appointments
                        $pppkApp = $employee->appointments()
                            ->where('jenis_pengangkatan', 'PPPK')
                            ->latest('tmt_pengangkatan')
                            ->first();

                        if ($pppkApp) {
                            $targetDate = Carbon::parse($pppkApp->tmt_pengangkatan)->addYears(5);
                        }
                    }

                    if ($targetDate) {
                        $diffDays = (int) now()->startOfDay()->diffInDays($targetDate->startOfDay(), false);

                        foreach ($pppkDays as $days) {
                            if ($diffDays === $days) {
                                $created = $this->createAlertIfNotExist(
                                    $employee,
                                    'KONTRAK_PPPK',
                                    $targetDate->toDateString(),
                                    $days,
                                    'Kontrak PPPK'
                                );
                                if ($created) {
                                    $alertsCreated++;
                                }
                            }
                        }
                    }
                }
            }

            // Mark scheduler run as successful
            $run->update([
                'status' => 'berhasil',
                'finished_at' => now(),
                'alerts_created' => $alertsCreated,
                'employees_checked' => $employeesChecked,
            ]);

        } catch (\Throwable $e) {
            Log::error('EWS Scheduler Run Failed: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            $run->update([
                'status' => 'gagal',
                'finished_at' => now(),
                'error_message' => $e->getMessage()."\n".$e->getTraceAsString(),
            ]);

            // Notify Super Admin if scheduler fails
            $superAdmins = User::where('role', 'super_admin')
                ->whereNotNull('employee_id')
                ->get();

            foreach ($superAdmins as $admin) {
                $employee = Employee::find($admin->employee_id);
                if ($employee) {
                    $this->notificationService->createForEmployee(
                        $employee,
                        'ews.scheduler_failed',
                        'Gagal Eksekusi Scheduler EWS',
                        'Scheduler EWS harian gagal berjalan. Error: '.$e->getMessage()
                    );
                }
            }

            throw $e;
        }
    }

    /**
     * Create EwsAlert and optional SimpegNotification if eligible.
     */
    protected function createAlertIfNotExist(Employee $employee, string $type, string $targetDate, int $days, string $titleLabel): bool
    {
        // Check uniqueness at query level first to avoid DB exception
        $exists = EwsAlert::where('employee_id', $employee->id)
            ->where('type', $type)
            ->where('target_date', $targetDate)
            ->where('interval_days', $days)
            ->exists();

        if ($exists) {
            return false;
        }

        // Try catch block to handle unique key database constraints safely
        try {
            $alert = EwsAlert::create([
                'employee_id' => $employee->id,
                'type' => $type,
                'target_date' => $targetDate,
                'interval_days' => $days,
                'is_processed' => false,
            ]);
        } catch (QueryException $e) {
            // Already created by a concurrent run
            return false;
        }

        // Check eligibility for promotion alerts
        $isEligible = true;
        if ($type === 'KENAIKAN_PANGKAT') {
            $hasActiveDiscipline = $employee->disciplineRecords->contains('is_active', true);
            $isEligible = ($employee->is_kinerja_baik === true) && ! $hasActiveDiscipline;
        }

        // Send notification only if eligible
        if ($isEligible) {
            $timeLabel = $days.' hari';
            if ($days >= 365 && $days % 365 === 0) {
                $timeLabel = ($days / 365).' tahun';
            } elseif ($days >= 30 && $days % 30 === 0) {
                $timeLabel = ($days / 30).' bulan';
            }

            $typeLabel = strtolower($type);
            $notificationType = 'ews.'.$typeLabel;

            $body = sprintf(
                'Pemberitahuan EWS: Jadwal %s Anda jatuh pada %s (sisa sekitar %s). Harap lengkapi berkas.',
                $titleLabel,
                Carbon::parse($targetDate)->format('d-m-Y'),
                $timeLabel
            );

            $this->notificationService->createForEmployee(
                $employee,
                $notificationType,
                'Peringatan EWS: '.$titleLabel,
                $body,
                ['ews_alert_id' => $alert->id]
            );

            $alert->update([
                'notified_at' => now(),
            ]);
        }

        return true;
    }
}
