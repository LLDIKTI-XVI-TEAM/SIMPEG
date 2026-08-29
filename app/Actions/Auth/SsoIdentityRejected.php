<?php

namespace App\Actions\Auth;

use RuntimeException;

/**
 * Sinyal internal penolakan mapping identitas SSO.
 *
 * Dilempar oleh resolver setelah transaksinya commit dan audit rejection
 * tersimpan (fail-closed), lalu ditangkap execute() untuk dirender sebagai
 * respons terkontrol tanpa membocorkan detail internal.
 */
final class SsoIdentityRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $userMessage,
    ) {}
}
