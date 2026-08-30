<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Services\Notifications\WhatsApp\WhatsAppRecipientResolver;
use Tests\TestCase;

class WhatsAppRecipientResolverTest extends TestCase
{
    private WhatsAppRecipientResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new WhatsAppRecipientResolver;
    }

    /**
     * Resolver hanya membaca atribut no_hp; model dibangun in-memory agar unit test
     * tetap bebas database dan dapat berjalan pada runner tanpa migrasi.
     */
    private function employee(?string $noHp): Employee
    {
        return (new Employee)->forceFill(['no_hp' => $noHp]);
    }

    public function test_nomor_format_lokal_062_dinormalisasi_ke_62(): void
    {
        $this->assertSame('6281234567890', $this->resolver->resolve($this->employee('081234567890')));
    }

    public function test_nomor_tanpa_prefix_dinormalisasi_ke_62(): void
    {
        $this->assertSame('6281234567890', $this->resolver->resolve($this->employee('81234567890')));
    }

    public function test_nomor_62_dan_plus62_dipertahankan(): void
    {
        $this->assertSame('6281234567890', $this->resolver->resolve($this->employee('6281234567890')));
        $this->assertSame('6281234567890', $this->resolver->resolve($this->employee('+62 812-3456-7890')));
    }

    public function test_awalan_internasional_00_dihapus(): void
    {
        $this->assertSame('6281234567890', $this->resolver->resolve($this->employee('006281234567890')));
    }

    public function test_nol_trunk_setelah_kode_negara_dibuang(): void
    {
        // Bentuk penulisan lazim "+62 (0)812..." harus menjadi 62812..., bukan 620812...
        $this->assertSame('6281234567890', $this->resolver->resolve($this->employee('+62 (0)812-3456-7890')));
        $this->assertSame('6281234567890', $this->resolver->resolve($this->employee('62081234567890')));
    }

    public function test_nomor_kosong_atau_tidak_valid_menghasilkan_null(): void
    {
        $this->assertNull($this->resolver->resolve($this->employee(null)));
        $this->assertNull($this->resolver->resolve($this->employee('   ')));
        $this->assertNull($this->resolver->resolve($this->employee('abcd-efgh')));
        $this->assertNull($this->resolver->resolve($this->employee('+6581234567')));
        $this->assertNull($this->resolver->resolve($this->employee('+81 90-1234-5678')));
        $this->assertNull($this->resolver->resolve($this->employee('00819012345678')));
    }

    public function test_panjang_nomor_di_luar_rentang_ditolak(): void
    {
        // Terlalu pendek untuk E.164 Indonesia
        $this->assertNull($this->resolver->resolve($this->employee('081234')));
        // Terlalu panjang
        $this->assertNull($this->resolver->resolve($this->employee('08123456789012345678')));
    }

    public function test_teks_label_dua_nomor_dan_pemisah_di_luar_format_nomor_ditolak(): void
    {
        foreach ([
            '0812O3456789',
            '08123456789 ext 123',
            'WA: 08123456789',
            '08123456789, 081298765432',
            '0812/3456-7890',
            '0812#3456-7890',
            '0812,3456-7890',
            '0812;3456-7890',
        ] as $raw) {
            $this->assertNull($this->resolver->resolve($this->employee($raw)), $raw);
        }
    }

    public function test_format_telepon_lazim_tetap_dinormalisasi(): void
    {
        $this->assertSame('6281234567890', $this->resolver->resolve($this->employee('+62 (0)812.3456-7890')));
        $this->assertSame('6281234567890', $this->resolver->resolve($this->employee('0812 3456.7890')));
    }

    public function test_normalize_statis_konsisten_dengan_resolve(): void
    {
        $this->assertSame('6281234567890', WhatsAppRecipientResolver::normalize('0812-3456-7890'));
        $this->assertNull(WhatsAppRecipientResolver::normalize(null));
    }
}
