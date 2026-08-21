<?php

namespace App\Support\EmployeeImport;

use App\Actions\Employees\UploadImportBatchAction;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Menjaga kompensasi cache batch agar tidak menimpa mutasi request atau proyeksi worker yang lebih baru. */
final class ImportBatchCacheMutation
{
    private readonly string $guardKey;

    private readonly string $mutationToken;

    private readonly ?string $previousToken;

    public function __construct(
        private readonly string $cacheKey,
        private readonly array $snapshot,
    ) {
        $this->guardKey = self::guardKeyFor($cacheKey);
        $this->mutationToken = (string) Str::uuid();
        $previousToken = Cache::get($this->guardKey);
        $this->previousToken = is_string($previousToken) ? $previousToken : null;
    }

    /** Mendapatkan guard key untuk key cache batch tertentu. */
    public static function guardKeyFor(string $cacheKey): string
    {
        return "{$cacheKey}:mutation";
    }

    /**
     * Memperbarui cache batch secara langsung (misal dari worker) sekaligus memutakhirkan
     * guard token agar kompensasi snapshot request terdahulu tidak menimpa state terbaru.
     *
     * @param  array<string, mixed>  $state
     */
    public static function putDirect(string $cacheKey, array $state, ?DateTimeInterface $expiresAt = null): void
    {
        $expiration = $expiresAt ?? now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES);
        $token = (string) Str::uuid();

        Cache::put(self::guardKeyFor($cacheKey), $token, $expiration);
        Cache::put($cacheKey, $state, $expiration);
    }

    /** Simpan state dan token pemilik agar kompensasi dapat mengenali mutasi yang lebih baru. */
    public function put(array $state): void
    {
        $expiresAt = now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES);
        Cache::put($this->guardKey, $this->mutationToken, $expiresAt);

        try {
            Cache::put($this->cacheKey, $state, $expiresAt);
        } catch (\Throwable $exception) {
            $this->restorePreviousToken($expiresAt);

            throw $exception;
        }
    }

    /** Pulihkan snapshot hanya jika belum ada request atau worker lain yang mengganti state batch. */
    public function restoreIfUnchanged(): void
    {
        if (Cache::get($this->guardKey) !== $this->mutationToken) {
            return;
        }

        $expiresAt = now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES);
        Cache::put($this->cacheKey, $this->snapshot, $expiresAt);
        $this->restorePreviousToken($expiresAt);
    }

    /** Kembalikan kepemilikan guard ke mutasi sebelumnya setelah kompensasi selesai. */
    private function restorePreviousToken(DateTimeInterface $expiresAt): void
    {
        if ($this->previousToken === null) {
            Cache::forget($this->guardKey);

            return;
        }

        Cache::put($this->guardKey, $this->previousToken, $expiresAt);
    }
}
