<?php

namespace App\Actions\Ews;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\EwsConfig;
use App\Services\Ews\EwsEligibilityService;
use Carbon\Carbon;
use RuntimeException;

class ListActiveEwsAlertsAction
{
    /** @var array<string, string> */
    private array $typeLabels = [
        'KENAIKAN_PANGKAT' => 'Kenaikan Pangkat',
        'KGB' => 'KGB',
        'PENSIUN' => 'Pensiun',
        'KONTRAK_PPPK' => 'Kontrak PPPK',
    ];

    public function __construct(private readonly EwsEligibilityService $eligibility) {}

    /**
     * Mengambil alert EWS aktif untuk halaman admin dan pimpinan.
     * Alert non-eligible tetap tampil agar data pegawai bisa ditindaklanjuti.
     *
     * @return array{alerts: array<int, array<string, mixed>>, type_labels: array<string, string>}
     */
    public function execute(?string $filterEvent): array
    {
        $query = EwsAlert::query()
            ->with(['employee.disciplineRecords'])
            ->where('is_processed', false);

        if ($filterEvent !== null && $filterEvent !== '') {
            $query->where('type', $this->typeFromLabel($filterEvent) ?? '__invalid__');
        }

        $alerts = $query
            ->orderBy('target_date')
            ->get()
            ->filter(fn (EwsAlert $alert): bool => $alert->employee !== null)
            ->map(fn (EwsAlert $alert): array => $this->mapAlert($alert))
            ->sortBy('sisa_hari')
            ->values()
            ->all();

        return [
            'alerts' => $alerts,
            'type_labels' => $this->typeLabels,
        ];
    }

    private function typeFromLabel(?string $label): ?string
    {
        if ($label === null || $label === '') {
            return null;
        }

        $type = array_search($label, $this->typeLabels, true);

        return $type === false ? null : $type;
    }

    /** @return array<string, mixed> */
    private function mapAlert(EwsAlert $alert): array
    {
        $employee = $alert->employee;
        if (! $employee instanceof Employee) {
            throw new RuntimeException('Alert EWS aktif harus memiliki pegawai agar eligibility dapat dihitung.');
        }

        $sisaHari = (int) now()->startOfDay()->diffInDays(Carbon::parse($alert->target_date)->startOfDay(), false);
        $thresholdMap = $this->thresholdMap();
        $thresholdConfig = $thresholdMap[$alert->type] ?? [];
        $eligibility = $this->eligibilityFor($alert, $employee);

        return [
            'pegawai_id' => $employee->id,
            'nama' => $employee->nama_lengkap,
            'nip' => $employee->nip,
            'jenis_event' => $this->typeLabels[$alert->type] ?? $alert->type,
            'tanggal_target' => Carbon::parse($alert->target_date)->format('Y-m-d'),
            'sisa_hari' => $sisaHari,
            'threshold_label' => $thresholdConfig[$alert->interval_days] ?? 'H-'.$alert->interval_days,
            'threshold_schedule' => array_values($thresholdConfig),
            'is_eligible' => $eligibility['is_eligible'],
            'eligibility_reason' => $eligibility['reason'],
            'eligibility_checks' => $eligibility['checks'],
            'urgency' => $this->urgency($sisaHari),
        ];
    }

    /** @return array{is_eligible: bool, reason: string, checks: array<int, array{label: string, passed: bool}>} */
    private function eligibilityFor(EwsAlert $alert, Employee $employee): array
    {
        if ($alert->type === 'KENAIKAN_PANGKAT') {
            return $this->eligibility->promotion($employee);
        }

        return [
            'is_eligible' => true,
            'reason' => 'Perlu tindak lanjut',
            'checks' => [],
        ];
    }

    /** @return array<string, array<int, string>> */
    private function thresholdMap(): array
    {
        $configDays = fn (string $key, int $default): int => (int) EwsConfig::getVal($key, (string) $default);
        $dayLabel = fn (int $days): string => 'H-'.$days;
        $monthLabel = function (int $days): string {
            if ($days >= 365 && $days % 365 === 0) {
                return 'H-'.((int) ($days / 365)).' tahun';
            }

            if ($days >= 28) {
                return 'H-'.((int) round($days / 30)).' bulan';
            }

            return 'H-'.$days.' hari';
        };

        return [
            'KENAIKAN_PANGKAT' => $this->points([
                $configDays('pangkat_h90', 90),
                $configDays('pangkat_h60', 60),
                $configDays('pangkat_h30', 30),
            ], $dayLabel),
            'KGB' => $this->points([
                $configDays('kgb_h60', 60),
                $configDays('kgb_h30', 30),
                $configDays('kgb_h14', 14),
            ], $dayLabel),
            'PENSIUN' => $this->points([
                $configDays('pensiun_y1', 365),
                $configDays('pensiun_m6', 180),
                $configDays('pensiun_m3', 90),
            ], $monthLabel),
            'KONTRAK_PPPK' => $this->points([
                $configDays('pppk_m6', 180),
                $configDays('pppk_m3', 90),
                $configDays('pppk_m1', 30),
            ], $monthLabel),
        ];
    }

    /**
     * @param  array<int, int>  $days
     * @return array<int, string>
     */
    private function points(array $days, callable $labeler): array
    {
        $mapped = [];
        foreach ($days as $day) {
            $mapped[$day] = $labeler($day);
        }

        return $mapped;
    }

    private function urgency(int $sisaHari): string
    {
        if ($sisaHari < 30) {
            return 'danger';
        }

        if ($sisaHari <= 90) {
            return 'warning';
        }

        return 'success';
    }
}
