<?php

namespace App\Support\Audit;

use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * Membersihkan nilai penyaring audit sebelum masuk ke klausa query.
 *
 * Kolom waktu dan pengenal pada audit bertipe ketat di PostgreSQL, sehingga nilai yang tidak
 * berbentuk tanggal atau UUID akan ditolak basis data dan berakhir sebagai galat peladen.
 * Nilai yang tidak sah diperlakukan sebagai penyaring yang tidak diisi.
 */
class AuditFilterValue
{
    public static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    public static function date(mixed $value): string
    {
        $teks = self::text($value);

        if ($teks === '') {
            return '';
        }

        $tanggal = DateTimeImmutable::createFromFormat('!Y-m-d', $teks);

        return $tanggal !== false && $tanggal->format('Y-m-d') === $teks ? $teks : '';
    }

    public static function uuid(mixed $value): string
    {
        $teks = self::text($value);

        return Str::isUuid($teks) ? $teks : '';
    }
}
