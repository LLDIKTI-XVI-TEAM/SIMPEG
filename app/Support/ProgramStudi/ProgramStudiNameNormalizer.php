<?php

namespace App\Support\ProgramStudi;

final class ProgramStudiNameNormalizer
{
    /** Bentuk nama kanonis menjaga input, snapshot, dan constraint tetap selaras. */
    public static function normalize(mixed $name): string
    {
        $rawName = (string) $name;
        $collapsedName = preg_replace('/\s+/u', ' ', $rawName) ?? $rawName;

        return trim($collapsedName);
    }

    /** Kunci pencarian menyatukan variasi spasi dan kapitalisasi nama. */
    public static function key(mixed $name): string
    {
        return mb_strtolower(self::normalize($name));
    }
}
