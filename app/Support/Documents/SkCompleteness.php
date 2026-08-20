<?php

namespace App\Support\Documents;

/**
 * Katalog SK yang dinilai kelengkapan dokumen pegawai beserta nilai default
 * matriks "SK wajib per jenis pegawai".
 *
 * Menjadi satu sumber kebenaran agar service penilaian kelengkapan dan halaman
 * konfigurasi super admin tidak menduplikasi daftar SK atau nilai default.
 */
final class SkCompleteness
{
    /**
     * Seluruh SK yang dapat diwajibkan, dengan label tampilannya.
     *
     * @var array<string, string>
     */
    public const POOL = [
        'sk_pengangkatan' => 'SK Pengangkatan',
        'sk_pangkat' => 'SK Pangkat',
        'sk_jabatan' => 'SK Jabatan',
        'sk_kgb' => 'SK KGB',
    ];

    /**
     * Default matriks wajib per jenis pegawai (nama jenis pegawai → SK wajib).
     *
     * @var array<string, list<string>>
     */
    public const DEFAULTS = [
        'PNS' => ['sk_pengangkatan', 'sk_pangkat', 'sk_jabatan', 'sk_kgb'],
        'CPNS' => ['sk_pengangkatan', 'sk_pangkat', 'sk_jabatan', 'sk_kgb'],
        'PPPK' => ['sk_pengangkatan', 'sk_kgb'],
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

    /**
     * Nilai default SK wajib untuk sebuah nama jenis pegawai, atau null bila
     * jenis tidak memiliki default.
     *
     * @return list<string>|null
     */
    public static function defaultFor(?string $jenisPegawaiNama): ?array
    {
        if ($jenisPegawaiNama === null) {
            return null;
        }

        return self::DEFAULTS[$jenisPegawaiNama] ?? null;
    }
}
