<?php

namespace App\Support\EmployeeImport;

use Illuminate\Support\Facades\Log;

class ImportFailureMessage
{
    public const USER_MESSAGE = 'Proses import pegawai gagal. Silakan coba lagi atau hubungi administrator.';

    public static function report(string $batchId, \Throwable $exception): void
    {
        Log::error('Eksekusi import pegawai gagal.', [
            'batch_id' => $batchId,
            'exception' => $exception::class,
            'detail_masked' => self::mask($exception->getMessage()),
        ]);
    }

    private static function mask(string $message): string
    {
        $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email-disamarkan]', $message) ?? $message;

        return preg_replace('/\b\d{16,18}\b/', '[nomor-identitas-disamarkan]', $message) ?? $message;
    }
}
