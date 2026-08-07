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

            $pangkatRequiredYears = $this->configYears('pangkat_required_years', 4);
            $kgbRequiredYears = $this->configYears('kgb_required_years', 2);
            $pensiunRequiredAgeYears = max(0, $configDays('pensiun_required_age_years', 0));
            $pppkContractYears = $this->configYears('pppk_contract_years', 4);
            $satyalancanaYears = [
                $this->configYears('satyalancana_years_1', 10),
                $this->configYears('satyalancana_years_2', 20),
                $this->configYears('satyalancana_years_3', 30),
            ];

            // Scan semua pegawai aktif dalam chunk 100 untuk mencegah OOM pada dataset besar.
            Employee::with(['appointments', 'rankHistories', 'salaryHistories', 'jenisPegawai', 'disciplineRecords'])
                ->where('status_aktif', 'Aktif')
                ->chunkById(100, function ($employees) use (
                    $pangkatDays, $kgbDays, $pensiunDays, $pppkDays, $satyalancanaDays,
                    $pangkatRequiredYears, $kgbRequiredYears, $pensiunRequiredAgeYears,
                    $pppkContractYears, $satyalancanaYears,
                    &$alertsCreated, &$employeesChecked
                ): void {
                    foreach ($employees as $employee) {
                        $employeesChecked++;

                        // 1. Kenaikan Pangkat: gunakan TMT pangkat terbaru agar perubahan masa berlaku langsung diterapkan.
                        $latestRank = $employee->rankHistories
                            ->filter(fn ($history): bool => $history->tmt_pangkat !== null)
                            ->sortByDesc('tmt_pangkat')
                            ->first();
                        $targetDate = $latestRank
                            ? Carbon::parse($latestRank->tmt_pangkat)->addYears($pangkatRequiredYears)
                            : ($employee->tanggal_kenaikan_pangkat_berikutnya
                                ? Carbon::parse($employee->tanggal_kenaikan_pangkat_berikutnya)
                                : null);
                        if ($targetDate) {
                            $diffDays = (int) now()->startOfDay()->diffInDays($targetDate->startOfDay(), false);

                            $days = $this->dueStage($pangkatDays, $diffDays);
                            if ($days !== null) {
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

                        // 2. KGB — gunakan TMT KGB terbaru agar perubahan masa berlaku langsung diterapkan.
                        $latestKgb = $employee->salaryHistories
                            ->filter(fn ($history): bool => $history->tmt_kgb !== null)
                            ->sortByDesc('tmt_kgb')
                            ->first();
                        $targetDate = $latestKgb
                            ? Carbon::parse($latestKgb->tmt_kgb)->addYears($kgbRequiredYears)
                            : ($employee->tanggal_kgb_berikutnya
                                ? Carbon::parse($employee->tanggal_kgb_berikutnya)
                                : null);
                        if ($targetDate) {
                            $diffDays = (int) now()->startOfDay()->diffInDays($targetDate->startOfDay(), false);

                            $days = $this->dueStage($kgbDays, $diffDays);
                            if ($days !== null) {
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

                        // 3. Pensiun — EWS alert menggunakan tanggal pensiun final (manual jika diset, kalkulasi BUP jika kosong)
                        $targetDate = null;

                        // Prioritaskan tanggal_pensiun manual jika sudah diset
                        if ($employee->tanggal_pensiun) {
                            $targetDate = Carbon::parse($employee->tanggal_pensiun);
                        } else {
                            // Fallback ke kalkulasi BUP jika tanggal_pensiun kosong
                            if ($pensiunRequiredAgeYears > 0 && $employee->tanggal_lahir) {
                                $targetDate = Carbon::parse($employee->tanggal_lahir)->addYears($pensiunRequiredAgeYears);
                            } else {
                                $targetDate = $this->calculatePensionFromPositionBup($employee);
                            }
                        }
                        if ($targetDate) {
                            $diffDays = (int) now()->startOfDay()->diffInDays($targetDate->startOfDay(), false);

                            $days = $this->dueStage($pensiunDays, $diffDays);
                            if ($days !== null) {
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

                        // 4. Kontrak PPPK
                        $isPppk = $employee->jenisPegawai && strtolower($employee->jenisPegawai->nama) === 'pppk';
                        if ($isPppk) {
                            $targetDate = null;

                            // Baca dari employees.tanggal_akhir_kontrak terlebih dahulu
                            if ($employee->tanggal_akhir_kontrak) {
                                $targetDate = Carbon::parse($employee->tanggal_akhir_kontrak);
                            } else {
                                // Fallback: TMT pengangkatan PPPK terbaru + masa kontrak global.
                                $pppkApp = $employee->appointments
                                    ->filter(fn ($a): bool => strtoupper((string) $a->jenis_pengangkatan) === 'PPPK' && $a->tmt_pengangkatan !== null)
                                    ->sortByDesc('tmt_pengangkatan')
                                    ->first();

                                if ($pppkApp) {
                                    $targetDate = Carbon::parse($pppkApp->tmt_pengangkatan)->addYears($pppkContractYears);
                                }
                            }

                            if ($targetDate) {
                                $diffDays = (int) now()->startOfDay()->diffInDays($targetDate->startOfDay(), false);

                                $days = $this->dueStage($pppkDays, $diffDays);
                                if ($days !== null) {
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

                        // 5. Satyalancana Karya Satya: milestone 10 / 20 / 30 tahun
                        $firstAppointment = $employee->appointments
                            ->filter(fn ($appointment): bool => $appointment->tmt_pengangkatan !== null)
                            ->sortBy('tmt_pengangkatan')
                            ->first();

                        if ($firstAppointment) {
                            $firstTmt = Carbon::parse($firstAppointment->tmt_pengangkatan)->startOfDay();
                            $isEligible = $employee->is_satyalancana_eligible === true;

                            foreach ($satyalancanaYears as $years) {
                                $targetDate = $firstTmt->copy()->addYears($years);
                                $diffDays = (int) now()->startOfDay()->diffInDays($targetDate->startOfDay(), false);

                                $days = $this->dueStage($satyalancanaDays, $diffDays);
                                if ($days !== null) {
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
                });

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
                    // Pesan exception mentah tidak boleh tampil di notifikasi karena bisa
                    // membocorkan detail sensitif (kredensial, struktur query, path server).
                    // Detail teknis lengkap sudah tercatat di log server dan riwayat run.
                    $this->notificationService->createForEmployee(
                        $employee,
                        'ews.scheduler_failed',
                        'Gagal Eksekusi Scheduler EWS',
                        'Scheduler EWS harian gagal berjalan. Silakan periksa log server atau riwayat eksekusi scheduler untuk detail teknis.'
                    );
                }
            }

            throw $e;
        }
    }

    private function configYears(string $key, int $default): int
    {
        return max(1, (int) EwsConfig::getVal($key, (string) $default));
    }

    /**
     * Mengambil tahap paling mendesak yang telah mulai berlaku.
     * Tahap terakhir tetap berlaku setelah tanggal target lewat agar reminder tidak
     * berhenti sebelum pegawai membaca notifikasinya.
     *
     * @param  list<int>  $stages
     */
    private function dueStage(array $stages, int $diffDays): ?int
    {
        $stages = array_values(array_unique(array_filter($stages, fn (int $days): bool => $days >= 0)));
        sort($stages);

        foreach ($stages as $stage) {
            if ($diffDays <= $stage) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Menghitung tanggal pensiun dari BUP jabatan, mengikuti logika TmtCalculatorService.
     * EWS alert HARUS pakai BUP calculation, bukan tanggal_pensiun manual.
     */
    private function calculatePensionFromPositionBup(Employee $employee): ?Carbon
    {
        if ($employee->tanggal_lahir === null) {
            return null;
        }

        $position = $employee->positionHistories()
            ->with(['jabatan', 'jenisJabatan'])
            ->whereNotNull('tmt_jabatan')
            ->orderByDesc('tmt_jabatan')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($position === null) {
            return null;
        }

        $bup = $position->jabatan?->default_bup
            ?? $position->jenisJabatan?->maks_usia_pensiun;

        if ($bup === null) {
            return null;
        }

        return $employee->tanggal_lahir->copy()->addYearsNoOverflow($bup);
    }

    /**
     * Membuat satu alert EWS dan menyegarkan satu notifikasi in-app selama belum dibaca.
     *
     * @param  bool|null  $isEligible  null = tidak ada eligibility check untuk tipe ini
     * @param  int|null  $satyalancanaYears  milestone dalam tahun; diisi hanya untuk SATYALANCANA
     */
    protected function createAlertIfNotExist(
        Employee $employee,
        string $type,
        string $targetDate,
        int $days,
        string $titleLabel,
        ?bool $isEligible = null,
        ?int $satyalancanaYears = null,
    ): bool {
        $identity = [
            'employee_id' => $employee->id,
            'type' => $type,
            'target_date' => $targetDate,
            'interval_days' => $days,
        ];

        $findAlert = fn () => EwsAlert::query()
            ->where('employee_id', $employee->id)
            ->where('type', $type)
            ->whereDate('target_date', $targetDate)
            ->where('interval_days', $days);
        $alert = $findAlert()->first();
        $wasCreated = false;

        if ($alert === null) {
            try {
                $alert = EwsAlert::create([
                    ...$identity,
                    'is_processed' => false,
                    'is_eligible' => $isEligible,
                    'satyalancana_years' => $satyalancanaYears,
                    'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
                ]);
                $wasCreated = true;
            } catch (QueryException $exception) {
                $alert = $findAlert()->first();

                if ($alert === null) {
                    throw $exception;
                }
            }
        }

        // Pengingat kenaikan pangkat ditahan selama pegawai belum memenuhi syarat kelayakan,
        // baik karena flag kinerja negatif maupun hukuman disiplin aktif. Alert tetap disimpan
        // supaya status kelayakan tetap terbaca pada daftar EWS maupun EWS pribadi, dan
        // notified_at dibiarkan kosong agar alert tidak tampak sudah memberi tahu pegawai.
        // Penahanan sengaja dibatasi pada kenaikan pangkat; kelayakan Satyalancana memakai
        // penilaian manual yang pengingatnya tetap diterbitkan sebagai bahan verifikasi admin.
        if ($type === 'KENAIKAN_PANGKAT' && $isEligible === false) {
            return $wasCreated;
        }

        $timeLabel = $days.' hari';
        if ($days >= 365 && $days % 365 === 0) {
            $timeLabel = ($days / 365).' tahun';
        } elseif ($days >= 30 && $days % 30 === 0) {
            $timeLabel = ($days / 30).' bulan';
        }

        $eligibilityNote = ($isEligible === false)
            ? ' Perhatian: pegawai saat ini belum memenuhi syarat eligibilitas.'
            : '';
        $body = sprintf(
            'Pemberitahuan EWS: Jadwal %s Anda jatuh pada %s (tahap pengingat H-%s). Harap lengkapi berkas.%s',
            $titleLabel,
            Carbon::parse($targetDate)->format('d-m-Y'),
            $timeLabel,
            $eligibilityNote,
        );

        $notification = $this->notificationService->upsertEwsReminder(
            $employee,
            $alert,
            'ews.'.strtolower($type),
            'Peringatan EWS: '.$titleLabel,
            $body,
            [
                'ews_alert_id' => $alert->id,
                'is_eligible' => $isEligible,
            ],
        );

        if ($notification !== null && ! $notification->is_read) {
            $updates = ['notified_at' => now()];

            // Alert kedaluwarsa dari lifecycle lama tidak boleh menyembunyikan
            // reminder yang masih belum dibaca. Status manual tetap dihormati.
            if ($alert->followup_status === EwsAlert::FOLLOWUP_STATUS_EXPIRED) {
                $updates += [
                    'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
                    'is_processed' => false,
                    'handled_at' => null,
                    'handled_by' => null,
                    'handled_note' => null,
                ];
            }

            $alert->forceFill($updates)->save();
        }

        return $wasCreated;
    }
}
