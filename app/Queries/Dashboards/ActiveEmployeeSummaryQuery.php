<?php

namespace App\Queries\Dashboards;

use App\Models\Employee;
use App\Models\User;

class ActiveEmployeeSummaryQuery
{
    /**
     * Menghitung widget pegawai aktif secara set-based tanpa memuat model pegawai.
     *
     * Data milik sendiri dikecualikan kecuali untuk Super Admin efektif,
     * konsisten dengan daftar Data Pegawai.
     *
     * @return array{total: int, composition: array<string, int>, rank_distribution: array<string, int>}
     */
    public function execute(?User $viewer = null): array
    {
        $active = Employee::query()->whereActiveStatus();

        if ($viewer !== null
            && $viewer->getEffectiveRole() !== 'super_admin'
            && is_string($viewer->employee_id)
            && $viewer->employee_id !== '') {
            $active->whereKeyNot($viewer->employee_id);
        }
        $composition = (clone $active)
            ->toBase()
            ->leftJoin('ref_jenis_pegawai', 'ref_jenis_pegawai.id', '=', 'employees.jenis_pegawai_id')
            ->selectRaw('ref_jenis_pegawai.nama AS label, COUNT(*) AS total')
            ->groupBy('ref_jenis_pegawai.nama')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (string) ($row->label ?? 'Tidak Diketahui') => (int) $row->total,
            ])
            ->all();
        $rankDistribution = (clone $active)
            ->toBase()
            ->selectRaw("COALESCE(NULLIF(golongan_terakhir, ''), 'Belum Diisi') AS label, COUNT(*) AS total")
            ->groupByRaw("COALESCE(NULLIF(golongan_terakhir, ''), 'Belum Diisi')")
            ->orderByRaw("COALESCE(NULLIF(golongan_terakhir, ''), 'Belum Diisi')")
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->label => (int) $row->total])
            ->all();

        return [
            'total' => (clone $active)->count(),
            'composition' => $composition,
            'rank_distribution' => $rankDistribution,
        ];
    }
}
