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

class TmtCalculatorService
{
    /**
     * Menyinkronkan snapshot tanggal turunan dari riwayat bertanggal terbaru tanpa mengubah riwayat sumber.
     * US-5.5 AC-4,5: Juga menyimpan hasil kalkulasi ke tabel employee_milestones untuk optimisasi scheduler.
     */
    public function syncForEmployee(Employee $employee): void
    {
        $latestRank = $this->latestRank($employee);
        $latestSalary = $this->latestSalary($employee);

        $pangkatRequiredYears = $this->configYears('pangkat_required_years', 4);
        $kgbRequiredYears = $this->configYears('kgb_required_years', 2);

        // Tanpa sumber bertanggal, snapshot lama harus dikosongkan agar tidak dianggap sebagai fakta pegawai.
        $updates = [
            'tanggal_kenaikan_pangkat_berikutnya' => $latestRank?->tmt_pangkat?->copy()->addYearsNoOverflow($pangkatRequiredYears),
            'tanggal_kgb_berikutnya' => $latestSalary?->tmt_kgb?->copy()->addYearsNoOverflow($kgbRequiredYears),
        ];

        // Tanggal pensiun manual/import adalah data resmi sehingga kalkulasi hanya mengisi nilai yang masih kosong.
        // US-5.5 AC-3: Bedakan manual/import (authoritative) vs calculated (dapat di-override)
        //
        // Logic provenance:
        // 1. Jika milestone exists dengan is_manual=true → manual (preserve)
        // 2. Jika tanggal_pensiun exists tapi milestone belum pernah dibuat → manual (import/manual entry)
        // 3. Otherwise → calculated (dapat di-recalculate)
        $existingPensionMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
            ->first(); // Include inactive to detect if milestone ever existed

        $hadManualPensionDate = $employee->tanggal_pensiun !== null
            && (
                // Case 1: Milestone explicitly marked as manual
                ($existingPensionMilestone !== null && ($existingPensionMilestone->metadata['is_manual'] ?? false))
                // Case 2: Has pension date but milestone never created (manual/import entry)
                || $existingPensionMilestone === null
            );

        if (! $hadManualPensionDate) {
            // Always recalculate if not manual (even if tanggal_pensiun is non-null from previous calculation)
            $pensionDate = $this->pensionDate($employee);

            if ($pensionDate !== null) {
                $updates['tanggal_pensiun'] = $pensionDate;
            } elseif ($employee->tanggal_pensiun !== null) {
                // Clear calculated pension date if source data (position/BUP) is gone
                $updates['tanggal_pensiun'] = null;
            }
        }

        $employee->update($updates);

        // US-5.5 AC-5: Simpan hasil kalkulasi ke tabel milestones untuk optimisasi scheduler
        $this->storeMilestones($employee, $latestRank, $latestSalary, $hadManualPensionDate);
    }

    /**
     * US-5.5 AC-4,5: Menyimpan milestone yang sudah dikalkulasi ke tabel terpisah.
     * Scheduler EWS dapat langsung query tabel ini tanpa perlu kalkulasi ulang.
     *
     * Reconciliation: Milestone yang tidak lagi dihasilkan akan dinonaktifkan
     * untuk mencegah scheduler memproses data yang sudah tidak berlaku.
     *
     * @param  bool  $hadManualPensionDate  Apakah tanggal_pensiun sudah ada sebelum sync (manual/import)
     */
    private function storeMilestones(Employee $employee, ?RankHistory $latestRank, ?SalaryHistory $latestSalary, bool $hadManualPensionDate = false): void
    {
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
                ],
                [
                    'milestone_date' => $nextPangkat,
                    'calculated_at' => $today,
                    'metadata' => [
                        'tmt_pangkat' => $latestRank->tmt_pangkat->toDateString(),
                        'golongan_id' => $latestRank->golongan_id,
                        'required_years' => $pangkatRequiredYears,
                    ],
                    'is_active' => true,
                ]
            );
            $activeMilestoneIds[] = $milestone->id;
        } else {
            // Source data hilang: nonaktifkan milestone lama
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
                ],
                [
                    'milestone_date' => $nextKgb,
                    'calculated_at' => $today,
                    'metadata' => [
                        'tmt_kgb' => $latestSalary->tmt_kgb->toDateString(),
                        'gaji_pokok' => $latestSalary->gaji_pokok,
                        'required_years' => $kgbRequiredYears,
                    ],
                    'is_active' => true,
                ]
            );
            $activeMilestoneIds[] = $milestone->id;
        } else {
            // Source data hilang: nonaktifkan milestone lama
            EmployeeMilestone::where('employee_id', $employee->id)
                ->where('type', EmployeeMilestone::TYPE_KGB)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        // 3. Pensiun: Prioritaskan tanggal_pensiun manual, fallback ke kalkulasi BUP
        $pensionDate = null;
        $metadata = ['tanggal_lahir' => $employee->tanggal_lahir?->toDateString()];

        if ($hadManualPensionDate) {
            // Prioritaskan tanggal pensiun manual/impor (sumber resmi)
            $pensionDate = $employee->tanggal_pensiun;
            $metadata['is_manual'] = true;
            $metadata['source'] = 'employees.tanggal_pensiun';
            $metadata['bup'] = null;
            $metadata['jabatan'] = null;
        } else {
            // Fallback: hitung dari BUP jabatan (tanggal_pensiun baru saja dikalkulasi oleh sync)
            $pensionDate = $employee->tanggal_pensiun;
            if ($pensionDate !== null) {
                $position = $this->latestPosition($employee);
                $bup = $position?->jabatan?->default_bup ?? $position?->jenisJabatan?->maks_usia_pensiun;
                $metadata['is_manual'] = false;
                $metadata['source'] = 'calculated_from_bup';
                $metadata['bup'] = $bup;
                $metadata['jabatan'] = $position?->jabatan?->nama ?? null;
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
                    'milestone_date' => $pensionDate,
                ],
                [
                    'calculated_at' => $today,
                    'metadata' => $metadata,
                    'is_active' => true,
                ]
            );
            $activeMilestoneIds[] = $milestone->id;
        } else {
            // Source data hilang: nonaktifkan milestone lama
            EmployeeMilestone::where('employee_id', $employee->id)
                ->where('type', EmployeeMilestone::TYPE_PENSIUN)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        // 4. US-5.5 AC-4: Satyalancana (10, 20, 30 tahun dari pengangkatan pertama)
        $tmtPengangkatan = $this->firstAppointmentDate($employee);
        if ($tmtPengangkatan !== null) {
            $currentSatyalancanaDates = [];
            $currentSatyalancanaMilestoneIds = [];

            foreach ([10, 20, 30] as $years) {
                $satyalancanaDate = $tmtPengangkatan->copy()->addYearsNoOverflow($years);

                $milestone = EmployeeMilestone::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'type' => EmployeeMilestone::TYPE_SATYALANCANA,
                        'milestone_date' => $satyalancanaDate,
                    ],
                    [
                        'calculated_at' => $today,
                        'metadata' => [
                            'tmt_pengangkatan' => $tmtPengangkatan->toDateString(),
                            'satyalancana_years' => $years,
                            'years_of_service' => $years, // Backward compatibility
                        ],
                        'is_active' => true,
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
            // Source data hilang: nonaktifkan semua milestone Satyalancana
            EmployeeMilestone::where('employee_id', $employee->id)
                ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        // 5. PPPK Contract End (jika ada)
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
                    'milestone_date' => $employee->tanggal_akhir_kontrak,
                ],
                [
                    'calculated_at' => $today,
                    'metadata' => [
                        'contract_type' => 'PPPK',
                    ],
                    'is_active' => true,
                ]
            );
            $activeMilestoneIds[] = $milestone->id;
        } else {
            // Source data hilang: nonaktifkan milestone lama
            EmployeeMilestone::where('employee_id', $employee->id)
                ->where('type', EmployeeMilestone::TYPE_PPPK_CONTRACT_END)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }
    }

    /**
     * US-5.5 AC-4: Ambil TMT pengangkatan pertama dari tabel appointments.
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

    private function pensionDate(Employee $employee): ?Carbon
    {
        $position = $this->latestPosition($employee);
        $bup = $position?->jabatan?->default_bup
            ?? $position?->jenisJabatan?->maks_usia_pensiun;

        if ($employee->tanggal_lahir === null || $bup === null) {
            return null;
        }

        return $employee->tanggal_lahir->copy()->addYearsNoOverflow($bup);
    }
}
