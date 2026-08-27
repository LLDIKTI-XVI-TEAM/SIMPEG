<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/** Membawa path kompensasi keluar transaksi tanpa memasukkannya ke pesan error atau audit. */
final class LeaveProofGenerationException extends RuntimeException
{
    public function __construct(
        public readonly string $cleanupPath,
        Throwable $previous,
    ) {
        parent::__construct('Dokumen bukti final gagal dibuat.', 0, $previous);
    }
}
