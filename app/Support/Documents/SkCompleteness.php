<?php

namespace App\Support\Documents;

/**
 * Katalog kategori SK yang dapat dimasukkan ke matriks dokumen wajib.
 *
 * Daftar ini dipakai bersama oleh validasi, konfigurasi, dan kalkulator status
 * agar label maupun key kategori tidak menyimpang antarpermukaan.
 */
final class SkCompleteness
{
    /** @var array<string, string> */
    public const POOL = [
        'sk_pengangkatan' => 'SK Pengangkatan',
        'sk_pangkat' => 'SK Pangkat',
        'sk_jabatan' => 'SK Jabatan',
        'sk_kgb' => 'SK KGB',
    ];

    /** @var array<string, list<string>> */
    public const DEFAULTS = [
        'PNS' => ['sk_pengangkatan', 'sk_pangkat', 'sk_jabatan', 'sk_kgb'],
        'CPNS' => ['sk_pengangkatan', 'sk_pangkat', 'sk_jabatan', 'sk_kgb'],
    ];

    /** @return array<string, string> */
    public static function pool(): array
    {
        return self::POOL;
    }

    /** @return list<string> */
    public static function poolKeys(): array
    {
        return array_keys(self::POOL);
    }

    public static function label(string $key): string
    {
        return self::POOL[$key] ?? $key;
    }
}
