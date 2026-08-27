<?php

namespace App\Data\Cuti;

use Carbon\CarbonImmutable;

/**
 * Baris baca rekap hanya membawa field yang aman ditampilkan pada laporan lintas peran.
 */
final readonly class CutiRekapReadRow
{
    public const SOURCE_LEAVE_REQUEST = 'leave_request';

    public const SOURCE_MANUAL_EXTERNAL = 'manual_external';

    public function __construct(
        public string $id,
        public string $employeeId,
        public string $leaveTypeId,
        public string $sourceType,
        public string $sourceLabel,
        public string $nip,
        public string $nama,
        public string $unit,
        public string $jenis,
        public CarbonImmutable $tanggalMulai,
        public CarbonImmutable $tanggalSelesai,
        public int $hari,
        public string $status,
        public string $statusLabel,
        public ?string $currentStepLabel,
    ) {}
}
