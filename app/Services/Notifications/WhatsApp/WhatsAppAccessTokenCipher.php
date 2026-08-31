<?php

namespace App\Services\Notifications\WhatsApp;

use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Mengenkripsi access token Qontak sebelum persistence dan mendekripsinya hanya
 * pada batas runtime. Ciphertext rusak selalu diperlakukan sebagai credential kosong.
 */
final class WhatsAppAccessTokenCipher
{
    public const CONFIG_KEY = 'access_token_encrypted';

    public static function encrypt(string $token): string
    {
        return Crypt::encryptString($token);
    }

    public static function decrypt(mixed $ciphertext): ?string
    {
        if (! is_string($ciphertext) || trim($ciphertext) === '') {
            return null;
        }

        try {
            $token = Crypt::decryptString($ciphertext);
        } catch (Throwable) {
            return null;
        }

        return trim($token) !== '' ? $token : null;
    }
}
