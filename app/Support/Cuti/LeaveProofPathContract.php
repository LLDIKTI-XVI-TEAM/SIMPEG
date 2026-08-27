<?php

namespace App\Support\Cuti;

use Illuminate\Support\Str;

final class LeaveProofPathContract
{
    /**
     * Path proof kanonis wajib memakai UUID lowercase RFC 4122 v1-v5 dengan variant yang sah.
     */
    public static function isCanonical(string $leaveRequestId, string $path): bool
    {
        if (! Str::isUuid($leaveRequestId)) {
            return false;
        }

        return preg_match(
            '#\Aleave-proofs/'.preg_quote(strtolower($leaveRequestId), '#').'/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.pdf\z#D',
            $path,
        ) === 1;
    }
}
