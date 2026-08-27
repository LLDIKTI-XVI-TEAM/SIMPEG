<?php

namespace App\Data\Cuti;

use Carbon\CarbonImmutable;

/**
 * Baris riwayat keputusan verifikator hanya membawa periode dan jumlah hari yang aman ditampilkan.
 */
final readonly class VerifierLeaveHistoryRow
{
    public function __construct(
        public string $id,
        public string $source_type,
        public CarbonImmutable $tanggal_mulai,
        public CarbonImmutable $tanggal_selesai,
        public int $jumlah_hari_kerja,
    ) {}
}
