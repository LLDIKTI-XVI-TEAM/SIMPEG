<?php

namespace App\Services\Referensi;

use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenjangPendidikan;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use Illuminate\Database\Eloquent\Model;

/**
 * Katalog dependensi reference table untuk kebijakan hapus hybrid:
 * daftar tabel/kolom pemakai menentukan apakah sebuah item boleh dihapus
 * permanen atau hanya boleh dinonaktifkan, dan daftar cache key memastikan
 * dropdown yang di-cache langsung segar setelah data referensi berubah.
 * Entri `protected` menandai baris yang dirujuk langsung oleh logika
 * aplikasi sehingga tidak boleh dihapus maupun dinonaktifkan sama sekali.
 */
final class ReferenceTableCatalog
{
    /**
     * @var array<class-string, array{
     *     usage: list<array{table: string, column: string, label: string}>,
     *     cache_keys: list<string>,
     *     protected?: list<array{column: string, values: list<mixed>, reason: string}>
     * }>
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
        RefGolongan::class => [
            'usage' => [
                ['table' => 'rank_histories', 'column' => 'golongan_id', 'label' => 'riwayat kepangkatan'],
            ],
            'cache_keys' => ['ref.golongan'],
        ],
        RefJenisJabatan::class => [
            // FK ref_jabatan.jenis_jabatan_id bersifat nullOnDelete sehingga
            // database tidak memblokir penghapusan; guard aplikasi melindungi
            // sumber fallback BUP pensiun agar tidak hilang diam-diam.
            'usage' => [
                ['table' => 'position_histories', 'column' => 'jenis_jabatan_id', 'label' => 'riwayat jabatan'],
                ['table' => 'ref_jabatan', 'column' => 'jenis_jabatan_id', 'label' => 'referensi jabatan'],
            ],
            'cache_keys' => ['ref.jenis_jabatan', 'ref.jabatan_with_jenis'],
        ],
        RefUnitKerja::class => [
            // Self-FK parent_id bersifat nullOnDelete: menghapus induk tidak
            // ditolak database, justru anaknya diam-diam menjadi root dengan
            // level basi. Karena itu sub-unit didaftarkan sebagai pemakai agar
            // guard penghapusan generik menolaknya lebih dulu.
            'usage' => [
                ['table' => 'position_histories', 'column' => 'unit_kerja_id', 'label' => 'riwayat jabatan'],
                ['table' => 'ref_unit_kerja', 'column' => 'parent_id', 'label' => 'sub-unit'],
            ],
            'cache_keys' => ['ref.unit_kerja'],
        ],
        RefStatusPegawai::class => [
            // FK employees.status_pegawai_id bersifat nullOnDelete; tanpa
            // guard ini status pegawai bisa terhapus diam-diam dari data
            // pegawai yang merujuknya.
            'usage' => [
                ['table' => 'employees', 'column' => 'status_pegawai_id', 'label' => 'data pegawai'],
            ],
            'cache_keys' => ['ref.status_pegawai'],
            // Baris terproteksi: kode PENSIUN dicari langsung oleh proses
            // followup EWS (firstOrFail — hilang berarti error 500). Baris
            // AKTIF dilindungi bukan karena kodenya di-lookup, melainkan
            // karena nama 'Aktif' dipakai sebagai fallback status pegawai
            // baru/hasil import dan tersalin ke kolom legacy
            // employees.status_aktif yang difilter scheduler EWS. Baris
            // is_default adalah fallback resmi pegawai baru. Menghapus atau
            // menonaktifkan baris-baris ini mematikan alur tersebut tanpa
            // error yang terlihat admin.
            'protected' => [
                ['column' => 'kode', 'values' => ['AKTIF', 'PENSIUN'], 'reason' => 'baris ini dirujuk logika sistem (followup pensiun EWS dan penetapan status aktif pegawai)'],
                ['column' => 'is_default', 'values' => [true], 'reason' => 'status ini menjadi default untuk pegawai baru dan hasil import'],
            ],
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

    /**
     * Mengembalikan alasan proteksi bila item merupakan data sistem yang
     * tidak boleh dihapus/dinonaktifkan; null bila item bebas dikelola.
     */
    public static function protectionReason(Model $item): ?string
    {
        foreach (self::DEFINITIONS[$item::class]['protected'] ?? [] as $rule) {
            if (in_array($item->getAttribute($rule['column']), $rule['values'], true)) {
                return $rule['reason'];
            }
        }

        return null;
    }
}
