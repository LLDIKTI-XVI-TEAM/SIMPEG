<?php

namespace App\Services;

use Closure;
use Throwable;

/**
 * Menyelaraskan efek samping non-database dengan transaksi request terluar.
 *
 * Database dapat melakukan rollback, sedangkan storage tidak. Action yang membuat atau
 * menghapus berkas mendaftarkan kompensasi di sini agar middleware pemilik transaksi
 * menentukan callback yang aman setelah hasil commit atau rollback sudah pasti.
 */
class TransactionSideEffectManager
{
    private bool $active = false;

    private bool $databaseCommitted = false;

    /** @var list<Closure(): void> */
    private array $afterCommitCallbacks = [];

    /** @var list<Closure(): void> */
    private array $afterRollbackCallbacks = [];

    /** @var array<string, true> */
    private array $afterRollbackKeys = [];

    /** Mulai satu scope transaksi request dan buang callback usang dari request sebelumnya. */
    public function begin(): void
    {
        $this->reset();
        $this->active = true;
    }

    /** Daftarkan pekerjaan yang hanya aman setelah transaksi database berhasil commit. */
    public function afterCommit(Closure $callback): bool
    {
        if (! $this->active) {
            return false;
        }

        $this->afterCommitCallbacks[] = $callback;

        return true;
    }

    /** Daftarkan kompensasi untuk efek samping yang sudah terjadi sebelum commit. */
    public function afterRollback(Closure $callback): bool
    {
        if (! $this->active) {
            return false;
        }

        $this->afterRollbackCallbacks[] = $callback;

        return true;
    }

    /**
     * Tunda finalizer resource sampai hasil transaksi pasti. Urutan rollback dibalik,
     * sehingga kompensasi yang didaftarkan setelah finalizer selesai lebih dahulu.
     */
    public function afterCompletion(Closure $callback): bool
    {
        if (! $this->active) {
            return false;
        }

        $this->afterCommitCallbacks[] = $callback;
        $this->afterRollbackCallbacks[] = $callback;

        return true;
    }

    /**
     * Daftarkan satu kompensasi unik per resource agar beberapa perubahan dalam request
     * tetap kembali ke snapshot pertama, bukan ke state antara yang sudah dimutasi.
     */
    public function afterRollbackOnce(string $key, Closure $callback): bool
    {
        if (! $this->active) {
            return false;
        }

        if (isset($this->afterRollbackKeys[$key])) {
            return true;
        }

        $this->afterRollbackKeys[$key] = true;
        $this->afterRollbackCallbacks[] = $callback;

        return true;
    }

    /** Tandai bahwa PDO sudah commit sebelum callback pasca-commit lain dijalankan. */
    public function markDatabaseCommitted(): void
    {
        if ($this->active) {
            $this->databaseCommitted = true;
        }
    }

    /** Bedakan exception pasca-commit dari kegagalan yang masih dapat di-rollback. */
    public function databaseWasCommitted(): bool
    {
        return $this->databaseCommitted;
    }

    /** Jalankan efek samping commit setelah transaksi pemilik request benar-benar selesai. */
    public function commit(): void
    {
        $callbacks = $this->afterCommitCallbacks;
        $this->reset();
        $this->runCallbacks($callbacks);
    }

    /** Jalankan kompensasi rollback dan jangan pernah menjalankan callback commit. */
    public function rollback(): void
    {
        $callbacks = array_reverse($this->afterRollbackCallbacks);
        $this->reset();

        $this->runCallbacks($callbacks);
    }

    /**
     * Jalankan semua kompensasi meski salah satunya gagal, lalu teruskan kegagalan pertama
     * agar pemilik transaksi dapat melaporkannya tanpa menyisakan callback lain.
     *
     * @param  list<Closure(): void>  $callbacks
     */
    private function runCallbacks(array $callbacks): void
    {
        $firstFailure = null;

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $exception) {
                $firstFailure ??= $exception;
            }
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }

    private function reset(): void
    {
        $this->active = false;
        $this->databaseCommitted = false;
        $this->afterCommitCallbacks = [];
        $this->afterRollbackCallbacks = [];
        $this->afterRollbackKeys = [];
    }
}
