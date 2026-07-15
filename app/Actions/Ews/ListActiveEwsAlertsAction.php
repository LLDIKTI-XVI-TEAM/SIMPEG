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
        'SATYALANCANA' => 'Satyalancana',
    ];

    /** @var array<string, string> */
    private array $followupStatusLabels = [
        'aktif' => 'Aktif',
        'ditangani' => 'Ditangani',
        'tidak_perlu' => 'Tidak Perlu',
        'kedaluwarsa' => 'Kedaluwarsa',
    ];

    public function __construct(private readonly EwsEligibilityService $eligibility) {}

    /**
     * Mengambil alert EWS aktif untuk halaman admin/pimpinan atau pegawai tertentu.
     * Alert non-eligible tetap tampil agar data pegawai bisa ditindaklanjuti.
     *
     * @return array{alerts: array<int, array<string, mixed>>, type_labels: array<string, string>, followup_status_labels: array<string, string>}
     */
    public function execute(
        ?string $filterEvent,
        ?string $filterStatus = null,
        ?string $employeeId = null,
        ?array $employeeIds = null,
    ): array {
        $query = EwsAlert::query()
            ->with(['employee.disciplineRecords', 'handledBy']);

        if ($employeeId !== null && $employeeId !== '') {
            $query->where('employee_id', $employeeId);
        }

        if ($employeeIds !== null) {
            $employeeIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('employee_id', $employeeIds);
        }

        $status = $this->statusFromFilter($filterStatus);
        $status = $this->statusFromFilter($filterStatus);
        if ($filterStatus === 'semua') {
            // Do not filter by followup_status
        } elseif ($filterStatus === '' || $filterStatus === null) {
            $query->where('followup_status', EwsAlert::FOLLOWUP_STATUS_ACTIVE);
        } elseif ($status === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('followup_status', $status);
        }

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
            'followup_status_labels' => $this->followupStatusLabels,
        ];
    }

    /**
     * Mengambil alert aktif untuk daftar pegawai yang sudah dibatasi oleh scope pemanggil.
     *
     * @param  list<string>  $employeeIds
     * @return array{alerts: array<int, array<string, mixed>>, type_labels: array<string, string>, followup_status_labels: array<string, string>}
     */
    public function executeForEmployees(array $employeeIds, ?string $filterEvent, ?string $filterStatus = null): array
    {
        return $this->execute($filterEvent, $filterStatus, null, $employeeIds);
    }

    private function typeFromLabel(?string $label): ?string
    {
        if ($label === null || $label === '') {
            return null;
        }

        $type = array_search($label, $this->typeLabels, true);

        return $type === false ? null : $type;
    }

    private function statusFromFilter(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        return array_key_exists($status, $this->followupStatusLabels) ? $status : null;
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
            'alert_id' => $alert->id,
            'nama' => $employee->nama_lengkap,
            'nip' => $employee->nip,
            'jenis_event' => $this->typeLabels[$alert->type] ?? $alert->type,
            'tanggal_target' => Carbon::parse($alert->target_date)->format('Y-m-d'),
            'sisa_hari' => $sisaHari,
            'followup_status' => $alert->followup_status,
            'followup_status_label' => $this->followupStatusLabels[$alert->followup_status] ?? $alert->followup_status,
            'handled_at' => $alert->handled_at?->format('Y-m-d H:i'),
            'handled_by_name' => $alert->handledBy?->name,
            'handled_note' => $alert->handled_note,
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

        if ($alert->type === 'SATYALANCANA') {
            return $this->eligibility->satyalancana($employee);
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
            'SATYALANCANA' => $this->points([
                $configDays('satyalancana_h180', 180),
                $configDays('satyalancana_h90', 90),
                $configDays('satyalancana_h30', 30),
            ], $dayLabel),
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
