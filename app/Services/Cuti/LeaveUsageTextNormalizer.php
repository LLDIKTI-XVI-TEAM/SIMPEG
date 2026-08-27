<?php

namespace App\Services\Cuti;

use Illuminate\Validation\ValidationException;

final class LeaveUsageTextNormalizer
{
    /** Membersihkan whitespace Unicode hanya di tepi dan menolak alasan tanpa karakter bermakna. */
    public function required(string $value, string $field, string $message): string
    {
        $normalized = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value);

        if ($normalized === null || $normalized === '') {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $normalized;
    }
}
