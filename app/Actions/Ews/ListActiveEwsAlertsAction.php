<?php

namespace App\Actions\Ews;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\EwsConfig;
use App\Services\Ews\EwsEligibilityService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use RuntimeException;

class ListActiveEwsAlertsAction
{
    /** @var array<string, string> */
    private const TYPE_LABELS = [
        'KENAIKAN_PANGKAT' => 'Kenaikan Pangkat',
        'KGB' => 'KGB',
        'PENSIUN' => 'Pensiun',
        'KONTRAK_PPPK' => 'Kontrak PPPK',
        'SATYALANCANA' => 'Satyalancana',
    ];

    /** @var array<string, string> */
    private const FOLLOWUP_STATUS_LABELS = [
        'aktif' => 'Aktif',
        'ditangani' => 'Ditangani',
        'tidak_perlu' => 'Tidak Perlu',
        'kedaluwarsa' => 'Kedaluwarsa',
    ];

    public function __construct(private readonly EwsEligibilityService $eligibility) {}

    /** @return list<string> */
    public static function allowedEventFilters(): array
    {
        return ['semua', ...array_values(self::TYPE_LABELS)];
    }

    /** @return list<string> */
    public static function allowedStatusFilters(): array
    {
        return ['semua', ...array_keys(self::FOLLOWUP_STATUS_LABELS)];
    }

    /**
     * Pagination dilakukan sebelum eager load dan mapping agar jumlah row serta query
     * tetap bounded. Search hanya menyentuh field identitas minimum pegawai.
     *
     * @param  list<string>|null  $employeeIds
     * @return array{alerts: LengthAwarePaginator, type_labels: array<string, string>, followup_status_labels: array<string, string>, summary: array{total: int, urgent: int, warning: int, info: int}}
     */
    public function paginate(
        ?string $filterEvent,
        ?string $filterStatus,
        ?string $search,
        int $perPage,
        ?string $employeeId = null,
        ?array $employeeIds = null,
        ?Builder $employeeScope = null,
    ): array {
        $query = $this->query($filterEvent, $filterStatus, $search, $employeeId, $employeeIds, $employeeScope);
        $summary = $this->summary(clone $query);
        $thresholdMap = $this->thresholdMap($this->configValues());
        $alerts = $query
            ->with(['employee.disciplineRecords', 'handledBy'])
            ->orderBy('target_date')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
        $alerts->through(fn (EwsAlert $alert): array => $this->mapAlert($alert, $thresholdMap));

        return [
            'alerts' => $alerts,
            'type_labels' => self::TYPE_LABELS,
            'followup_status_labels' => self::FOLLOWUP_STATUS_LABELS,
            'summary' => $summary,
        ];
    }

    /**
     * Dashboard hanya menerima preview terbatas, sedangkan total dan bucket urgensi
     * dihitung set-based di database dari filter yang sama.
     *
     * @param  list<string>|null  $employeeIds
     * @return array{alerts: array<int, array<string, mixed>>, total: int, urgent: int, warning: int, info: int}
     */
    public function preview(
        int $limit,
        ?string $filterEvent = null,
        ?string $filterStatus = null,
        ?string $search = null,
        ?string $employeeId = null,
        ?array $employeeIds = null,
    ): array {
        $query = $this->query($filterEvent, $filterStatus, $search, $employeeId, $employeeIds);
        $summary = $this->summary(clone $query);
        $thresholdMap = $this->thresholdMap($this->configValues());
        $alerts = $query
            ->with(['employee.disciplineRecords', 'handledBy'])
            ->orderBy('target_date')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (EwsAlert $alert): array => $this->mapAlert($alert, $thresholdMap))
            ->all();

        return ['alerts' => $alerts] + $summary;
    }

    /** @param list<string>|null $employeeIds */
    private function query(
        ?string $filterEvent,
        ?string $filterStatus,
        ?string $search,
        ?string $employeeId,
        ?array $employeeIds,
        ?Builder $employeeScope = null,
    ): Builder {
        // Predicate aktif tetap berasal dari relasi status canonical dan gagal tertutup.
        $query = EwsAlert::query()
            ->whereIn('employee_id', Employee::query()->whereActiveStatus()->select('id'));

        if ($employeeId !== null && $employeeId !== '') {
            $query->where('employee_id', $employeeId);
        }

        if ($employeeIds !== null) {
            $employeeIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('employee_id', $employeeIds);
        }

        if ($employeeScope !== null) {
            $query->whereIn('employee_id', $employeeScope->select('id'));
        }

        $status = $this->statusFromFilter($filterStatus);
        if ($filterStatus === 'semua') {
            // Tidak ada filter status tindak lanjut.
        } elseif ($filterStatus === '' || $filterStatus === null) {
            $query->where('followup_status', EwsAlert::FOLLOWUP_STATUS_ACTIVE);
        } elseif ($status === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('followup_status', $status);
        }

        if ($filterEvent !== null && $filterEvent !== '' && $filterEvent !== 'semua') {
            $query->where('type', $this->typeFromLabel($filterEvent) ?? '__invalid__');
        }

        $normalizedSearch = mb_strtolower(trim((string) $search));
        if ($normalizedSearch !== '') {
            // Gunakan ! sebagai escape character agar %, _, dan ! dari input
            // diperlakukan literal pada PostgreSQL, MySQL, maupun SQLite.
            $escapedSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $normalizedSearch);
            $like = '%'.$escapedSearch.'%';
            $query->whereHas('employee', function (Builder $employeeQuery) use ($like): void {
                $employeeQuery->where(function (Builder $identityQuery) use ($like): void {
                    $identityQuery->whereRaw("LOWER(nama_lengkap) LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("LOWER(COALESCE(nip, '')) LIKE ? ESCAPE '!'", [$like]);
                });
            });
        }

        return $query;
    }

    /** @return array{total: int, urgent: int, warning: int, info: int} */
    private function summary(Builder $query): array
    {
        $today = now()->startOfDay();
        $dangerBoundary = $today->copy()->addDays(30)->toDateString();
        $infoBoundary = $today->copy()->addDays(90)->toDateString();
        $row = $query->selectRaw(
            <<<'SQL'
COUNT(*) AS total,
COALESCE(SUM(CASE WHEN target_date < ? THEN 1 ELSE 0 END), 0) AS urgent,
COALESCE(SUM(CASE WHEN target_date >= ? AND target_date <= ? THEN 1 ELSE 0 END), 0) AS warning,
COALESCE(SUM(CASE WHEN target_date > ? THEN 1 ELSE 0 END), 0) AS info
SQL,
            [$dangerBoundary, $dangerBoundary, $infoBoundary, $infoBoundary],
        )->firstOrFail();

        return [
            'total' => (int) $row->getAttribute('total'),
            'urgent' => (int) $row->getAttribute('urgent'),
            'warning' => (int) $row->getAttribute('warning'),
            'info' => (int) $row->getAttribute('info'),
        ];
    }

    private function typeFromLabel(?string $label): ?string
    {
        if ($label === null || $label === '') {
            return null;
        }

        $type = array_search($label, self::TYPE_LABELS, true);

        return $type === false ? null : $type;
    }

    private function statusFromFilter(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        return array_key_exists($status, self::FOLLOWUP_STATUS_LABELS) ? $status : null;
    }

    /** @param array<string, array<int, string>> $thresholdMap */
    private function mapAlert(EwsAlert $alert, array $thresholdMap): array
    {
        $employee = $alert->employee;
        if (! $employee instanceof Employee) {
            throw new RuntimeException('Alert EWS aktif harus memiliki pegawai agar eligibility dapat dihitung.');
        }

        $sisaHari = (int) now()->startOfDay()->diffInDays(Carbon::parse($alert->target_date)->startOfDay(), false);
        $thresholdConfig = $thresholdMap[$alert->type] ?? [];
        $eligibility = $this->eligibilityFor($alert, $employee);

        return [
            'pegawai_id' => $employee->id,
            'alert_id' => $alert->id,
            'type' => $alert->type,
            'nama' => $employee->nama_lengkap,
            'nip' => $employee->nip,
            'jenis_event' => self::TYPE_LABELS[$alert->type] ?? $alert->type,
            'tanggal_target' => Carbon::parse($alert->target_date)->format('Y-m-d'),
            'sisa_hari' => $sisaHari,
            'followup_status' => $alert->followup_status,
            'followup_status_label' => self::FOLLOWUP_STATUS_LABELS[$alert->followup_status] ?? $alert->followup_status,
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

        return ['is_eligible' => true, 'reason' => 'Perlu tindak lanjut', 'checks' => []];
    }

    /** @return array<string, string> */
    private function configValues(): array
    {
        try {
            return EwsConfig::query()
                ->whereIn('key', $this->configKeys())
                ->pluck('value', 'key')
                ->map(fn ($value): string => (string) $value)
                ->all();
        } catch (\Throwable) {
            // Default formula tetap tersedia ketika tabel config belum siap saat recovery.
            return [];
        }
    }

    /** @return list<string> */
    private function configKeys(): array
    {
        return [
            'pangkat_h90', 'pangkat_h60', 'pangkat_h30',
            'kgb_h60', 'kgb_h30', 'kgb_h14',
            'pensiun_y1', 'pensiun_m6', 'pensiun_m3',
            'pppk_m6', 'pppk_m3', 'pppk_m1',
            'satyalancana_h180', 'satyalancana_h90', 'satyalancana_h30',
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, array<int, string>>
     */
    private function thresholdMap(array $values): array
    {
        $configDays = fn (string $key, int $default): int => (int) ($values[$key] ?? $default);
        $dayLabel = fn (int $days): string => 'H-'.$days;
        $monthLabel = function (int $days): string {
            if ($days >= 365 && $days % 365 === 0) {
                return 'H-'.((int) ($days / 365)).' tahun';
            }

            return $days >= 28 ? 'H-'.((int) round($days / 30)).' bulan' : 'H-'.$days.' hari';
        };

        return [
            'KENAIKAN_PANGKAT' => $this->points([
                $configDays('pangkat_h90', 90), $configDays('pangkat_h60', 60), $configDays('pangkat_h30', 30),
            ], $dayLabel),
            'KGB' => $this->points([
                $configDays('kgb_h60', 60), $configDays('kgb_h30', 30), $configDays('kgb_h14', 14),
            ], $dayLabel),
            'PENSIUN' => $this->points([
                $configDays('pensiun_y1', 365), $configDays('pensiun_m6', 180), $configDays('pensiun_m3', 90),
            ], $monthLabel),
            'KONTRAK_PPPK' => $this->points([
                $configDays('pppk_m6', 180), $configDays('pppk_m3', 90), $configDays('pppk_m1', 30),
            ], $monthLabel),
            'SATYALANCANA' => $this->points([
                $configDays('satyalancana_h180', 180), $configDays('satyalancana_h90', 90), $configDays('satyalancana_h30', 30),
            ], $dayLabel),
        ];
    }

    /** @param array<int, int> $days @return array<int, string> */
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
        return match (true) {
            $sisaHari < 30 => 'danger',
            $sisaHari <= 90 => 'warning',
            default => 'success',
        };
    }
}
