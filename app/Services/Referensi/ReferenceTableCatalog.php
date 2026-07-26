<?php

namespace App\Services\Referensi;

use App\Models\RefEselon;
use App\Models\RefJenjangPendidikan;

/**
 * Katalog dependensi reference table untuk kebijakan hapus hybrid:
 * daftar tabel/kolom pemakai menentukan apakah sebuah item boleh dihapus
 * permanen atau hanya boleh dinonaktifkan, dan daftar cache key memastikan
 * dropdown yang di-cache langsung segar setelah data referensi berubah.
 */
final class ReferenceTableCatalog
{
    /**
     * @var array<class-string, array{usage: list<array{table: string, column: string, label: string}>, cache_keys: list<string>}>
     */
    public const DEFINITIONS = [
        RefEselon::class => [
            // Kedua FK pemakai eselon bersifat nullOnDelete sehingga database
            // tidak memblokir penghapusan; guard aplikasi dari peta inilah
            // satu-satunya pelindung data riwayat.
            'usage' => [
                ['table' => 'position_histories', 'column' => 'eselon_id', 'label' => 'riwayat jabatan'],
                ['table' => 'ref_jabatan', 'column' => 'eselon_id', 'label' => 'referensi jabatan'],
            ],
            'cache_keys' => ['ref.eselon'],
        ],
        RefJenjangPendidikan::class => [
            'usage' => [
                ['table' => 'education_histories', 'column' => 'jenjang_id', 'label' => 'riwayat pendidikan'],
            ],
            'cache_keys' => [],
        ],
    ];

    /**
     * @return list<array{table: string, column: string, label: string}>
     */
    public static function usageReferences(string $modelClass): array
    {
        return self::DEFINITIONS[$modelClass]['usage'] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function cacheKeys(string $modelClass): array
    {
        return self::DEFINITIONS[$modelClass]['cache_keys'] ?? [];
    }
}
