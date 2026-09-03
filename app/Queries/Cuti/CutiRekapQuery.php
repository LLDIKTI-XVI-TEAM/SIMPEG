<?php

namespace App\Queries\Cuti;

use App\Data\Cuti\CutiRekapReadRow;
use App\Models\LeaveBalance;
use App\Models\LeaveUsageRecord;
use App\Support\Cuti\ApprovalStepLabel;
use App\Support\Cuti\CutiPeriodFilter;
use App\Support\Cuti\CutiReportStatusFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CutiRekapQuery
{
    private const MAX_UNIT_OPTIONS = 200;

    private const MAX_LEAVE_TYPE_OPTIONS = 100;

    public function __construct(
        private readonly CutiReportStatusFormatter $statusFormatter,
    ) {}

    /**
     * Menyatukan pengajuan SIMPEG dan cuti manual aktif tanpa membawa catatan atau metadata dokumen privat.
     * Cast eksplisit menjaga kedua cabang UNION ALL kompatibel pada PostgreSQL.
     *
     * @param  array<string, mixed>  $filters
     */
    public function detailRows(array $filters): QueryBuilder
    {
        $this->guardUuidFilters($filters);

        $activeSteps = DB::table('leave_request_steps as step_rows')
            ->selectRaw('DISTINCT ON (step_rows.leave_request_id) step_rows.leave_request_id, step_rows.step_type::text AS current_step_type, step_rows.role_label::text AS current_step_label')
            ->where('step_rows.status', 'active')
            ->orderBy('step_rows.leave_request_id')
            ->orderBy('step_rows.step_order')
            ->orderBy('step_rows.id');

        $requests = DB::table('leave_requests as lr')
            ->join('employees as employees', 'employees.id', '=', 'lr.employee_id')
            ->join('ref_jenis_cuti as leave_types', 'leave_types.id', '=', 'lr.jenis_cuti_id')
            ->leftJoinSub($this->currentPositions(), 'current_positions', function ($join): void {
                $join->on('current_positions.employee_id', '=', 'lr.employee_id');
            })
            ->leftJoin('ref_unit_kerja as units', 'units.id', '=', 'current_positions.unit_kerja_id')
            ->leftJoinSub($activeSteps, 'active_steps', function ($join): void {
                $join->on('active_steps.leave_request_id', '=', 'lr.id');
            })
            ->selectRaw(<<<'SQL'
CAST(lr.id AS text) AS id,
CAST(lr.employee_id AS text) AS employee_id,
CAST(lr.jenis_cuti_id AS text) AS leave_type_id,
CAST('leave_request' AS text) AS source_type,
CAST(employees.nip AS text) AS nip,
CAST(employees.nama_lengkap AS text) AS nama,
CAST(current_positions.unit_kerja_id AS text) AS unit_id,
CAST(units.nama AS text) AS unit,
CAST(leave_types.nama AS text) AS jenis,
CAST(lr.tanggal_mulai AS date) AS tanggal_mulai,
CAST(lr.tanggal_selesai AS date) AS tanggal_selesai,
CAST(lr.jumlah_hari_kerja AS integer) AS hari,
CAST(lr.status AS text) AS status,
CAST(active_steps.current_step_type AS text) AS current_step_type,
CAST(active_steps.current_step_label AS text) AS current_step_label,
CAST(lr.created_at AS timestamp) AS created_at
SQL);

        $manual = DB::table('leave_usage_records as usage')
            ->join('employees as employees', 'employees.id', '=', 'usage.employee_id')
            ->join('ref_jenis_cuti as leave_types', 'leave_types.id', '=', 'usage.leave_type_id')
            ->leftJoinSub($this->currentPositions(), 'current_positions', function ($join): void {
                $join->on('current_positions.employee_id', '=', 'usage.employee_id');
            })
            ->leftJoin('ref_unit_kerja as units', 'units.id', '=', 'current_positions.unit_kerja_id')
            ->where('usage.source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)
            ->where('usage.record_status', LeaveUsageRecord::STATUS_ACTIVE)
            ->selectRaw(<<<'SQL'
CAST(usage.id AS text) AS id,
CAST(usage.employee_id AS text) AS employee_id,
CAST(usage.leave_type_id AS text) AS leave_type_id,
CAST('manual_external' AS text) AS source_type,
CAST(employees.nip AS text) AS nip,
CAST(employees.nama_lengkap AS text) AS nama,
CAST(current_positions.unit_kerja_id AS text) AS unit_id,
CAST(units.nama AS text) AS unit,
CAST(leave_types.nama AS text) AS jenis,
CAST(usage.start_date AS date) AS tanggal_mulai,
CAST(usage.end_date AS date) AS tanggal_selesai,
CAST(usage.workdays AS integer) AS hari,
CAST('disetujui' AS text) AS status,
CAST(NULL AS text) AS current_step_type,
CAST(NULL AS text) AS current_step_label,
CAST(usage.created_at AS timestamp) AS created_at
SQL);

        $query = DB::query()
            ->fromSub($requests->unionAll($manual), 'cuti_rekap')
            ->select([
                'id', 'employee_id', 'leave_type_id', 'source_type', 'nip', 'nama', 'unit_id', 'unit', 'jenis',
                'tanggal_mulai', 'tanggal_selesai', 'hari', 'status', 'current_step_type', 'current_step_label', 'created_at',
            ]);

        $this->applyDetailFilters($query, $filters);

        return $query
            ->orderByDesc('tanggal_mulai')
            ->orderByDesc('created_at')
            ->orderBy('source_type')
            ->orderBy('id');
    }

    /**
     * Pagination dan transformasi DTO dilakukan setelah LIMIT/OFFSET diterapkan database.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<CutiRekapReadRow>
     */
    public function paginateDetailRows(array $filters, int $perPage, string $pageName): LengthAwarePaginator
    {
        $rows = $this->detailRows($filters)
            ->paginate($perPage, ['*'], $pageName)
            ->withQueryString();

        return $rows->through(fn (object $row): CutiRekapReadRow => $this->toReadRow($row));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, CutiRekapReadRow>
     */
    public function allDetailRows(array $filters): Collection
    {
        return $this->detailRows($filters)
            ->get()
            ->map(fn (object $row): CutiRekapReadRow => $this->toReadRow($row));
    }

    /** @param array<string, mixed> $filters */
    public function detailCount(array $filters): int
    {
        return $this->detailRows($filters)->count();
    }

    /**
     * Menyusun sumber saldo dari field materialized ledger; saldo tidak dihitung ulang dari jatah tetap.
     *
     * @param  array<string, mixed>  $filters
     * @return EloquentBuilder<LeaveBalance>
     */
    public function balanceRows(array $filters): EloquentBuilder
    {
        $this->guardUuidFilters($filters);
        $period = $this->parsePeriod($filters['periode'] ?? null);

        return LeaveBalance::query()
            ->from('leave_balances as balances')
            ->leftJoinSub($this->currentPositions(), 'current_positions', function ($join): void {
                $join->on('current_positions.employee_id', '=', 'balances.employee_id');
            })
            ->leftJoin('ref_unit_kerja as units', 'units.id', '=', 'current_positions.unit_kerja_id')
            ->select([
                'balances.id', 'balances.employee_id', 'balances.tahun', 'balances.jatah_awal',
                'balances.carry_over', 'balances.terpakai', 'balances.sisa', 'balances.sisa_n2',
                'balances.sisa_n1', 'balances.sisa_tahun_berjalan',
                'balances.terpakai_tahun_berjalan', 'balances.hangus', 'units.nama as unit_name',
            ])
            ->with('employee:id,nama_lengkap,nip')
            ->when($this->stringFilter($filters, 'unit'), fn (EloquentBuilder $query, string $unit) => $query
                ->where('current_positions.unit_kerja_id', $unit))
            ->when($this->stringFilter($filters, 'pegawai'), fn (EloquentBuilder $query, string $pegawai) => $query
                ->where('balances.employee_id', $pegawai))
            ->when($period, fn (EloquentBuilder $query, CutiPeriodFilter $periodFilter) => $query
                ->where('balances.tahun', $periodFilter->year))
            ->orderByDesc('balances.tahun')
            ->orderBy('balances.employee_id')
            ->orderBy('balances.id');
    }

    /**
     * Ringkasan hanya menghitung fakta pemakaian final aktif, bukan status pengajuan atau histori versi lama.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{employee_id: string, nip: string, nama: string, jenis: string, total_hari: int, sisa_saldo: int|string, saldo_tahun: int}>
     */
    public function summaryRows(array $filters): Collection
    {
        $this->guardUuidFilters($filters);
        $saldoTahun = $this->saldoYear($filters);
        $period = $this->parsePeriod($filters['periode'] ?? null);
        $aggregateAnnualBalance = $period?->month === null;
        $rows = $this->itemizedSummaryRows($filters, $saldoTahun, $aggregateAnnualBalance);

        if ($aggregateAnnualBalance) {
            // Saldo material adalah hasil authoritative dari deklarasi, membership, dan fakta baru.
            // Memakainya untuk total tahunan mencegah deklarasi hilang atau itemized terhitung dua kali.
            $rows = $rows->concat($this->annualBalanceSummaryRows($filters, $saldoTahun, $period));
        }

        return $rows
            ->sort(fn (array $left, array $right): int => [
                $left['nama'], $left['jenis'], $left['employee_id'],
            ] <=> [
                $right['nama'], $right['jenis'], $right['employee_id'],
            ])
            ->values();
    }

    /**
     * Menghitung seluruh baris ringkasan di database agar guard export tidak memuat koleksi besar ke memori.
     *
     * @param  array<string, mixed>  $filters
     */
    public function summaryCount(array $filters): int
    {
        $this->guardUuidFilters($filters);
        $saldoTahun = $this->saldoYear($filters);
        $period = $this->parsePeriod($filters['periode'] ?? null);
        $aggregateAnnualBalance = $period?->month === null;
        $count = $this->groupedSummaryCount(
            $this->itemizedSummaryQuery($filters, $saldoTahun, $aggregateAnnualBalance),
        );

        if ($aggregateAnnualBalance) {
            $count += $this->groupedSummaryCount(
                $this->annualBalanceSummaryQuery($filters, $saldoTahun, $period),
            );
        }

        return $count;
    }

    /**
     * Fakta bertanggal tetap menjadi sumber laporan bulanan. Saat total tahunan berasal dari
     * projection material, Cuti Tahunan dikeluarkan dari cabang ini agar tidak terhitung dua kali.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{employee_id: string, nip: string, nama: string, jenis: string, total_hari: int, sisa_saldo: int|string, saldo_tahun: int}>
     */
    private function itemizedSummaryRows(array $filters, int $saldoTahun, bool $excludeAnnual): Collection
    {
        return $this->mapSummaryRows(
            $this->itemizedSummaryQuery($filters, $saldoTahun, $excludeAnnual)
                ->orderBy('employees.nama_lengkap')
                ->orderBy('leave_types.nama')
                ->orderBy('usage.leave_type_id')
                ->orderBy('usage.employee_id')
                ->get(),
            $saldoTahun,
        );
    }

    /**
     * Query kelompok fakta bertanggal dipakai bersama oleh render dan guard jumlah baris.
     *
     * @param  array<string, mixed>  $filters
     */
    private function itemizedSummaryQuery(array $filters, int $saldoTahun, bool $excludeAnnual): QueryBuilder
    {
        $query = DB::table('leave_usage_records as usage')
            ->join('employees as employees', 'employees.id', '=', 'usage.employee_id')
            ->join('ref_jenis_cuti as leave_types', 'leave_types.id', '=', 'usage.leave_type_id')
            ->leftJoinSub($this->currentPositions(), 'current_positions', function ($join): void {
                $join->on('current_positions.employee_id', '=', 'usage.employee_id');
            })
            ->leftJoin('leave_balances as balances', function ($join) use ($saldoTahun): void {
                $join->on('balances.employee_id', '=', 'usage.employee_id')
                    ->where('balances.tahun', '=', $saldoTahun);
            })
            ->whereIn('usage.source_type', [
                LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
                LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            ])
            ->where('usage.record_status', LeaveUsageRecord::STATUS_ACTIVE)
            ->when($excludeAnnual, fn (QueryBuilder $builder) => $builder
                ->where(fn (QueryBuilder $leaveTypeQuery) => $leaveTypeQuery
                    ->whereNull('leave_types.code')
                    ->orWhere('leave_types.code', '!=', 'tahunan')));

        $this->applySummaryFilters($query, $filters);

        return $query
            ->groupBy([
                'usage.employee_id', 'employees.nip', 'employees.nama_lengkap',
                'usage.leave_type_id', 'leave_types.nama', 'balances.sisa',
            ])
            ->selectRaw(<<<'SQL'
CAST(usage.employee_id AS text) AS employee_id,
CAST(employees.nip AS text) AS nip,
CAST(employees.nama_lengkap AS text) AS nama,
CAST(leave_types.nama AS text) AS jenis,
CAST(SUM(usage.workdays) AS integer) AS total_hari,
balances.sisa AS sisa_saldo
SQL);
    }

    /**
     * Agregat tahunan membaca field terpakai yang sudah direkalkulasi dari sumber fakta resmi.
     * Filter bulan tidak masuk jalur ini karena deklarasi agregat tidak memiliki tanggal kejadian.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{employee_id: string, nip: string, nama: string, jenis: string, total_hari: int, sisa_saldo: int|string, saldo_tahun: int}>
     */
    private function annualBalanceSummaryRows(
        array $filters,
        int $saldoTahun,
        ?CutiPeriodFilter $period,
    ): Collection {
        return $this->mapSummaryRows(
            $this->annualBalanceSummaryQuery($filters, $saldoTahun, $period)->get(),
            $saldoTahun,
        );
    }

    /**
     * Query kelompok saldo tahunan dipakai bersama oleh render dan guard jumlah baris.
     *
     * @param  array<string, mixed>  $filters
     */
    private function annualBalanceSummaryQuery(
        array $filters,
        int $saldoTahun,
        ?CutiPeriodFilter $period,
    ): QueryBuilder {
        return DB::table('leave_balances as usage_balances')
            ->join('employees as employees', 'employees.id', '=', 'usage_balances.employee_id')
            ->crossJoin('ref_jenis_cuti as leave_types')
            ->leftJoinSub($this->currentPositions(), 'current_positions', function ($join): void {
                $join->on('current_positions.employee_id', '=', 'usage_balances.employee_id');
            })
            ->leftJoin('leave_balances as balances', function ($join) use ($saldoTahun): void {
                $join->on('balances.employee_id', '=', 'usage_balances.employee_id')
                    ->where('balances.tahun', '=', $saldoTahun);
            })
            ->where('leave_types.code', 'tahunan')
            ->where('usage_balances.terpakai', '>', 0)
            ->when($this->stringFilter($filters, 'unit'), fn (QueryBuilder $builder, string $unit) => $builder
                ->where('current_positions.unit_kerja_id', $unit))
            ->when($this->stringFilter($filters, 'pegawai'), fn (QueryBuilder $builder, string $pegawai) => $builder
                ->where('usage_balances.employee_id', $pegawai))
            ->when($this->stringFilter($filters, 'jenis'), fn (QueryBuilder $builder, string $jenis) => $builder
                ->where('leave_types.id', $jenis))
            ->when($period, fn (QueryBuilder $builder, CutiPeriodFilter $periodFilter) => $builder
                ->where('usage_balances.tahun', $periodFilter->year))
            ->groupBy([
                'usage_balances.employee_id', 'employees.nip', 'employees.nama_lengkap',
                'leave_types.id', 'leave_types.nama', 'balances.sisa',
            ])
            ->selectRaw(<<<'SQL'
CAST(usage_balances.employee_id AS text) AS employee_id,
CAST(employees.nip AS text) AS nip,
CAST(employees.nama_lengkap AS text) AS nama,
CAST(leave_types.nama AS text) AS jenis,
CAST(SUM(usage_balances.terpakai) AS integer) AS total_hari,
balances.sisa AS sisa_saldo
SQL);
    }

    /**
     * Subquery menghitung hasil GROUP BY tanpa mematerialisasi setiap baris ke koleksi PHP.
     */
    private function groupedSummaryCount(QueryBuilder $query): int
    {
        return (int) DB::query()
            ->fromSub($query, 'summary_rows')
            ->count();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, array{employee_id: string, nip: string, nama: string, jenis: string, total_hari: int, sisa_saldo: int|string, saldo_tahun: int}>
     */
    private function mapSummaryRows(Collection $rows, int $saldoTahun): Collection
    {
        return $rows->map(fn (object $row): array => [
            'employee_id' => (string) $row->employee_id,
            'nip' => $this->displayText($row->nip),
            'nama' => $this->displayText($row->nama),
            'jenis' => $this->displayText($row->jenis),
            'total_hari' => (int) $row->total_hari,
            'sisa_saldo' => $row->sisa_saldo === null ? '-' : (int) $row->sisa_saldo,
            'saldo_tahun' => $saldoTahun,
        ]);
    }

    /** @param array<string, mixed> $filters */
    public function saldoYear(array $filters): int
    {
        return $this->parsePeriod($filters['periode'] ?? null)?->year ?? (int) now()->year;
    }

    /** @param array<string, mixed> $filters */
    public function periodLabel(array $filters): string
    {
        return $this->parsePeriod($filters['periode'] ?? null)?->label() ?? 'Semua_Tahun';
    }

    /**
     * Opsi referensi dibatasi dan hanya memuat id serta label yang aman untuk kontrol GET.
     *
     * @return Collection<int, array{id: string, nama: string}>
     */
    public function unitOptions(?string $selectedId = null): Collection
    {
        $this->guardOptionalUuid($selectedId);

        return DB::table('ref_unit_kerja')
            ->select(['id', 'nama'])
            ->when($selectedId, fn (QueryBuilder $query, string $id) => $query
                ->orderByRaw('CASE WHEN id = CAST(? AS uuid) THEN 0 ELSE 1 END', [$id]))
            ->orderBy('nama')
            ->orderBy('id')
            ->limit(self::MAX_UNIT_OPTIONS)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'nama' => $this->displayText($row->nama),
            ]);
    }

    /** @return Collection<int, array{id: string, nama: string}> */
    public function leaveTypeOptions(?string $selectedId = null): Collection
    {
        $this->guardOptionalUuid($selectedId);

        return DB::table('ref_jenis_cuti')
            ->select(['id', 'nama'])
            ->when($selectedId, fn (QueryBuilder $query, string $id) => $query
                ->orderByRaw('CASE WHEN id = CAST(? AS uuid) THEN 0 ELSE 1 END', [$id]))
            ->orderBy('nama')
            ->orderBy('id')
            ->limit(self::MAX_LEAVE_TYPE_OPTIONS)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'nama' => $this->displayText($row->nama),
            ]);
    }

    /** @param array<string, mixed> $filters */
    private function applyDetailFilters(QueryBuilder $query, array $filters): void
    {
        $query
            ->when($this->stringFilter($filters, 'unit'), fn (QueryBuilder $builder, string $unit) => $builder
                ->where('unit_id', $unit))
            ->when($this->stringFilter($filters, 'pegawai'), fn (QueryBuilder $builder, string $pegawai) => $builder
                ->where('employee_id', $pegawai))
            ->when($this->stringFilter($filters, 'jenis'), fn (QueryBuilder $builder, string $jenis) => $builder
                ->where('leave_type_id', $jenis));

        $this->applyPeriod($query, 'tanggal_mulai', $filters);
    }

    /** @param array<string, mixed> $filters */
    private function applySummaryFilters(QueryBuilder $query, array $filters): void
    {
        $query
            ->when($this->stringFilter($filters, 'unit'), fn (QueryBuilder $builder, string $unit) => $builder
                ->where('current_positions.unit_kerja_id', $unit))
            ->when($this->stringFilter($filters, 'pegawai'), fn (QueryBuilder $builder, string $pegawai) => $builder
                ->where('usage.employee_id', $pegawai))
            ->when($this->stringFilter($filters, 'jenis'), fn (QueryBuilder $builder, string $jenis) => $builder
                ->where('usage.leave_type_id', $jenis));

        $this->applyPeriod($query, 'usage.start_date', $filters);
    }

    /** @param array<string, mixed> $filters */
    private function applyPeriod(QueryBuilder $query, string $column, array $filters): void
    {
        $period = $this->parsePeriod($filters['periode'] ?? null);
        if ($period === null) {
            return;
        }

        $query->where($column, '>=', $period->startsAt()->toDateString())
            ->where($column, '<', $period->endsBeforeAt()->toDateString());
    }

    private function toReadRow(object $row): CutiRekapReadRow
    {
        $sourceType = (string) $row->source_type;
        $status = (string) $row->status;
        $currentStepLabel = $row->current_step_label === null
            ? null
            : ApprovalStepLabel::display(
                (string) ($row->current_step_type ?? ''),
                (string) $row->current_step_label,
            );

        return new CutiRekapReadRow(
            id: (string) $row->id,
            employeeId: (string) $row->employee_id,
            leaveTypeId: (string) $row->leave_type_id,
            sourceType: $sourceType,
            sourceLabel: $this->statusFormatter->sourceLabel($sourceType),
            nip: $this->displayText($row->nip),
            nama: $this->displayText($row->nama),
            unit: $this->displayText($row->unit),
            jenis: $this->displayText($row->jenis),
            tanggalMulai: CarbonImmutable::parse((string) $row->tanggal_mulai),
            tanggalSelesai: CarbonImmutable::parse((string) $row->tanggal_selesai),
            hari: (int) $row->hari,
            status: $status,
            statusLabel: $this->statusFormatter->format($status, $currentStepLabel, $sourceType),
            currentStepLabel: $currentStepLabel,
        );
    }

    private function displayText(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : '-';
    }

    /**
     * DISTINCT ON mencegah data riwayat inkonsisten menggandakan baris laporan.
     * Urutan tanggal dan id hanya menjadi pertahanan deterministik saat lebih dari satu baris ditandai terkini.
     */
    private function currentPositions(): QueryBuilder
    {
        return DB::table('position_histories as position_rows')
            ->selectRaw('DISTINCT ON (position_rows.employee_id) position_rows.employee_id, position_rows.unit_kerja_id')
            ->where('position_rows.is_latest', true)
            ->orderBy('position_rows.employee_id')
            ->orderByDesc('position_rows.tmt_jabatan')
            ->orderBy('position_rows.id');
    }

    /** @param array<string, mixed> $filters */
    private function guardUuidFilters(array $filters): void
    {
        foreach (['unit', 'pegawai', 'jenis'] as $field) {
            if (! array_key_exists($field, $filters) || $filters[$field] === null) {
                continue;
            }

            $value = $filters[$field];
            if (is_array($value) || ! is_string($value) || ! Str::isUuid($value)) {
                abort(404);
            }
        }
    }

    private function guardOptionalUuid(?string $value): void
    {
        if ($value !== null && ! Str::isUuid($value)) {
            abort(404);
        }
    }

    /** @param array<string, mixed> $filters */
    private function stringFilter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function parsePeriod(mixed $period): ?CutiPeriodFilter
    {
        return CutiPeriodFilter::parse($period);
    }
}
