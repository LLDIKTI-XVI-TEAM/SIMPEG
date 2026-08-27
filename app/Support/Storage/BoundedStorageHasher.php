<?php

namespace App\Support\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

final class BoundedStorageHasher
{
    private const STREAM_CHUNK_BYTES = 8192;

    /**
     * Menghitung SHA-256 hanya setelah ukuran metadata memenuhi batas dan ukuran expected bila dipin.
     */
    public static function sha256(
        Filesystem $disk,
        string $path,
        int $maxBytes,
        ?int $expectedSize = null,
    ): string {
        if ($maxBytes <= 0
            || ($expectedSize !== null && ($expectedSize <= 0 || $expectedSize > $maxBytes))) {
            throw new RuntimeException('Batas atau ukuran expected untuk verifikasi storage tidak valid.');
        }

        $size = $disk->size($path);
        if ($size <= 0
            || $size > $maxBytes
            || ($expectedSize !== null && $size !== $expectedSize)) {
            throw new RuntimeException('Ukuran file storage tidak memenuhi kontrak verifikasi.');
        }

        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('File storage tidak dapat dibaca sebagai stream.');
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, self::STREAM_CHUNK_BYTES);
                if ($chunk === false) {
                    throw new RuntimeException('Pembacaan stream storage gagal.');
                }
                if ($chunk === '') {
                    if (feof($stream)) {
                        break;
                    }

                    throw new RuntimeException('Stream storage berhenti sebelum selesai.');
                }

                $bytes += strlen($chunk);
                if ($bytes > $maxBytes || $bytes > $size) {
                    throw new RuntimeException('Ukuran aktual stream storage melampaui batas metadata.');
                }
                hash_update($hash, $chunk);
            }

            if ($bytes !== $size) {
                throw new RuntimeException('Ukuran metadata dan stream storage tidak konsisten.');
            }

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }
}
