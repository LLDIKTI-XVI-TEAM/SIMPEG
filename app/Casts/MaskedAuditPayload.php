<?php

namespace App\Casts;

use App\Support\Audit\AuditPayloadMasker;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Payload audit yang selalu tersamarkan, baik saat disimpan maupun saat dibaca kembali.
 *
 * Penyamaran dijalankan pada kedua arah karena audit bersifat append-only. Sisi simpan
 * mencegah nomor identitas baru tersimpan utuh, sedangkan sisi baca menutup baris yang
 * sudah terbentuk sebelum aturan ini berlaku dan tidak dapat diperbaiki lagi.
 *
 * @implements CastsAttributes<array<array-key, mixed>|null, array<array-key, mixed>|null>
 */
class MaskedAuditPayload implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<array-key, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return AuditPayloadMasker::mask(self::decode($value));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $masked = AuditPayloadMasker::mask(self::decode($value));

        return $masked === null ? null : json_encode($masked, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
