<?php

namespace Tests\Unit;

use App\Services\Cuti\LeaveBalanceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit murni untuk aturan matematis saldo cuti tahunan.
 * Tidak menyentuh database, transaksi, audit, atau RBAC: hanya kalkulasi bucket N-2/N-1/tahun berjalan,
 * urutan potong N-2 -> N-1 -> tahun berjalan, larangan potong sebagian, clamp koreksi, dan cap rollover 18/24.
 */
class LeaveBalanceCalculatorTest extends TestCase
{
    private LeaveBalanceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new LeaveBalanceCalculator;
    }

    public function test_annual_entitlement_is_full_twelve_days_without_proration(): void
    {
        $this->assertSame(12, $this->calculator->annualEntitlement());
    }

    public function test_available_total_sums_all_three_buckets(): void
    {
        $total = $this->calculator->availableTotal(['n2' => 3, 'n1' => 2, 'current' => 5]);

        $this->assertSame(10, $total);
    }

    public function test_insufficient_when_available_less_than_requested(): void
    {
        $buckets = ['n2' => 0, 'n1' => 3, 'current' => 5];

        $this->assertFalse($this->calculator->isSufficient($buckets, 10));
    }

    public function test_sufficient_when_available_meets_requested(): void
    {
        $buckets = ['n2' => 0, 'n1' => 3, 'current' => 5];

        $this->assertTrue($this->calculator->isSufficient($buckets, 8));
    }

    /**
     * @param  array{n2:int, n1:int, current:int}  $buckets
     * @param  array{n2:int, n1:int, current:int}  $expectedAllocations
     * @param  array{n2:int, n1:int, current:int}  $expectedRemaining
     */
    #[DataProvider('deductionProvider')]
    public function test_deduction_allocates_in_source_year_order(
        array $buckets,
        int $requested,
        bool $expectedSuccess,
        array $expectedAllocations,
        array $expectedRemaining,
    ): void {
        $result = $this->calculator->allocateDeduction($buckets, $requested);

        $this->assertSame($expectedSuccess, $result['success']);
        $this->assertSame($expectedAllocations, $result['allocations']);
        $this->assertSame($expectedRemaining, $result['remaining']);
    }

    /**
     * @return array<string, array{
     *     0: array{n2:int, n1:int, current:int},
     *     1: int,
     *     2: bool,
     *     3: array{n2:int, n1:int, current:int},
     *     4: array{n2:int, n1:int, current:int}
     * }>
     */
    public static function deductionProvider(): array
    {
        return [
            'urutan N-2 lalu N-1 lalu tahun berjalan' => [
                ['n2' => 3, 'n1' => 4, 'current' => 12],
                5,
                true,
                ['n2' => 3, 'n1' => 2, 'current' => 0],
                ['n2' => 0, 'n1' => 2, 'current' => 12],
            ],
            'potong lintas semua bucket sampai habis' => [
                ['n2' => 2, 'n1' => 2, 'current' => 2],
                6,
                true,
                ['n2' => 2, 'n1' => 2, 'current' => 2],
                ['n2' => 0, 'n1' => 0, 'current' => 0],
            ],
            'potong penuh dari tahun berjalan saja' => [
                ['n2' => 0, 'n1' => 0, 'current' => 12],
                12,
                true,
                ['n2' => 0, 'n1' => 0, 'current' => 12],
                ['n2' => 0, 'n1' => 0, 'current' => 0],
            ],
            'saldo tidak cukup: tidak ada potongan sebagian' => [
                ['n2' => 0, 'n1' => 3, 'current' => 5],
                10,
                false,
                ['n2' => 0, 'n1' => 0, 'current' => 0],
                ['n2' => 0, 'n1' => 3, 'current' => 5],
            ],
        ];
    }

    public function test_debit_correction_is_clamped_to_zero_and_records_applied_portion(): void
    {
        $result = $this->calculator->clampCorrection(2, -10);

        $this->assertSame(-2, $result['applied']);
        $this->assertSame(0, $result['newAvailable']);
    }

    public function test_debit_correction_within_balance_applies_fully(): void
    {
        $result = $this->calculator->clampCorrection(5, -3);

        $this->assertSame(-3, $result['applied']);
        $this->assertSame(2, $result['newAvailable']);
    }

    public function test_credit_correction_applies_fully(): void
    {
        $result = $this->calculator->clampCorrection(5, 4);

        $this->assertSame(4, $result['applied']);
        $this->assertSame(9, $result['newAvailable']);
    }

    /**
     * @param  array{n2:int, n1:int, current:int, hangus:int, maxUsable:int}  $expected
     */
    #[DataProvider('rolloverProvider')]
    public function test_rollover_applies_caps_and_records_hangus(
        int $previousN1,
        int $previousCurrent,
        bool $twoYearsNoAnnualLeave,
        int $postponedByDuty,
        array $expected,
    ): void {
        $result = $this->calculator->calculateRollover($previousN1, $previousCurrent, $twoYearsNoAnnualLeave, $postponedByDuty);

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{
     *     0: int,
     *     1: int,
     *     2: bool,
     *     3: int,
     *     4: array{n2:int, n1:int, current:int, hangus:int, maxUsable:int}
     * }>
     */
    public static function rolloverProvider(): array
    {
        return [
            'tanpa carry-over: seluruh sisa lama sudah habis, maksimal terpakai 12' => [
                0,
                0,
                false,
                0,
                ['n2' => 0, 'n1' => 0, 'current' => 12, 'hangus' => 0, 'maxUsable' => 12],
            ],
            'normal: sisa di atas 6 masuk N-1 maksimal 6, cap 18' => [
                0,
                10,
                false,
                0,
                ['n2' => 0, 'n1' => 6, 'current' => 12, 'hangus' => 4, 'maxUsable' => 18],
            ],
            'dua tahun tanpa cuti tahunan: N-2 dan N-1 masing-masing 6, cap 24' => [
                8,
                9,
                true,
                0,
                ['n2' => 6, 'n1' => 6, 'current' => 12, 'hangus' => 5, 'maxUsable' => 24],
            ],
            'cuti tahunan sebagian mematahkan aturan dua tahun: kembali ke cap 18' => [
                8,
                9,
                false,
                0,
                ['n2' => 0, 'n1' => 6, 'current' => 12, 'hangus' => 11, 'maxUsable' => 18],
            ],
            'penangguhan dinas: sisa ditangguhkan dihitung penuh tahun berikutnya, total maksimal 24' => [
                0,
                0,
                false,
                10,
                ['n2' => 0, 'n1' => 10, 'current' => 12, 'hangus' => 0, 'maxUsable' => 22],
            ],
            'penangguhan dinas: sisa normal dan sisa ditangguhkan harus dipisah agar tidak double-count' => [
                0,
                2,
                false,
                4,
                ['n2' => 0, 'n1' => 6, 'current' => 12, 'hangus' => 0, 'maxUsable' => 18],
            ],
            'penangguhan dinas: sisa di atas total 24 hangus' => [
                0,
                6,
                false,
                10,
                ['n2' => 0, 'n1' => 12, 'current' => 12, 'hangus' => 4, 'maxUsable' => 24],
            ],
            'dua tahun plus penangguhan dinas: total tetap dibatasi 24' => [
                6,
                6,
                true,
                5,
                ['n2' => 6, 'n1' => 6, 'current' => 12, 'hangus' => 5, 'maxUsable' => 24],
            ],
        ];
    }
}
