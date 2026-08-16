<?php

namespace App\Exceptions\Import;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Dilempar ketika schema import_batches belum dimigrasikan sehingga batch import tidak boleh diklaim.
 *
 * Exception ini merender responsnya sendiri agar pengguna selalu menerima pesan operasional
 * yang sama. Tanpa render eksplisit, handler bawaan Laravel akan menyertakan pesan SQL,
 * host/nama database, dan stack trace pada respons JSON saat APP_DEBUG aktif.
 */
class ImportSchemaNotReadyException extends RuntimeException
{
    /** Pesan tunggal untuk pengguna: operasional, tanpa detail internal database. */
    public const USER_MESSAGE = 'Database aplikasi belum siap untuk menjalankan import pegawai. Hubungi administrator sistem dan jalankan pembaruan database.';

    /** @param list<string> $missingColumns */
    public function __construct(private readonly array $missingColumns)
    {
        parent::__construct('Schema tabel import_batches belum lengkap untuk eksekusi import pegawai.');
    }

    /** @return list<string> */
    public function missingColumns(): array
    {
        return $this->missingColumns;
    }

    /** Kontrak respons tetap: hanya message Bahasa Indonesia dengan status 503. */
    public function render(): JsonResponse
    {
        return response()->json(
            ['message' => self::USER_MESSAGE],
            HttpResponse::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * Log diagnosis untuk operator berisi kolom yang hilang saja.
     * Payload import, identitas pegawai, dan token sengaja tidak dicatat karena bersifat sensitif.
     */
    public function report(): bool
    {
        Log::warning('Eksekusi import pegawai dihentikan karena schema database belum siap.', [
            'feature' => 'import.pegawai.schema_readiness',
            'table' => 'import_batches',
            'missing_columns' => $this->missingColumns,
        ]);

        return true;
    }
}
