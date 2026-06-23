<?php

namespace App\Support;

class SkFilePathRules
{
    /**
     * Membatasi path SK ke file PDF relatif di folder sk/ agar input tidak bisa menyisipkan traversal path.
     *
     * @return array<int, string>
     */
    public static function nullablePdfPath(): array
    {
        return ['nullable', 'string', 'max:255', 'regex:/\Ask\/[A-Za-z0-9][A-Za-z0-9._-]*\.pdf\z/'];
    }
}
