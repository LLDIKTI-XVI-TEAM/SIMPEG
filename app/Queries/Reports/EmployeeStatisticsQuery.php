<?php

namespace App\Queries\Reports;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class EmployeeStatisticsQuery
{
    /**
     * Batasi kategori yang digambar agar payload serta render Chart.js tidak
     * tumbuh mengikuti jumlah pegawai atau variasi snapshot teks lama.
     */
    public const MAX_VISIBLE_CATEGORIES = 10;

    /** @var list<string> */
    private const CHART_TONES = [
        'primary',
        'secondary',
        'info',
        'success',
        'warning',
        'orange',
        'danger',
        'muted',
    ];

    /**
     * @param  EloquentBuilder<Employee>  $employees
     */
    public function execute(EloquentBuilder $employees): array
    {
        $active = (clone $employees)->whereActiveStatus()->toBase();
        $total = (clone $active)->count();

        $dimensions = [
            'jenis_pegawai' => $this->group((clone $active)->leftJoin('ref_jenis_pegawai as refs', 'refs.id', '=', 'employees.jenis_pegawai_id'), 'refs.nama', $total),
            'golongan' => $this->group($this->withLatestRank((clone $active))->leftJoin('ref_golongan as refs', 'refs.id', '=', 'ranks.golongan_id'), "COALESCE(NULLIF(TRIM(refs.kode), ''), NULLIF(TRIM(employees.golongan_terakhir), ''))", $total),
            'jenis_jabatan' => $this->position((clone $active), 'refs.nama', 'ref_jenis_jabatan as refs', 'positions.jenis_jabatan_id', $total),
            'jabatan' => $this->position((clone $active), "COALESCE(NULLIF(TRIM(refs.nama), ''), NULLIF(TRIM(positions.nama_jabatan), ''), NULLIF(TRIM(employees.jabatan_terakhir), ''))", 'ref_jabatan as refs', 'positions.jabatan_id', $total),
            'unit_kerja' => $this->position((clone $active), 'refs.nama', 'ref_unit_kerja as refs', 'positions.unit_kerja_id', $total),
            'jenis_kelamin' => $this->group((clone $active), "CASE WHEN UPPER(TRIM(employees.jenis_kelamin)) = 'L' THEN 'Laki-laki' WHEN UPPER(TRIM(employees.jenis_kelamin)) = 'P' THEN 'Perempuan' ELSE NULL END", $total),
            'pendidikan' => $this->group((clone $active), 'employees.pendidikan_terakhir', $total),
            'status_pegawai' => $this->group((clone $active)->leftJoin('ref_status_pegawai as refs', 'refs.id', '=', 'employees.status_pegawai_id'), "COALESCE(NULLIF(TRIM(refs.nama), ''), NULLIF(TRIM(employees.status_aktif), ''))", $total),
        ];

        foreach ($dimensions as $key => &$rows) {
            foreach ($rows as $index => &$row) {
                $row['tone'] = self::resolveTone($key, (string) $row['label'], $index);
            }
            unset($row);
        }
        unset($rows);

        $pnsCount = 0;
        $pppkCount = 0;
        $cpnsCount = 0;
        foreach ($dimensions['jenis_pegawai'] as $row) {
            if (strcasecmp($row['label'], 'PNS') === 0) {
                $pnsCount = $row['total'];
            } elseif (strcasecmp($row['label'], 'PPPK') === 0) {
                $pppkCount = $row['total'];
            } elseif (strcasecmp($row['label'], 'CPNS') === 0) {
                $cpnsCount = $row['total'];
            }
        }

        $maleCount = 0;
        $femaleCount = 0;
        foreach ($dimensions['jenis_kelamin'] as $row) {
            if ($row['label'] === 'Laki-laki') {
                $maleCount = $row['total'];
            } elseif ($row['label'] === 'Perempuan') {
                $femaleCount = $row['total'];
            }
        }

        return [
            'total' => $total,
            'summary' => [
                'pns' => $pnsCount,
                'pppk' => $pppkCount,
                'cpns' => $cpnsCount,
                'laki_laki' => $maleCount,
                'perempuan' => $femaleCount,
            ],
            'dimensions' => $dimensions,
        ];
    }

    private function position(Builder $query, string $label, string $table, string $positionColumn, int $total): array
    {
        return $this->group(
            $this->withLatestPosition($query)->leftJoin($table, 'refs.id', '=', $positionColumn),
            $label,
            $total,
        );
    }

    private function withLatestRank(Builder $query): Builder
    {
        return $query->leftJoin('rank_histories as ranks', function (JoinClause $join): void {
            $join->on('ranks.id', '=', DB::raw('(
                SELECT latest_rank.id
                FROM rank_histories AS latest_rank
                WHERE latest_rank.employee_id = employees.id
                  AND latest_rank.is_latest = true
                ORDER BY latest_rank.tmt_pangkat IS NULL ASC, latest_rank.tmt_pangkat DESC, latest_rank.created_at DESC, latest_rank.id DESC
                LIMIT 1
            )'));
        });
    }

    private function withLatestPosition(Builder $query): Builder
    {
        return $query->leftJoin('position_histories as positions', function (JoinClause $join): void {
            $join->on('positions.id', '=', DB::raw('(
                SELECT latest_position.id
                FROM position_histories AS latest_position
                WHERE latest_position.employee_id = employees.id
                  AND latest_position.is_latest = true
                ORDER BY latest_position.tmt_jabatan IS NULL ASC, latest_position.tmt_jabatan DESC, latest_position.created_at DESC, latest_position.id DESC
                LIMIT 1
            )'));
        });
    }

    /** @return list<array{label: string, total: int}> */
    private function group(Builder $query, string $column, int $total): array
    {
        $rawLabel = "COALESCE(NULLIF(TRIM({$column}), ''), 'Belum diisi/Tidak diketahui')";
        $label = "CASE WHEN LOWER({$rawLabel}) = 'lainnya' THEN 'Lainnya' ELSE {$rawLabel} END";
        $rows = $query
            ->selectRaw("{$label} as label, COUNT(*) as total")
            ->groupByRaw($label)
            ->orderByDesc('total')
            ->orderByRaw($label)
            ->limit(self::MAX_VISIBLE_CATEGORIES)
            ->get()
            ->map(fn (object $row): array => ['label' => (string) $row->label, 'total' => (int) $row->total])
            ->all();

        $shownTotal = array_sum(array_column($rows, 'total'));

        if ($shownTotal < $total) {
            $remainingTotal = $total - $shownTotal;
            $mergedIntoExistingOther = false;

            foreach ($rows as &$row) {
                if (strcasecmp(trim($row['label']), 'Lainnya') === 0) {
                    $row['total'] += $remainingTotal;
                    $mergedIntoExistingOther = true;
                    break;
                }
            }
            unset($row);

            if (! $mergedIntoExistingOther) {
                $rows[] = ['label' => 'Lainnya', 'total' => $remainingTotal];
            }
        }

        return $rows;
    }

    public static function resolveTone(string $dimensionKey, string $label, int $index = 0): string
    {
        // Golongan dan jenjang pendidikan tetap memakai warna biru institusional.
        if (in_array($dimensionKey, ['golongan', 'pendidikan'], true)) {
            return 'primary';
        }

        $lower = strtolower(trim($label));

        // Missing or unknown data is always neutral slate
        if (str_contains($lower, 'belum') || str_contains($lower, 'tidak diketahui')) {
            return 'muted';
        }

        if ($dimensionKey === 'jenis_pegawai') {
            // Note: Check CPNS before PNS because 'cpns' contains 'pns'
            if (str_contains($lower, 'cpns')) {
                return 'info';
            }
            if (str_contains($lower, 'pppk')) {
                return 'secondary';
            }
            if (str_contains($lower, 'pns')) {
                return 'primary';
            }
        }

        if ($dimensionKey === 'jenis_kelamin') {
            if (str_contains($lower, 'laki')) {
                return 'primary';
            }
            if (str_contains($lower, 'perempuan')) {
                return 'secondary';
            }
        }

        if ($dimensionKey === 'status_pegawai') {
            if (
                str_contains($lower, 'pensiun') ||
                str_contains($lower, 'nonaktif') ||
                str_contains($lower, 'berhenti') ||
                str_contains($lower, 'meninggal')
            ) {
                return 'danger';
            }
            if (str_contains($lower, 'cuti')) {
                return 'warning';
            }
            if (str_contains($lower, 'aktif')) {
                return 'success';
            }
        }

        return self::CHART_TONES[$index % count(self::CHART_TONES)];
    }
}
