<?php

namespace App\Support\Histories;

use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\SalaryHistory;
use Carbon\CarbonInterface;

class EmployeeHistoryPayload
{
    /**
     * Mempertahankan kontrak riwayat pangkat dengan tanggal kalender yang deterministik.
     *
     * @return array<string, mixed>
     */
    public function rank(RankHistory $history): array
    {
        return $this->withDateOnlyFields($history->toArray(), [
            'tanggal_sk' => $history->tanggal_sk,
            'tmt_pangkat' => $history->tmt_pangkat,
        ]);
    }

    /**
     * Mempertahankan kontrak riwayat jabatan dengan tanggal kalender yang deterministik.
     *
     * @return array<string, mixed>
     */
    public function position(PositionHistory $history): array
    {
        return $this->withDateOnlyFields($history->toArray(), [
            'tanggal_sk' => $history->tanggal_sk,
            'tmt_jabatan' => $history->tmt_jabatan,
        ]);
    }

    /**
     * Mempertahankan kontrak riwayat KGB dengan tanggal kalender yang deterministik.
     *
     * @return array<string, mixed>
     */
    public function kgb(SalaryHistory $history): array
    {
        return $this->withDateOnlyFields($history->toArray(), [
            'tanggal_sk' => $history->tanggal_sk,
            'tmt_kgb' => $history->tmt_kgb,
        ]);
    }

    /**
     * Field tanggal kalender tidak boleh mengikuti serialisasi timestamp UTC bawaan Eloquent.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, CarbonInterface|null>  $dates
     * @return array<string, mixed>
     */
    private function withDateOnlyFields(array $payload, array $dates): array
    {
        foreach ($dates as $field => $date) {
            $payload[$field] = $date?->format('Y-m-d');
        }

        return $payload;
    }
}
