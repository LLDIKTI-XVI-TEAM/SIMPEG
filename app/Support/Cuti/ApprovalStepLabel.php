<?php

namespace App\Support\Cuti;

final class ApprovalStepLabel
{
    /** Menjaga istilah bisnis tahap approval tanpa mengubah kunci peran internal. */
    public static function display(string $stepType, ?string $storedLabel): string
    {
        return $stepType === 'kepala_bagian'
            ? 'Atasan Langsung'
            : ($storedLabel ?? '');
    }
}
