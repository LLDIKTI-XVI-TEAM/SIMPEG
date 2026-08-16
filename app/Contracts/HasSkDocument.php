<?php

namespace App\Contracts;

use Illuminate\Support\Carbon;

/**
 * Kontrak untuk model riwayat yang memiliki referensi berkas SK (Surat Keputusan).
 *
 * Semua model yang menyimpan metadata SK — no_sk, tanggal_sk, dan file_sk —
 * wajib mengimplementasikan interface ini agar dapat diproses oleh
 * StoreDocumentAction secara type-safe.
 *
 * @property string|null $no_sk
 * @property Carbon|null $tanggal_sk
 * @property string|null $file_sk
 *
 * @method bool update(array $attributes = [], array $options = [])
 */
interface HasSkDocument
{
    //
}
