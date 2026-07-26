<?php

namespace Tests\Feature;

use App\Models\RefBup;
use App\Models\RefEselon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReferenceTableIsActiveMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const TABLES_WITH_IS_ACTIVE = [
        'ref_golongan',
        'ref_jenis_jabatan',
        'ref_status_pegawai',
        'ref_eselon',
        'ref_jenjang_pendidikan',
        'ref_bup',
        // Dua tabel berikut mendapat is_active dari migrasi sebelumnya; ikut
        // dikunci di sini agar kebijakan hybrid nonaktif punya cakupan lengkap.
        'ref_jabatan',
        'ref_unit_kerja',
    ];

    public function test_seluruh_tabel_referensi_kebijakan_hybrid_memiliki_kolom_is_active(): void
    {
        foreach (self::TABLES_WITH_IS_ACTIVE as $tableName) {
            $this->assertTrue(
                Schema::hasColumn($tableName, 'is_active'),
                sprintf('Tabel %s belum memiliki kolom is_active.', $tableName),
            );
        }
    }

    public function test_baris_referensi_baru_aktif_secara_default(): void
    {
        // Default true wajib agar data referensi lama tetap dianggap aktif dan
        // dropdown input tidak mendadak kosong setelah migrasi.
        $eselon = RefEselon::create(['kode' => 'IV.b', 'nama' => 'Eselon IV.b']);
        $bup = RefBup::create(['jenis_jabatan' => 'Fungsional Tertentu', 'bup_tahun' => 60]);

        $this->assertTrue($eselon->refresh()->is_active);
        $this->assertTrue($bup->refresh()->is_active);
    }

    public function test_kolom_is_active_dapat_dinonaktifkan_dan_dibaca_sebagai_boolean(): void
    {
        $eselon = RefEselon::create(['kode' => 'IV.c', 'nama' => 'Eselon IV.c', 'is_active' => false]);

        $this->assertFalse($eselon->refresh()->is_active);
        $this->assertSame(1, RefEselon::query()->where('is_active', false)->count());
    }
}
