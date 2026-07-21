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

            $satyalancanaDays = [
                $configDays('satyalancana_h180', 180),
                $configDays('satyalancana_h90', 90),
                $configDays('satyalancana_h30', 30),
            ];

            // Scan semua pegawai aktif dalam chunk 100 untuk mencegah OOM pada dataset besar.
            Employee::with(['appointments', 'jenisPegawai', 'disciplineRecords'])
                ->where('status_aktif', 'Aktif')
                ->chunkById(100, function ($employees) use (
                    $pangkatDays, $kgbDays, $pensiunDays, $pppkDays, $satyalancanaDays,
                    &$alertsCreated, &$employeesChecked
                ): void {
                    foreach ($employees as $employee) {
                        $employeesChecked++;

                        // 1. Kenaikan Pangkat
                        if ($employee->tanggal_kenaikan_pangkat_berikutnya) {
                            $targetDate = Carbon::parse($employee->tanggal_kenaikan_pangkat_berikutnya);
                            $diffDays = (int) now()->startOfDay()->diffInDays($targetDate->startOfDay(), false);

                            foreach ($pangkatDays as $days) {
                                if ($diffDays === $days) {
                                    $hasActiveDiscipline = $employee->disciplineRecords->contains('is_active', true);
                                    $isEligible = ($employee->is_kinerja_baik === true) && ! $hasActiveDiscipline;

                                    $created = $this->createAlertIfNotExist(
                                        $employee,
                                        'KENAIKAN_PANGKAT',
                                        $targetDate->toDateString(),
                                        $days,
                                        'Kenaikan Pangkat',
                                        $isEligible,
                                    );
                                    if ($created) {
                                        $alertsCreated++;
                                    }
                                }
                            }
                        }

                        // 2. KGB — tidak ada eligibility check (selalu eligible)
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
                                        'KGB',
                                    );
                                    if ($created) {
                                        $alertsCreated++;
                                    }
                                }
                            }
                        }

                        // 3. Pensiun — tidak ada eligibility check
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
                                        'Pensiun',
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

                            // Baca dari employees.tanggal_akhir_kontrak terlebih dahulu
                            if ($employee->tanggal_akhir_kontrak) {
                                $targetDate = Carbon::parse($employee->tanggal_akhir_kontrak);
                            } else {
                                // Fallback: tmt_pengangkatan PPPK + 5 tahun
                                $pppkApp = $employee->appointments
                                    ->filter(fn ($a): bool => $a->jenis_pengangkatan === 'PPPK' && $a->tmt_pengangkatan !== null)
                                    ->sortBy('tmt_pengangkatan')
                                    ->last();

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
                                            'Kontrak PPPK',
                                        );
                                        if ($created) {
                                            $alertsCreated++;
                                        }
                                    }
                                }
                            }
                        }

                        // 5. Satyalancana Karya Satya: milestone 10 / 20 / 30 tahun
                        $firstAppointment = $employee->appointments
                            ->filter(fn ($appointment): bool => $appointment->tmt_pengangkatan !== null)
                            ->sortBy('tmt_pengangkatan')
                            ->first();

                        if ($firstAppointment) {
                            $firstTmt  = Carbon::parse($firstAppointment->tmt_pengangkatan)->startOfDay();
                            $isEligible = $employee->is_satyalancana_eligible === true;

                            foreach ([10, 20, 30] as $years) {
                                $targetDate = $firstTmt->copy()->addYears($years);
                                $diffDays   = (int) now()->startOfDay()->diffInDays($targetDate->startOfDay(), false);

                                foreach ($satyalancanaDays as $days) {
                                    if ($diffDays === $days) {
                                        $created = $this->createAlertIfNotExist(
                                            $employee,
                                            'SATYALANCANA',
                                            $targetDate->toDateString(),
                                            $days,
                                            'Satyalancana '.$years.' Tahun',
                                            $isEligible,
                                            $years,
                                        );
                                        if ($created) {
                                            $alertsCreated++;
                                        }
                                    }
                                }
                            }
                        }
                    }
                });

            // Mark scheduler run as successful
            $run->update([
                'status'            => 'berhasil',
                'finished_at'       => now(),
                'alerts_created'    => $alertsCreated,
                'employees_checked' => $employeesChecked,
            ]);

        } catch (\Throwable $e) {
            Log::error('EWS Scheduler Run Failed: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            $run->update([
                'status'        => 'gagal',
                'finished_at'   => now(),
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
     * Buat EwsAlert dan kirim notifikasi jika eligible.
     *
     * @param  bool|null  $isEligible  null = tidak ada eligibility check untuk tipe ini
     * @param  int|null   $satyalancanayears  milestone dalam tahun; diisi hanya untuk SATYALANCANA
     */
    protected function createAlertIfNotExist(
        Employee $employee,
        string $type,
        string $targetDate,
        int $days,
        string $titleLabel,
        ?bool $isEligible = null,
        ?int $satyalancanayears = null,
    ): bool {
        // Cek duplikasi di query level sebelum menyentuh DB constraint
        $exists = EwsAlert::where('employee_id', $employee->id)
            ->where('type', $type)
            ->where('target_date', $targetDate)
            ->where('interval_days', $days)
            ->exists();

        if ($exists) {
            return false;
        }

        // Try-catch untuk menangani race condition pada concurrent run
        try {
            $alert = EwsAlert::create([
                'employee_id'        => $employee->id,
                'type'               => $type,
                'target_date'        => $targetDate,
                'interval_days'      => $days,
                'is_processed'       => false,
                'is_eligible'        => $isEligible,
                'satyalancana_years' => $satyalancanayears,
                'followup_status'    => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
            ]);
        } catch (QueryException $e) {
            // Sudah dibuat oleh run bersamaan
            return false;
        }

        // Kirim notifikasi ke pegawai dan admin (in-app selalu, email jika channel aktif dan credential terkonfigurasi).
        // is_eligible tetap disimpan di DB untuk keperluan dashboard dan filtering, bukan sebagai gate notifikasi.
        $timeLabel = $days.' hari';
        if ($days >= 365 && $days % 365 === 0) {
            $timeLabel = ($days / 365).' tahun';
        } elseif ($days >= 30 && $days % 30 === 0) {
            $timeLabel = ($days / 30).' bulan';
        }

<<<<<<< Updated upstream
        $notificationType = 'ews.'.strtolower(str_replace('_', '_', $type));

        // Sertakan keterangan tidak eligible agar penerima mengetahui statusnya.
        $eligibilityNote = ($isEligible === false)
            ? ' Perhatian: pegawai saat ini belum memenuhi syarat eligibilitas.'
            : '';

        $body = sprintf(
            'Pemberitahuan EWS: Jadwal %s Anda jatuh pada %s (sisa sekitar %s). Harap lengkapi berkas.%s',
            $titleLabel,
            Carbon::parse($targetDate)->format('d-m-Y'),
            $timeLabel,
            $eligibilityNote
        );

        $this->notificationService->createForEmployee(
            $employee,
            $notificationType,
            'Peringatan EWS: '.$titleLabel,
            $body,
            [
                'ews_alert_id' => $alert->id,
                'is_eligible'  => $isEligible,
            ]
        );

        $alert->update(['notified_at' => now()]);
=======
        // Send notification regardless of eligibility, but add warning note if not eligible
        $timeLabel = $days.' hari';
        if ($days >= 365 && $days % 365 === 0) {
            $timeLabel = ($days / 365).' tahun';
        } elseif ($days >= 30 && $days % 30 === 0) {
            $timeLabel = ($days / 30).' bulan';
        }
>>>>>>> Stashed changes

        $typeLabel = strtolower($type);
        $notificationType = 'ews.'.$typeLabel;

        $body = sprintf(
            'Pemberitahuan EWS: Jadwal %s Anda jatuh pada %s (sisa sekitar %s). Harap lengkapi berkas.',
            $titleLabel,
            Carbon::parse($targetDate)->format('d-m-Y'),
            $timeLabel
        );

        if (! $isEligible) {
            $body .= "\n\nPerhatian: Saat ini Anda belum memenuhi syarat eligibilitas.";
        }

        $this->notificationService->createForEmployee(
            $employee,
            $notificationType,
            'Peringatan EWS: '.$titleLabel,
            $body,
            ['ews_alert_id' => $alert->id]
        );

        $alert->update([
            'is_eligible' => $isEligible,
            'notified_at' => now(),
        ]);

        return true;
    }
}


