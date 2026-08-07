<?php

namespace Tests\Unit\Cuti;

use App\Support\Cuti\CutiPeriodFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Mengunci kontrak nilai filter periode cuti agar daftar dan rekap memakai satu tafsir yang sama.
 */
class CutiPeriodFilterTest extends TestCase
{
    public function test_tahun_saja_diterima_sebagai_rentang_setahun(): void
    {
        $period = CutiPeriodFilter::parse('2026');

        $this->assertNotNull($period);
        $this->assertSame(2026, $period->year);
        $this->assertNull($period->month);
        $this->assertSame('2026-01-01', $period->startsAt()->toDateString());
        $this->assertSame('2027-01-01', $period->endsBeforeAt()->toDateString());
    }

    public function test_tahun_bulan_diterima_sebagai_rentang_sebulan(): void
    {
        $period = CutiPeriodFilter::parse('2026-07');

        $this->assertNotNull($period);
        $this->assertSame(2026, $period->year);
        $this->assertSame(7, $period->month);
        $this->assertSame('2026-07-01', $period->startsAt()->toDateString());
        $this->assertSame('2026-08-01', $period->endsBeforeAt()->toDateString());
    }

    public function test_batas_rentang_desember_pindah_ke_tahun_berikutnya(): void
    {
        $period = CutiPeriodFilter::parse('2026-12');

        $this->assertNotNull($period);
        $this->assertSame('2026-12-01', $period->startsAt()->toDateString());
        $this->assertSame('2027-01-01', $period->endsBeforeAt()->toDateString());
    }

    /**
     * Nama bulan Indonesia dipakai permukaan rekap sebelum helper ini ada; dukungannya wajib dipertahankan.
     */
    public function test_nama_bulan_indonesia_tetap_diterima(): void
    {
        $period = CutiPeriodFilter::parse('Juli 2026');

        $this->assertNotNull($period);
        $this->assertSame(2026, $period->year);
        $this->assertSame(7, $period->month);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nilaiTidakSahProvider(): array
    {
        return [
            'string kosong' => [''],
            'bukan angka' => ['abc'],
            'bulan di luar rentang' => ['2026-13'],
            'bulan nol' => ['2026-00'],
            'tahun tidak lengkap' => ['202'],
            'nama bulan tidak dikenal' => ['Smaptember 2026'],
            'null' => [null],
            'angka' => [2026],
            'array' => [['2026']],
            // Tahun 0 tidak ada pada kalender PostgreSQL; bila diteruskan, rentangnya menggagalkan permintaan.
            'tahun nol' => ['0000'],
            'tahun nol dengan bulan' => ['0000-01'],
            'tahun nol dengan nama bulan' => ['Januari 0000'],
        ];
    }

    #[DataProvider('nilaiTidakSahProvider')]
    public function test_nilai_tidak_sah_tidak_menghasilkan_filter(mixed $value): void
    {
        $this->assertNull(CutiPeriodFilter::parse($value));
    }

    public function test_label_membedakan_cakupan_tahun_dan_bulan(): void
    {
        $this->assertSame('2026', CutiPeriodFilter::parse('2026')?->label());
        $this->assertSame('2026-07', CutiPeriodFilter::parse('2026-07')?->label());
    }
}
