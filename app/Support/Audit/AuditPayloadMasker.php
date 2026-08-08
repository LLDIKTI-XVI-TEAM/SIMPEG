<?php

namespace App\Support\Audit;

/**
 * Menyamarkan nomor identitas pribadi pada payload audit.
 *
 * Dipakai di dua sisi. Di sisi tulis supaya nomor identitas tidak pernah tersimpan utuh,
 * dan di sisi baca supaya baris lama yang sudah menyimpannya tidak ikut terbaca. Sisi baca
 * tetap diperlukan karena audit bersifat append-only sehingga baris yang sudah terbentuk
 * tidak dapat diperbaiki lagi.
 */
class AuditPayloadMasker
{
    /**
     * Kunci payload yang memuat identitas pribadi pegawai. `nik_hash` ikut disamarkan karena
     * nomor induk memiliki pola tetap sehingga nilai hash dapat dicocokkan kembali ke
     * pemiliknya dengan pencarian yang tidak mahal.
     */
    private const SENSITIVE_KEYS = ['nik', 'no_kk', 'nik_hash'];

    /**
     * Empat karakter terakhir tetap ditampilkan mengikuti pola penyamaran yang sudah dipakai
     * pada audit pemetaan pengguna, supaya pembaca audit masih dapat melihat bahwa nilainya
     * berubah tanpa mengetahui nomor lengkapnya.
     */
    private const VISIBLE_CHARACTERS = 4;

    /**
     * @param  array<array-key, mixed>|null  $values
     * @return array<array-key, mixed>|null
     */
    public static function mask(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $masked = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $masked[$key] = self::mask($value);

                continue;
            }

            $masked[$key] = self::isSensitive($key) ? self::maskValue($value) : $value;
        }

        return $masked;
    }

    private static function isSensitive(int|string $key): bool
    {
        return is_string($key) && in_array($key, self::SENSITIVE_KEYS, true);
    }

    private static function maskValue(mixed $value): mixed
    {
        if (! is_string($value) && ! is_int($value)) {
            return $value;
        }

        $plain = (string) $value;

        if ($plain === '') {
            return $value;
        }

        $visible = min(self::VISIBLE_CHARACTERS, strlen($plain));

        return str_repeat('*', max(strlen($plain) - $visible, 0)).substr($plain, -$visible);
    }
}
