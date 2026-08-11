<?php

namespace App\Services\Employees;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\EwsConfig;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\SalaryHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TmtCalculatorService
{
    private const PENSION_SOURCE_CALCULATED = 'calculated_from_bup';

    private const PENSION_SOURCE_GLOBAL_CONFIG = 'calculated_from_global_config';

    private const PENSION_SOURCE_IMPORT = 'employee_import';

    private const PENSION_SOURCE_LEGACY_UNVERIFIED = 'legacy_unverified';

    private const PENSION_SOURCE_OFFICIAL = 'employees.tanggal_pensiun';

    /**
     * Menyinkronkan snapshot tanggal turunan dari riwayat bertanggal terbaru tanpa mengubah riwayat sumber.
     * Hint provenance dipakai saat tanggal pensiun resmi diubah atau dikosongkan oleh Admin.
     */
    public function syncForEmployee(
        Employee $employee,
        ?bool $pensionDateIsAuthoritative = null,
        bool $recalculateLegacyPension = false,
    ): void {
        DB::transaction(function () use ($employee, $pensionDateIsAuthoritative, $recalculateLegacyPension): void {
            // Semua writer milestone berbagi kunci baris pegawai agar backfill dan mutasi
            // profil tidak menghitung sumber yang sama secara bersamaan.
            $lockedEmployee = Employee::query()
                ->whereKey($employee->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->syncLockedEmployee(
                $lockedEmployee,
                $pensionDateIsAuthoritative,
                $recalculateLegacyPension,
            );
        });
    }

    /** Menyinkronkan snapshot setelah baris pegawai terkunci dan sumber dimuat ulang. */
    private function syncLockedEmployee(
        Employee $employee,
        ?bool $pensionDateIsAuthoritative,
        bool $recalculateLegacyPension,
    ): void {
        $latestRank = $this->latestRank($employee);
        $latestSalary = $this->latestSalary($employee);

        $pangkatRequiredYears = $this->configYears('pangkat_required_years', 4);
        $kgbRequiredYears = $this->configYears('kgb_required_years', 2);

        // Tanpa sumber bertanggal, snapshot lama harus dikosongkan agar tidak dianggap sebagai fakta pegawai.
        $updates = [
            'tanggal_kenaikan_pangkat_berikutnya' => $latestRank?->tmt_pangkat?->copy()->addYearsNoOverflow($pangkatRequiredYears),
            'tanggal_kgb_berikutnya' => $latestSalary?->tmt_kgb?->copy()->addYearsNoOverflow($kgbRequiredYears),
        ];

        // Tanggal resmi dari form atau impor harus dipertahankan; hasil kalkulasi boleh dihitung ulang.
        $existingPensionMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
            ->where('milestone_key', EmployeeMilestone::KEY_DEFAULT)
            ->where('is_active', true)
            ->first();

        $pensionSource = $this->resolvePensionSource(
            $employee,
            $existingPensionMilestone,
            $pensionDateIsAuthoritative,
            $recalculateLegacyPension,
        );
        $preservePensionDate = in_array($pensionSource, [
            self::PENSION_SOURCE_OFFICIAL,
            self::PENSION_SOURCE_LEGACY_UNVERIFIED,
        ], true);

        $pensionCalculation = null;

        if (! $preservePensionDate) {
            $pensionCalculation = $this->pensionCalculation($employee);
            $pensionDate = $pensionCalculation['date'] ?? null;

            if ($pensionDate !== null) {
                $updates['tanggal_pensiun'] = $pensionDate;
            } elseif ($employee->tanggal_pensiun !== null) {
                // Hasil kalkulasi sebelumnya dibersihkan ketika sumber jabatan atau BUP tidak lagi tersedia.
                $updates['tanggal_pensiun'] = null;
            }
        }

        $employee->update($updates);

        $this->storeMilestones(
            $employee,
            $latestRank,
            $latestSalary,
            $pensionSource,
            $pensionCalculation,
        );
    }

    /**
     * Mencatat provenance tanggal pensiun hasil import tanpa menjalankan kalkulasi TMT lain.
     * Batas ini melindungi snapshot import dan mencegah pembuatan milestone yang tidak memiliki riwayat resmi.
     */
    public function recordImportedPensionDate(Employee $employee): void
    {
        if ($employee->tanggal_pensiun === null) {
            return;
        }

        EmployeeMilestone::updateOrCreate(
            [
                'employee_id' => $employee->id,
                'type' => EmployeeMilestone::TYPE_PENSIUN,
                'milestone_key' => EmployeeMilestone::KEY_DEFAULT,
                'is_active' => true,
            ],
            [
                'milestone_date' => $employee->tanggal_pensiun,
                'calculated_at' => now()->startOfDay(),
                'metadata' => [
                    'tanggal_lahir' => $employee->tanggal_lahir?->toDateString(),
                    'is_manual' => true,
                    'source' => self::PENSION_SOURCE_IMPORT,
                    'bup' => null,
                    'jabatan' => null,
                ],
            ],
        );
    }

    /**
     * Menyimpan milestone yang sudah dikalkulasi ke tabel terpisah.
     * Scheduler EWS dapat langsung query tabel ini tanpa perlu kalkulasi ulang.
     *
     * Milestone yang tidak lagi dihasilkan akan dinonaktifkan
     * untuk mencegah scheduler memproses data yang sudah tidak berlaku.
     *
     * @param  string  $pensionSource  Provenance eksplisit untuk melindungi tanggal resmi dan legacy
     * @param  array{date: Carbon, bup: int, source: string, bup_source: string, jabatan: ?string, config_key: ?string}|null  $pensionCalculation
     */
    private function storeMilestones(
        Employee $employee,
        ?RankHistory $latestRank,
        ?SalaryHistory $latestSalary,
        string $pensionSource,
        ?array $pensionCalculation,
    ): void {
        $today = now()->startOfDay();
        $pangkatRequiredYears = $this->configYears('pangkat_required_years', 4);
        $kgbRequiredYears = $this->configYears('kgb_required_years', 2);

        $activeMilestoneIds = [];

        // 1. Kenaikan Pangkat
        if ($latestRank?->tmt_pangkat !== null) {
            $nextPangkat = $latestRank->tmt_pangkat->copy()->addYearsNoOverflow($pangkatRequiredYears);

            $milestone = EmployeeMilestone::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'type' => EmployeeMilestone::TYPE_KENAIKAN_PANGKAT,
                    'milestone_key' => EmployeeMilestone::KEY_DEFAULT,
                    'is_active' => true,
                ],
                [
                    'milestone_date' => $nextPangkat,
                    'calculated_at' => $today,
                    'metadata' => [
                        'tmt_pangkat' => $latestRank->tmt_pangkat->toDateString(),
                        'golongan_id' => $latestRank->golongan_id,
                        'required_years' => $pangkatRequiredYears,
                    ],
                ]
            );
            $activeMilestoneIds[] = $milestone->id;
        } else {
            // Sumber data hilang: nonaktifkan milestone lama agar scheduler tidak memakai tanggal kedaluwarsa.
            EmployeeMilestone::where('employee_id', $employee->id)
                ->where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        // 2. KGB
        if ($latestSalary?->tmt_kgb !== null) {
            $nextKgb = $latestSalary->tmt_kgb->copy()->addYearsNoOverflow($kgbRequiredYears);

            $milestone = EmployeeMilestone::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'type' => EmployeeMilestone::TYPE_KGB,
                    'milestone_key' => EmployeeMilestone::KEY_DEFAULT,
                    'is_active' => true,
                ],
                [
                    'milestone_date' => $nextKgb,
                    'calculated_at' => $today,
                    'metadata' => [
                        'tmt_kgb' => $latestSalary->tmt_kgb->toDateString(),
                        'gaji_pokok' => $latestSalary->gaji_pokok,
                        'required_years' => $kgbRequiredYears,
                    ],
                ]
            );
            $activeMilestoneIds[] = $milestone->id;
        } else {
            // Sumber data hilang: nonaktifkan milestone lama agar scheduler tidak memakai tanggal kedaluwarsa.
            EmployeeMilestone::where('employee_id', $employee->id)
                ->where('type', EmployeeMilestone::TYPE_KGB)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        // Tanggal pensiun resmi diprioritaskan; BUP hanya digunakan ketika tanggal tersebut belum ada.
        $pensionDate = null;
        $metadata = ['tanggal_lahir' => $employee->tanggal_lahir?->toDateString()];

        if (in_array($pensionSource, [self::PENSION_SOURCE_OFFICIAL, self::PENSION_SOURCE_LEGACY_UNVERIFIED], true)) {
            // Nilai resmi dan legacy yang belum diverifikasi diperlakukan authoritative agar tidak tertimpa diam-diam.
            $pensionDate = $employee->tanggal_pensiun;
            $metadata['is_manual'] = true;
            $metadata['source'] = $pensionSource;
            $metadata['bup'] = null;
            $metadata['jabatan'] = null;
        } else {
            // Simpan sumber kalkulasi yang sama dengan snapshot agar scheduler dapat mengaudit fallback-nya.
            $pensionDate = $pensionCalculation['date'] ?? null;
            if ($pensionCalculation !== null) {
                $metadata['is_manual'] = false;
                $metadata['source'] = $pensionCalculation['source'];
                $metadata['bup_source'] = $pensionCalculation['bup_source'];
                $metadata['bup'] = $pensionCalculation['bup'];
                $metadata['jabatan'] = $pensionCalculation['jabatan'];
                $metadata['config_key'] = $pensionCalculation['config_key'];
            }
        }

        if ($pensionDate !== null) {
            // Deactivate any existing active pension milestone with a different date —
            // this creates a new row instead of mutating the old one in-place.
            EmployeeMilestone::where('employee_id', $employee->id)
                ->where('type', EmployeeMilestone::TYPE_PENSIUN)
                ->where('is_active', true)
                ->where('milestone_date', '!=', $pensionDate->toDateString())
                ->update(['is_active' => false]);

            $milestone = EmployeeMilestone::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'type' => EmployeeMilestone::TYPE_PENSIUN,
                    'milestone_key' => EmployeeMilestone::KEY_DEFAULT,
                    'is_active' => true,
                ],
                [
                    'calculated_at' => $today,
                    'metadata' => $metadata,
                ]
            );
            $activeMilestoneIds[] = $milestone->id;
        } else {
            // Sumber data hilang: nonaktifkan milestone lama agar scheduler tidak memakai tanggal kedaluwarsa.
            EmployeeMilestone::where('employee_id', $employee->id)
                ->where('type', EmployeeMilestone::TYPE_PENSIUN)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        // Satyalancana dihitung dari TMT pengangkatan pertama.
        $tmtPengangkatan = $this->firstAppointmentDate($employee);
        if ($tmtPengangkatan !== null) {
            $currentSatyalancanaMilestoneIds = [];

            foreach ([10, 20, 30] as $years) {
                $satyalancanaDate = $tmtPengangkatan->copy()->addYearsNoOverflow($years);
                $milestoneKey = (string) $years;

                $activeMilestone = EmployeeMilestone::query()
                    ->where('employee_id', $employee->id)
                    ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
                    ->where('milestone_key', $milestoneKey)
                    ->where('is_active', true)
                    ->first();

                if ($activeMilestone !== null && ! $activeMilestone->milestone_date->isSameDay($satyalancanaDate)) {
                    // Perubahan TMT menghasilkan versi baru; versi lama tetap disimpan sebagai jejak kalkulasi.
                    $activeMilestone->update(['is_active' => false]);
                }

                $milestone = EmployeeMilestone::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'type' => EmployeeMilestone::TYPE_SATYALANCANA,
                        'milestone_key' => $milestoneKey,
                        'is_active' => true,
                    ],
                    [
                        'milestone_date' => $satyalancanaDate,
                        'calculated_at' => $today,
                        'metadata' => [
                            'tmt_pengangkatan' => $tmtPengangkatan->toDateString(),
                            'satyalancana_years' => $years,
                            'years_of_service' => $years, // Dipertahankan agar metadata lama tetap dapat dibaca.
                        ],
                    ]
                );
                $currentSatyalancanaMilestoneIds[] = $milestone->id;
            }

            // Nonaktifkan Satyalancana milestone lama yang tidak lagi di-generate
            // (misalnya TMT pengangkatan berubah)
            EmployeeMilestone::where('employee_id', $employee->id)
                ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
                ->where('is_active', true)
                ->whereNotIn('id', $currentSatyalancanaMilestoneIds)
                ->update(['is_active' => false]);
        } else {
            // Sumber data hilang: nonaktifkan semua milestone Satyalancana.
            EmployeeMilestone::where('employee_id', $employee->id)
                ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        // Akhir kontrak PPPK dicatat bila tanggal kontraknya tersedia.
        if ($employee->tanggal_akhir_kontrak !== null) {
            // Deactivate any existing active PPPK milestone with a different date —
            // this creates a new row instead of mutating the old one in-place.
            EmployeeMilestone::where('employee_id', $employee->id)
                ->where('type', EmployeeMilestone::TYPE_PPPK_CONTRACT_END)
                ->where('is_active', true)
                ->where('milestone_date', '!=', $employee->tanggal_akhir_kontrak->toDateString())
                ->update(['is_active' => false]);

            $milestone = EmployeeMilestone::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'type' => EmployeeMilestone::TYPE_PPPK_CONTRACT_END,
                    'milestone_key' => EmployeeMilestone::KEY_DEFAULT,
                    'is_active' => true,
                ],
                [
                    'calculated_at' => $today,
                    'metadata' => [
                        'contract_type' => 'PPPK',
                    ],
                ]
            );
            $activeMilestoneIds[] = $milestone->id;
        } else {
            // Sumber data hilang: nonaktifkan milestone lama agar scheduler tidak memakai tanggal kedaluwarsa.
            EmployeeMilestone::where('employee_id', $employee->id)
                ->where('type', EmployeeMilestone::TYPE_PPPK_CONTRACT_END)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }
    }

    /**
     * Mengambil TMT pengangkatan pertama sebagai dasar perhitungan Satyalancana.
     */
    private function firstAppointmentDate(Employee $employee): ?Carbon
    {
        $appointment = Appointment::where('employee_id', $employee->id)
            ->whereNotNull('tmt_pengangkatan')
            ->orderBy('tmt_pengangkatan', 'asc')
            ->orderBy('created_at', 'asc')
            ->first();

        return $appointment?->tmt_pengangkatan;
    }

    /**
     * Menentukan provenance tanpa menebak apakah tanggal legacy kebetulan sama dengan hasil BUP.
     * Nilai lama tanpa milestone dipertahankan sampai operator memilih kalkulasi ulang secara eksplisit.
     */
    private function resolvePensionSource(
        Employee $employee,
        ?EmployeeMilestone $existingMilestone,
        ?bool $pensionDateIsAuthoritative,
        bool $recalculateLegacyPension,
    ): string {
        if ($employee->tanggal_pensiun === null || $pensionDateIsAuthoritative === false) {
            return self::PENSION_SOURCE_CALCULATED;
        }

        if ($pensionDateIsAuthoritative === true) {
            return self::PENSION_SOURCE_OFFICIAL;
        }

        $existingSource = $existingMilestone?->metadata['source'] ?? null;
        $isLegacy = $existingMilestone === null
            || $existingSource === self::PENSION_SOURCE_LEGACY_UNVERIFIED;

        if ($isLegacy) {
            return $recalculateLegacyPension
                ? self::PENSION_SOURCE_CALCULATED
                : self::PENSION_SOURCE_LEGACY_UNVERIFIED;
        }

        if (($existingMilestone->metadata['is_manual'] ?? false) === true) {
            return self::PENSION_SOURCE_OFFICIAL;
        }

        return self::PENSION_SOURCE_CALCULATED;
    }

    private function configYears(string $key, int $default): int
    {
        return max(1, (int) EwsConfig::getVal($key, (string) $default));
    }

    private function latestRank(Employee $employee): ?RankHistory
    {
        return $employee->rankHistories()
            ->whereNotNull('tmt_pangkat')
            ->orderByDesc('tmt_pangkat')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function latestSalary(Employee $employee): ?SalaryHistory
    {
        return $employee->salaryHistories()
            ->whereNotNull('tmt_kgb')
            ->orderByDesc('tmt_kgb')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function latestPosition(Employee $employee): ?PositionHistory
    {
        return $employee->positionHistories()
            ->with(['jabatan', 'jenisJabatan'])
            ->whereNotNull('tmt_jabatan')
            ->orderByDesc('tmt_jabatan')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Menentukan tanggal dan provenance BUP dengan urutan jabatan, jenis jabatan,
     * lalu konfigurasi global sebagai fallback paling akhir.
     *
     * @return array{date: Carbon, bup: int, source: string, bup_source: string, jabatan: ?string, config_key: ?string}|null
     */
    private function pensionCalculation(Employee $employee): ?array
    {
        if ($employee->tanggal_lahir === null) {
            return null;
        }

        $position = $this->latestPosition($employee);
        $positionBup = (int) ($position?->jabatan?->default_bup ?? 0);
        $positionBupSource = 'ref_jabatan.default_bup';

        if ($positionBup <= 0) {
            $positionBup = (int) ($position?->jenisJabatan?->maks_usia_pensiun ?? 0);
            $positionBupSource = 'ref_jenis_jabatan.maks_usia_pensiun';
        }

        if ($positionBup > 0) {
            return [
                'date' => $employee->tanggal_lahir->copy()->addYearsNoOverflow($positionBup),
                'bup' => $positionBup,
                'source' => self::PENSION_SOURCE_CALCULATED,
                'bup_source' => $positionBupSource,
                'jabatan' => $position?->jabatan?->nama,
                'config_key' => null,
            ];
        }

        $globalBup = max(0, (int) EwsConfig::getVal('pensiun_required_age_years', '0'));

        if ($globalBup === 0) {
            return null;
        }

        return [
            'date' => $employee->tanggal_lahir->copy()->addYearsNoOverflow($globalBup),
            'bup' => $globalBup,
            'source' => self::PENSION_SOURCE_GLOBAL_CONFIG,
            'bup_source' => 'ews_configs.value',
            'jabatan' => null,
            'config_key' => 'pensiun_required_age_years',
        ];
    }
}
