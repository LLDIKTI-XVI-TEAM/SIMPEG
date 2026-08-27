<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Services\Cuti\AnnualLeaveCeilingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnnualLeaveCeilingTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('decisionTableProvider')]
    public function test_decision_table_pemakaian_membatasi_ceiling_pns(
        int $usageN2,
        int $usageN1,
        int $expected,
    ): void {
        $employee = $this->employee('PNS');

        $this->assertSame(
            $expected,
            app(AnnualLeaveCeilingService::class)->maximumFor($employee, 2026, $usageN2, $usageN1),
        );
    }

    /** @return array<string, array{int, int, int}> */
    public static function decisionTableProvider(): array
    {
        return [
            'nol nol' => [0, 0, 24],
            'nol positif' => [0, 1, 18],
            'positif nol' => [1, 0, 18],
            'positif positif' => [1, 1, 18],
        ];
    }

    #[DataProvider('pppkDurationProvider')]
    public function test_ceiling_pppk_memakai_batas_durasi_kontrak_kalender(
        string $contractStart,
        string $contractEnd,
        int $expected,
    ): void {
        $employee = $this->employee('PPPK', $contractEnd);
        $this->appointment($employee, 'PPPK', $contractStart);

        $this->assertSame(
            $expected,
            app(AnnualLeaveCeilingService::class)->maximumFor($employee, 2026, 0, 0),
        );
    }

    /** @return array<string, array{string, string, int}> */
    public static function pppkDurationProvider(): array
    {
        return [
            'tepat dua tahun' => ['2024-01-01', '2026-01-01', 12],
            'lebih dari dua tahun' => ['2023-07-01', '2026-01-01', 18],
            'tepat tiga tahun' => ['2023-01-01', '2026-01-01', 18],
            'lebih dari tiga tahun' => ['2022-12-31', '2026-01-01', 24],
        ];
    }

    public function test_pppk_lebih_tiga_tahun_tetap_dibatasi_delapan_belas_bila_ada_pemakaian(): void
    {
        $employee = $this->employee('PPPK', '2026-01-02');
        $this->appointment($employee, 'PPPK', '2023-01-01');

        $this->assertSame(18, app(AnnualLeaveCeilingService::class)->maximumFor($employee, 2026, 1, 0));
    }

    public function test_pppk_dengan_data_kontrak_tidak_lengkap_fail_closed_ke_dua_belas(): void
    {
        $tanpaAkhirKontrak = $this->employee('PPPK');
        $this->appointment($tanpaAkhirKontrak, 'PPPK', '2023-01-01');

        $tanpaTmtPppk = $this->employee('PPPK', '2027-01-01');

        $service = app(AnnualLeaveCeilingService::class);

        $this->assertSame(12, $service->maximumFor($tanpaAkhirKontrak, 2026, 0, 0));
        $this->assertSame(12, $service->maximumFor($tanpaTmtPppk, 2026, 0, 0));
    }

    public function test_riwayat_pengangkatan_non_pppk_tidak_membatasi_ceiling_pns(): void
    {
        $employee = $this->employee('PNS');
        $this->appointment($employee, 'PNS', '2025-01-01');

        $this->assertSame(24, app(AnnualLeaveCeilingService::class)->maximumFor($employee, 2026, 0, 0));
    }

    public function test_pppk_yang_kontraknya_tidak_overlap_tahun_saldo_fail_closed_ke_dua_belas(): void
    {
        $employee = $this->employee('PPPK', '2025-12-31');
        $this->appointment($employee, 'PPPK', '2022-01-01');

        $this->assertSame(12, app(AnnualLeaveCeilingService::class)->maximumFor($employee, 2026, 0, 0));
    }

    public function test_memilih_riwayat_pppk_efektif_terbaru_bukan_relasi_appointment_arbitrer(): void
    {
        $employee = $this->employee('PPPK', '2027-01-02');
        $this->appointment($employee, 'PPPK', '2020-01-01');
        $this->appointment($employee, 'PPPK', '2024-07-01');
        $this->appointment($employee, 'PPPK', '2027-01-01');

        $this->assertSame(18, app(AnnualLeaveCeilingService::class)->maximumFor($employee, 2026, 0, 0));
    }

    private function employee(string $type, ?string $contractEnd = null): Employee
    {
        $jenis = RefJenisPegawai::firstOrCreate(['nama' => $type]);

        return Employee::factory()->create([
            'jenis_pegawai_id' => $jenis->id,
            'tanggal_akhir_kontrak' => $contractEnd,
        ]);
    }

    private function appointment(Employee $employee, string $type, string $tmt): Appointment
    {
        return Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => $type,
            'tmt_pengangkatan' => $tmt,
            'no_sk' => fake()->unique()->numerify('SK-#####'),
            'tanggal_sk' => $tmt,
        ]);
    }
}
