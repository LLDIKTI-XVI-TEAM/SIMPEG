<?php

namespace App\Support\EmployeeImport;

use App\Actions\Employees\UploadImportBatchAction;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Menjaga kompensasi cache batch agar tidak menimpa mutasi request atau proyeksi worker yang lebih baru. */
final class ImportBatchCacheMutation
{
    /** Durasi singkat untuk menyatukan cek guard dan penulisan state cache batch. */
    private const LOCK_SECONDS = 15;

    /** Batas tunggu tetap kecil karena setiap critical section hanya operasi cache lokal. */
    private const LOCK_WAIT_SECONDS = 5;

    private readonly string $guardKey;

    private readonly string $mutationToken;

    private ?string $previousToken = null;

    private bool $hasCapturedPreviousToken = false;

    public function __construct(
        private readonly string $cacheKey,
        private readonly array $snapshot,
    ) {
        $this->guardKey = self::guardKeyFor($cacheKey);
        $this->mutationToken = (string) Str::uuid();
    }

    /** Mendapatkan guard key untuk key cache batch tertentu. */
    public static function guardKeyFor(string $cacheKey): string
    {
        return "{$cacheKey}:mutation";
    }

    /** Mendapatkan key lock yang dipakai bersama oleh rollback request dan proyeksi worker. */
    public static function lockKeyFor(string $cacheKey): string
    {
        return "{$cacheKey}:mutation-lock";
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

        self::withMutationLock($cacheKey, function () use ($cacheKey, $state, $expiration, $token): void {
            Cache::put(self::guardKeyFor($cacheKey), $token, $expiration);
            Cache::put($cacheKey, $state, $expiration);
        });
    }

    /** Simpan state dan token pemilik agar kompensasi dapat mengenali mutasi yang lebih baru. */
    public function put(array $state): void
    {
        $expiresAt = now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES);

        self::withMutationLock($this->cacheKey, function () use ($state, $expiresAt): void {
            $this->capturePreviousToken();
            Cache::put($this->guardKey, $this->mutationToken, $expiresAt);

            try {
                Cache::put($this->cacheKey, $state, $expiresAt);
            } catch (\Throwable $exception) {
                $this->restorePreviousToken($expiresAt);

                throw $exception;
            }
        });
    }

    /** Pulihkan snapshot hanya jika belum ada request atau worker lain yang mengganti state batch. */
    public function restoreIfUnchanged(): void
    {
        self::withMutationLock($this->cacheKey, function (): void {
            if (Cache::get($this->guardKey) !== $this->mutationToken) {
                return;
            }

            $expiresAt = now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES);
            Cache::put($this->cacheKey, $this->snapshot, $expiresAt);
            $this->restorePreviousToken($expiresAt);
        });
    }

    /** Simpan guard sebelum mutasi pertama agar rollback mengembalikan pemilik yang benar. */
    private function capturePreviousToken(): void
    {
        if ($this->hasCapturedPreviousToken) {
            return;
        }

        $previousToken = Cache::get($this->guardKey);
        $this->previousToken = is_string($previousToken) ? $previousToken : null;
        $this->hasCapturedPreviousToken = true;
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

    /** Jalankan perubahan cache batch secara serial agar cek guard dan rollback tidak terpisah. */
    private static function withMutationLock(string $cacheKey, \Closure $callback): void
    {
        Cache::lock(self::lockKeyFor($cacheKey), self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, $callback);
    }
}
