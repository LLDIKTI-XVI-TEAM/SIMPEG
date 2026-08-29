<?php

namespace App\Exceptions;

use RuntimeException;

/** Menandai jadwal legacy yang belum memiliki provenance aktor memadai untuk dieksekusi. */
class MissingEmployeeStatusTransitionProvenanceException extends RuntimeException
{
    public static function forTransition(string $transitionId): self
    {
        return new self("Transisi status {$transitionId} belum memiliki provenance aktor yang lengkap.");
    }
}
