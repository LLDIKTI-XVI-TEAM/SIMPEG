<?php

namespace App\Support\ProgramStudi;

final class ProgramStudiNameNormalizer
{
    /** Bentuk nama kanonis menjaga input, snapshot, dan constraint tetap selaras. */
    public static function normalize(string $name): string
    {
        $collapsedName = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($collapsedName);
    }

    /** Kunci pencarian menyatukan variasi spasi dan kapitalisasi nama. */
    public static function key(string $name): string
    {
        return mb_strtolower(self::normalize($name));
    }
}
