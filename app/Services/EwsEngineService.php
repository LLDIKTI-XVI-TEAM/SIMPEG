<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\EwsAlert;
use App\Models\EwsConfig;
use App\Models\EwsSchedulerRun;
use App\Models\RefStatusPegawai;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
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

            // Gunakan milestone terhitung untuk mengurangi query, lalu hitung langsung
            // bila backfill belum tersedia atau versi konfigurasinya sudah berubah.
            // Muat relasi untuk perhitungan cadangan agar tidak terjadi N+1 sebelum milestone direkonsiliasi.
            Employee::with([
                'milestones' => fn ($q) => $q->where('is_active', true),
                'statusPegawai:id,kelompok',
                'jenisPegawai',
                'disciplineRecords',
                'rankHistories',
                'salaryHistories',
                'appointments',
            ])
                // Satu-satunya predicate lifecycle: Aktif dan Aktif/khusus boleh diproses.
                // Status hilang/tidak valid tidak boleh menghasilkan alert maupun fan-out admin.
                ->withActiveLifecycleStatus()
                ->chunkById(100, function ($employees) use (
                    $pangkatDays, $kgbDays, $pensiunDays, $pppkDays, $satyalancanaDays,
                    $pangkatRequiredYears, $kgbRequiredYears, $pensiunRequiredAgeYears,
                    $pppkContractYears, $satyalancanaYears,
                    &$alertsCreated, &$employeesChecked
                ): void {
                    // Hanya pegawai yang benar-benar memerlukan fallback BUP yang memuat riwayat jabatan.
                    // Jalur milestone normal tetap membaca snapshot terhitung tanpa query riwayat tambahan.
                    $employees
                        ->filter(fn (Employee $employee): bool => $this->needsPositionPensionFallback($employee))
                        ->load([
                            'positionHistories' => fn ($query) => $query
                                ->with(['jabatan', 'jenisJabatan'])
                                ->whereNotNull('tmt_jabatan')
                                ->orderByDesc('tmt_jabatan')
                                ->orderByDesc('created_at')
                                ->orderByDesc('id'),
                        ]);

                    foreach ($employees as $employee) {
                        $employeesChecked++;

                        // Kenaikan pangkat memakai milestone hanya bila versinya masih sesuai konfigurasi.
                        $targetDate = $this->getMilestoneDate($employee, 'kenaikan_pangkat', $pangkatRequiredYears)
                            ?? $this->calculatePangkatDate($employee, $pangkatRequiredYears);

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
                                    sendNotification: $isEligible,
                                );
                                if ($created) {
                                    $alertsCreated++;
                                }
                            }
                        }

                        // KGB memakai milestone hanya bila versinya masih sesuai konfigurasi.
                        $targetDate = $this->getMilestoneDate($employee, 'kgb', $kgbRequiredYears)
                            ?? $this->calculateKgbDate($employee, $kgbRequiredYears);

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

                        // Pensiun memakai milestone bila tersedia; selain itu dihitung dari sumber data saat ini.
                        $targetDate = $this->getMilestoneDate($employee, 'pensiun')
                            ?? $this->calculatePensionDate($employee, $pensiunRequiredAgeYears);

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

                        // Akhir kontrak PPPK memakai milestone bila tersedia; selain itu dihitung dari sumber data saat ini.
                        $isPppk = $employee->jenisPegawai && strtolower($employee->jenisPegawai->nama) === 'pppk';
                        if ($isPppk) {
                            $targetDate = $this->getMilestoneDate($employee, 'pppk_contract_end')
                                ?? $this->calculatePppkContractDate($employee, $pppkContractYears);

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

                        // Satyalancana memakai milestone bila tersedia; selain itu dihitung dari TMT pengangkatan.
                        $satyalancanaMilestones = $this->getSatyalancanaMilestones($employee, $satyalancanaYears);
                        $isEligible = $employee->is_satyalancana_eligible === true;

                        foreach ($satyalancanaMilestones as $milestone) {
                            $targetDate = $milestone['date'];
                            $years = $milestone['years'];
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
                ->whereHas('employee.statusPegawai', fn ($statuses) => $statuses
                    ->whereIn('kelompok', RefStatusPegawai::activeGroups()))
                ->with(['employee.statusPegawai'])
                ->get();

            foreach ($superAdmins as $admin) {
                $employee = $admin->employee;
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
     * Mengambil tanggal milestone hanya ketika versinya masih sesuai dengan konfigurasi aktif.
     * Versi yang berbeda diabaikan supaya tanggal dihitung ulang dari sumber data terbaru.
     *
     * @param  int|null  $currentRequiredYears  Nilai konfigurasi aktif; null bila tipe tidak bergantung konfigurasi
     */
    private function getMilestoneDate(Employee $employee, string $type, ?int $currentRequiredYears = null): ?Carbon
    {
        $milestone = $employee->milestones
            ->where('type', $type)
            ->where('milestone_key', EmployeeMilestone::KEY_DEFAULT)
            ->where('is_active', true)
            ->first();

        if ($milestone === null) {
            return null;
        }

        // Untuk milestone yang bergantung pada konfigurasi (pangkat, KGB),
        // validasi bahwa required_years di metadata cocok dengan konfigurasi saat ini.
        // Jika tidak cocok, abaikan milestone lama dan hitung ulang dari sumber terbaru.
        if ($currentRequiredYears !== null && isset($milestone->metadata['required_years'])) {
            $storedRequiredYears = (int) $milestone->metadata['required_years'];

            if ($storedRequiredYears !== $currentRequiredYears) {
                // Milestone lama tidak boleh menghasilkan tanggal berdasarkan aturan yang sudah berubah.
                Log::info("Milestone '{$type}' for employee {$employee->id} uses outdated config (stored: {$storedRequiredYears}, current: {$currentRequiredYears}). Using fallback calculation.");

                return null;
            }
        }

        return Carbon::parse($milestone->milestone_date);
    }

    /**
     * Menghitung tanggal kenaikan pangkat ketika milestone belum tersedia.
     */
    private function calculatePangkatDate(Employee $employee, int $requiredYears): ?Carbon
    {
        $latestRank = $employee->rankHistories
            ->filter(fn ($history): bool => $history->tmt_pangkat !== null)
            ->sortByDesc('tmt_pangkat')
            ->first();

        if ($latestRank) {
            return Carbon::parse($latestRank->tmt_pangkat)->addYears($requiredYears);
        }

        return $employee->tanggal_kenaikan_pangkat_berikutnya
            ? Carbon::parse($employee->tanggal_kenaikan_pangkat_berikutnya)
            : null;
    }

    /**
     * Menghitung tanggal KGB ketika milestone belum tersedia.
     */
    private function calculateKgbDate(Employee $employee, int $requiredYears): ?Carbon
    {
        $latestKgb = $employee->salaryHistories
            ->filter(fn ($history): bool => $history->tmt_kgb !== null)
            ->sortByDesc('tmt_kgb')
            ->first();

        if ($latestKgb) {
            return Carbon::parse($latestKgb->tmt_kgb)->addYears($requiredYears);
        }

        return $employee->tanggal_kgb_berikutnya
            ? Carbon::parse($employee->tanggal_kgb_berikutnya)
            : null;
    }

    /**
     * Menghitung tanggal pensiun ketika milestone belum tersedia.
     */
    private function calculatePensionDate(Employee $employee, int $pensiunRequiredAgeYears): ?Carbon
    {
        // Prioritaskan tanggal_pensiun manual jika sudah diset
        if ($employee->tanggal_pensiun) {
            return Carbon::parse($employee->tanggal_pensiun);
        }

        // Presedensi BUP kanonik: jabatan detail, jenis jabatan, lalu konfigurasi global.
        $positionPensionDate = $this->calculatePensionFromPositionBup($employee);
        if ($positionPensionDate !== null) {
            return $positionPensionDate;
        }

        if ($pensiunRequiredAgeYears > 0 && $employee->tanggal_lahir) {
            return Carbon::parse($employee->tanggal_lahir)->addYearsNoOverflow($pensiunRequiredAgeYears);
        }

        return null;
    }

    /**
     * Menghitung tanggal akhir kontrak PPPK ketika milestone belum tersedia.
     */
    private function calculatePppkContractDate(Employee $employee, int $contractYears): ?Carbon
    {
        // Baca dari employees.tanggal_akhir_kontrak terlebih dahulu
        if ($employee->tanggal_akhir_kontrak) {
            return Carbon::parse($employee->tanggal_akhir_kontrak);
        }

        // Fallback: TMT pengangkatan PPPK terbaru + masa kontrak global
        $pppkApp = $employee->appointments
            ->filter(fn ($a): bool => strtoupper((string) $a->jenis_pengangkatan) === 'PPPK' && $a->tmt_pengangkatan !== null)
            ->sortByDesc('tmt_pengangkatan')
            ->first();

        if ($pppkApp) {
            return Carbon::parse($pppkApp->tmt_pengangkatan)->addYears($contractYears);
        }

        return null;
    }

    /**
     * Mengambil milestone Satyalancana terhitung atau menghitungnya dari TMT pengangkatan.
     *
     * @param  list<int>  $configuredYears  Daftar masa kerja yang aktif pada konfigurasi
     * @return list<array{date: Carbon, years: int}>
     */
    private function getSatyalancanaMilestones(Employee $employee, array $configuredYears): array
    {
        $milestones = [];

        // Gunakan milestone terhitung terlebih dahulu untuk menjaga pemindaian tetap efisien.
        $precomputedMilestones = $employee->milestones
            ->where('type', 'satyalancana')
            ->where('is_active', true);

        if ($precomputedMilestones->isNotEmpty()) {
            foreach ($precomputedMilestones as $milestone) {
                $years = $milestone->metadata['satyalancana_years'] ?? null;
                if ($years !== null
                    && $milestone->milestone_key === (string) $years
                    && in_array($years, $configuredYears, true)) {
                    $milestones[] = [
                        'date' => Carbon::parse($milestone->milestone_date),
                        'years' => $years,
                    ];
                }
            }

            // Semua masa kerja telah tersedia sehingga tidak perlu menghitung ulang.
            if (count($milestones) === count($configuredYears)) {
                return $milestones;
            }
        }

        // Hitung dari TMT pengangkatan pertama bila milestone belum lengkap.
        $firstAppointment = $employee->appointments
            ->filter(fn ($appointment): bool => $appointment->tmt_pengangkatan !== null)
            ->sortBy('tmt_pengangkatan')
            ->first();

        if ($firstAppointment) {
            $firstTmt = Carbon::parse($firstAppointment->tmt_pengangkatan)->startOfDay();
            $milestones = [];

            foreach ($configuredYears as $years) {
                $milestones[] = [
                    'date' => $firstTmt->copy()->addYears($years),
                    'years' => $years,
                ];
            }
        }

        return $milestones;
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

        $position = $employee->positionHistories->first();

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

    /** Menentukan apakah scheduler perlu memuat sumber BUP untuk fallback pensiun. */
    private function needsPositionPensionFallback(Employee $employee): bool
    {
        $hasPensionMilestone = $employee->milestones
            ->contains(fn ($milestone): bool => $milestone->type === EmployeeMilestone::TYPE_PENSIUN
                && $milestone->milestone_key === EmployeeMilestone::KEY_DEFAULT);

        return ! $hasPensionMilestone
            && $employee->tanggal_pensiun === null
            && $employee->tanggal_lahir !== null;
    }

    /**
     * Membuat satu alert EWS dan menyegarkan satu notifikasi in-app selama belum dibaca.
     *
     * @param  bool|null  $isEligible  null = tidak ada eligibility check untuk tipe ini
     * @param  int|null  $satyalancanaYears  milestone dalam tahun; diisi hanya untuk SATYALANCANA
     * @param  bool  $sendNotification  apakah notifikasi harus dikirim (default true); false = hanya buat alert tanpa notif
     */
    protected function createAlertIfNotExist(
        Employee $employee,
        string $type,
        string $targetDate,
        int $days,
        string $titleLabel,
        ?bool $isEligible = null,
        ?int $satyalancanaYears = null,
        bool $sendNotification = true,
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

        // Status kelayakan yang tersimpan harus mengikuti keadaan pegawai saat penjadwalan
        // berjalan, bukan keadaan saat alert pertama kali dibuat. Kolom ini menjelaskan alasan
        // sebuah pengingat ditahan atau diterbitkan, sehingga nilai yang tertinggal akan
        // menyesatkan pembaca yang memakainya tanpa menghitung ulang kelayakan.
        if ($isEligible !== null && $alert->is_eligible !== $isEligible) {
            $alert->forceFill(['is_eligible' => $isEligible])->save();
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

        // Notifikasi belum dibaca yang sudah ada tetap diselaraskan saat kelayakan berubah.
        // Nilai false hanya mencegah pembuatan notifikasi baru untuk pegawai tidak layak.
        $notificationData = [
            'ews_alert_id' => $alert->id,
            'is_eligible' => $isEligible,
        ];
        $notification = $sendNotification
            ? $this->notificationService->upsertEwsReminder(
                $employee,
                $alert,
                'ews.'.strtolower($type),
                'Peringatan EWS: '.$titleLabel,
                $body,
                $notificationData,
            )
            : $this->notificationService->upsertEwsReminder(
                $employee,
                $alert,
                'ews.'.strtolower($type),
                'Peringatan EWS: '.$titleLabel,
                $body,
                $notificationData,
                createIfMissing: false,
            );

        if ($notification !== null) {
            $updates = [];

            // Waktu pemberitahuan hanya diperbarui bila notifikasinya belum dibaca.
            if (! $notification->is_read) {
                $updates['notified_at'] = now();

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
            }

            if (! empty($updates)) {
                $alert->forceFill($updates)->save();
            }
        }

        return $wasCreated;
    }
}
