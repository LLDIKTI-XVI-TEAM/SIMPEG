<?php

namespace Tests\Feature;

use App\Models\RefHariLibur;
use App\Services\WorkdayCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Menguji kalkulasi hari kerja untuk pengajuan cuti.
 * Aturan domain: hari kerja = total hari kalender dikurangi Sabtu, Minggu,
 * hari libur nasional, dan cuti bersama (keduanya tersimpan di ref_hari_libur).
 */
class WorkdayCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private WorkdayCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new WorkdayCalculator;
    }

    /**
     * Membuat entri hari libur/cuti bersama pada tanggal tertentu.
     * Cuti bersama dan libur nasional sama-sama mengurangi hari kerja.
     */
    private function buatHariLibur(string $tanggal, bool $cutiBersama = false): void
    {
        $date = Carbon::parse($tanggal);

        RefHariLibur::create([
            'tanggal' => $tanggal,
            'nama' => $cutiBersama ? 'Cuti Bersama' : 'Libur Nasional',
            'tahun' => (int) $date->year,
            'is_cuti_bersama' => $cutiBersama,
        ]);
    }

    public function test_rentang_senin_sampai_jumat_dihitung_penuh(): void
    {
        // 2026-01-05 (Senin) s.d. 2026-01-09 (Jumat) = 5 hari kerja, tanpa libur.
        $hari = $this->calculator->calculate(
            Carbon::parse('2026-01-05'),
            Carbon::parse('2026-01-09'),
        );

        $this->assertSame(5, $hari);
    }

    public function test_sabtu_dan_minggu_tidak_dihitung(): void
    {
        // 2026-01-05 (Senin) s.d. 2026-01-11 (Minggu): Sabtu+Minggu di-exclude, sisa 5 hari kerja.
        $hari = $this->calculator->calculate(
            Carbon::parse('2026-01-05'),
            Carbon::parse('2026-01-11'),
        );

        $this->assertSame(5, $hari);
    }

    public function test_libur_nasional_dikurangi_dari_hari_kerja(): void
    {
        // Libur nasional di hari kerja (Rabu 2026-01-07) harus mengurangi total.
        $this->buatHariLibur('2026-01-07');

        $hari = $this->calculator->calculate(
            Carbon::parse('2026-01-05'),
            Carbon::parse('2026-01-09'),
        );

        $this->assertSame(4, $hari);
    }

    public function test_cuti_bersama_dikurangi_dari_hari_kerja(): void
    {
        // Cuti bersama (Selasa 2026-01-06) juga mengurangi hari kerja, sama seperti libur nasional.
        $this->buatHariLibur('2026-01-06', cutiBersama: true);

        $hari = $this->calculator->calculate(
            Carbon::parse('2026-01-05'),
            Carbon::parse('2026-01-09'),
        );

        $this->assertSame(4, $hari);
    }

    public function test_libur_di_akhir_pekan_tidak_dihitung_dua_kali(): void
    {
        // Libur yang jatuh di hari Sabtu tidak boleh mengurangi dua kali (sudah ter-exclude sebagai weekend).
        $this->buatHariLibur('2026-01-10'); // Sabtu

        $hari = $this->calculator->calculate(
            Carbon::parse('2026-01-05'),
            Carbon::parse('2026-01-11'),
        );

        $this->assertSame(5, $hari);
    }

    public function test_rentang_lintas_tahun_dihitung_benar(): void
    {
        // Rentang menyeberang tahun (2025 -> 2026) harus mengikutkan libur dari kedua tahun.
        // 2025-12-29 (Senin) s.d. 2026-01-02 (Jumat) = 5 hari kerja; libur 2026-01-01 mengurangi jadi 4.
        $this->buatHariLibur('2026-01-01');

        $hari = $this->calculator->calculate(
            Carbon::parse('2025-12-29'),
            Carbon::parse('2026-01-02'),
        );

        $this->assertSame(4, $hari);
    }

    public function test_satu_hari_kerja_menghasilkan_satu(): void
    {
        $hari = $this->calculator->calculate(
            Carbon::parse('2026-01-05'),
            Carbon::parse('2026-01-05'),
        );

        $this->assertSame(1, $hari);
    }

    public function test_satu_hari_libur_menghasilkan_nol(): void
    {
        $this->buatHariLibur('2026-01-05');

        $hari = $this->calculator->calculate(
            Carbon::parse('2026-01-05'),
            Carbon::parse('2026-01-05'),
        );

        $this->assertSame(0, $hari);
    }

    public function test_tanggal_mulai_setelah_selesai_menghasilkan_nol(): void
    {
        // Rentang tidak valid (mulai > selesai) dikembalikan 0 secara defensif, tanpa exception.
        $hari = $this->calculator->calculate(
            Carbon::parse('2026-01-09'),
            Carbon::parse('2026-01-05'),
        );

        $this->assertSame(0, $hari);
    }

    public function test_peringatan_saat_tanggal_mulai_jatuh_di_akhir_pekan(): void
    {
        // 2026-01-03 = Sabtu, harus memunculkan peringatan untuk tanggal mulai.
        $warnings = $this->calculator->warnings(
            Carbon::parse('2026-01-03'),
            Carbon::parse('2026-01-09'),
        );

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsStringIgnoringCase('mulai', implode(' ', $warnings));
    }

    public function test_peringatan_saat_tanggal_selesai_jatuh_di_libur(): void
    {
        // 2026-01-09 (Jumat) ditandai libur, harus memunculkan peringatan untuk tanggal selesai.
        $this->buatHariLibur('2026-01-09');

        $warnings = $this->calculator->warnings(
            Carbon::parse('2026-01-05'),
            Carbon::parse('2026-01-09'),
        );

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsStringIgnoringCase('selesai', implode(' ', $warnings));
    }

    public function test_tanpa_peringatan_saat_mulai_dan_selesai_hari_kerja(): void
    {
        $warnings = $this->calculator->warnings(
            Carbon::parse('2026-01-05'),
            Carbon::parse('2026-01-09'),
        );

        $this->assertEmpty($warnings);
    }
}
