<?php

namespace App\Support;

/**
 * Menyamarkan identifier identitas eksternal (mis. subject Keycloak) sebelum
 * disimpan pada jejak audit append-only agar identifier stabil yang tidak dapat
 * dibersihkan kemudian tidak tersimpan secara utuh, sambil tetap memungkinkan
 * korelasi manual antar-baris audit (4 karakter terakhir tetap terlihat).
 */
final class IdentifierMasker
{
    public function __construct() {}

    public static function mask(?string $identifier): ?string
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        $visibleCharacters = min(4, strlen($identifier));

        return str_repeat('*', max(strlen($identifier) - $visibleCharacters, 0))
            .substr($identifier, -$visibleCharacters);
    }
}
