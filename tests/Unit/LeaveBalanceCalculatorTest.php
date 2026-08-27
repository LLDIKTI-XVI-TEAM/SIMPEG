<?php

namespace Tests\Unit;

use App\Services\Cuti\LeaveBalanceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit murni untuk aturan matematis saldo cuti tahunan.
 * Tidak menyentuh database, transaksi, audit, atau RBAC: hanya kalkulasi bucket N-2/N-1/tahun berjalan,
 * urutan potong N-2 -> N-1 -> tahun berjalan, larangan potong sebagian, serta cap rollover 18/24.
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

    public function test_fifo_mengonsumsi_n2_n1_lalu_tahun_berjalan(): void
    {
        $result = $this->calculator->allocateDeduction(
            ['n2' => 4, 'n1' => 6, 'current' => 12],
            5,
        );

        $this->assertTrue($result['success']);
        $this->assertSame(['n2' => 4, 'n1' => 1, 'current' => 0], $result['allocations']);
        $this->assertSame(['n2' => 0, 'n1' => 5, 'current' => 12], $result['remaining']);
        $this->assertSame(17, $this->calculator->availableTotal($result['remaining']));
    }

    public function test_replay_backdated_dua_hari_menghasilkan_total_lima_belas(): void
    {
        $result = $this->calculator->allocateDeduction(
            ['n2' => 4, 'n1' => 6, 'current' => 12],
            7,
        );

        $this->assertTrue($result['success']);
        $this->assertSame(['n2' => 0, 'n1' => 3, 'current' => 12], $result['remaining']);
        $this->assertSame(15, $this->calculator->availableTotal($result['remaining']));
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

    /**
     * @param  array{n2:int, n1:int, current:int}  $buckets
     * @param  array{n2:int, n1:int, current:int}  $allocations
     * @param  array{n2:int, n1:int, current:int}  $remaining
     */
    #[DataProvider('dutyPostponementAllocationProvider')]
    public function test_duty_postponement_allocates_protected_days_without_overlap(
        array $buckets,
        int $days,
        bool $success,
        array $allocations,
        array $remaining,
    ): void {
        $this->assertSame(
            compact('success', 'allocations', 'remaining'),
            $this->calculator->allocateDutyPostponement($buckets, $days),
        );
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
    public static function dutyPostponementAllocationProvider(): array
    {
        return [
            'current dilindungi lebih dahulu' => [
                ['n2' => 3, 'n1' => 4, 'current' => 5],
                7,
                true,
                ['n2' => 0, 'n1' => 2, 'current' => 5],
                ['n2' => 3, 'n1' => 2, 'current' => 0],
            ],
            'seluruh bucket dapat dilindungi' => [
                ['n2' => 2, 'n1' => 2, 'current' => 2],
                6,
                true,
                ['n2' => 2, 'n1' => 2, 'current' => 2],
                ['n2' => 0, 'n1' => 0, 'current' => 0],
            ],
            'saldo kurang tidak menghasilkan alokasi sebagian' => [
                ['n2' => 0, 'n1' => 1, 'current' => 2],
                4,
                false,
                ['n2' => 0, 'n1' => 0, 'current' => 0],
                ['n2' => 0, 'n1' => 1, 'current' => 2],
            ],
            'nol hari ditolak tanpa mengubah saldo' => [
                ['n2' => 1, 'n1' => 2, 'current' => 3],
                0,
                false,
                ['n2' => 0, 'n1' => 0, 'current' => 0],
                ['n2' => 1, 'n1' => 2, 'current' => 3],
            ],
            'hari negatif ditolak tanpa mengubah saldo' => [
                ['n2' => 1, 'n1' => 2, 'current' => 3],
                -1,
                false,
                ['n2' => 0, 'n1' => 0, 'current' => 0],
                ['n2' => 1, 'n1' => 2, 'current' => 3],
            ],
        ];
    }

    /**
     * @param  array{n2:int, n1:int, current:int, hangus:int, maxUsable:int}  $expected
     */
    #[DataProvider('usageBasedRolloverProvider')]
    public function test_rollover_berdasarkan_total_pemakaian_faktual(
        int $previousN1,
        int $previousCurrent,
        int $usageN2,
        int $usageN1,
        int $ceiling,
        array $expected,
    ): void {
        $this->assertSame($expected, $this->calculator->calculateRolloverFromUsage(
            previousN1: $previousN1,
            previousCurrent: $previousCurrent,
            usageN2: $usageN2,
            usageN1: $usageN1,
            maximumCeiling: $ceiling,
        ));
    }

    /**
     * @return array<string, array{int, int, int, int, int, array{n2:int, n1:int, current:int, hangus:int, maxUsable:int}}>
     */
    public static function usageBasedRolloverProvider(): array
    {
        return [
            'nol nol dapat mempertahankan dua carry maksimal enam' => [
                9, 8, 0, 0, 24,
                ['n2' => 6, 'n1' => 6, 'current' => 12, 'hangus' => 5, 'maxUsable' => 24],
            ],
            'nol positif hanya memakai ceiling delapan belas' => [
                9, 8, 0, 1, 18,
                ['n2' => 0, 'n1' => 6, 'current' => 12, 'hangus' => 11, 'maxUsable' => 18],
            ],
            'positif nol hanya memakai ceiling delapan belas' => [
                9, 8, 1, 0, 18,
                ['n2' => 0, 'n1' => 6, 'current' => 12, 'hangus' => 11, 'maxUsable' => 18],
            ],
            'positif positif hanya memakai ceiling delapan belas' => [
                9, 8, 1, 1, 18,
                ['n2' => 0, 'n1' => 6, 'current' => 12, 'hangus' => 11, 'maxUsable' => 18],
            ],
            'ceiling adalah batas dan tidak mengisi carry yang tidak tersedia' => [
                0, 2, 0, 0, 24,
                ['n2' => 0, 'n1' => 2, 'current' => 12, 'hangus' => 0, 'maxUsable' => 14],
            ],
            'bucket n2 kedaluwarsa ketika dua tahun tidak sama-sama nol' => [
                6, 0, 0, 2, 18,
                ['n2' => 0, 'n1' => 0, 'current' => 12, 'hangus' => 6, 'maxUsable' => 12],
            ],
        ];
    }

    /**
     * @param  array{n2:int, n1:int, current:int, hangus:int, maxUsable:int}  $expected
     */
    #[DataProvider('rolloverProvider')]
    public function test_rollover_applies_eligibility_caps_and_records_hangus(
        int $previousN1,
        int $previousCurrent,
        bool $sourceYearNoApprovedAnnualLeave,
        bool $twoYearsNoAnnualLeave,
        int $postponedByDuty,
        array $expected,
    ): void {
        $result = $this->calculator->calculateRollover(
            previousN1: $previousN1,
            previousCurrent: $previousCurrent,
            sourceYearNoApprovedAnnualLeave: $sourceYearNoApprovedAnnualLeave,
            twoYearsNoAnnualLeave: $twoYearsNoAnnualLeave,
            postponedByDuty: $postponedByDuty,
        );

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{
     *     0: int,
     *     1: int,
     *     2: bool,
     *     3: bool,
     *     4: int,
     *     5: array{n2:int, n1:int, current:int, hangus:int, maxUsable:int}
     * }>
     */
    public static function rolloverProvider(): array
    {
        return [
            'tanpa sisa tidak membuat carry' => [
                0, 0, true, false, 0,
                ['n2' => 0, 'n1' => 0, 'current' => 12, 'hangus' => 0, 'maxUsable' => 12],
            ],
            'Rule 1 tepat 6 hari dibawa seluruhnya' => [
                0, 6, true, false, 0,
                ['n2' => 0, 'n1' => 6, 'current' => 12, 'hangus' => 0, 'maxUsable' => 18],
            ],
            'Rule 1 tepat 7 hari dibatasi 6 dan 1 hangus' => [
                0, 7, true, false, 0,
                ['n2' => 0, 'n1' => 6, 'current' => 12, 'hangus' => 1, 'maxUsable' => 18],
            ],
            'approved annual leave menggagalkan ordinary carry 6 hari' => [
                0, 6, false, false, 0,
                ['n2' => 0, 'n1' => 0, 'current' => 12, 'hangus' => 6, 'maxUsable' => 12],
            ],
            'approved annual leave menggagalkan ordinary carry 7 hari' => [
                0, 7, false, false, 0,
                ['n2' => 0, 'n1' => 0, 'current' => 12, 'hangus' => 7, 'maxUsable' => 12],
            ],
            'Rule 2 dua tahun tanpa approval mempertahankan N-2 dan N-1 dengan cap 24' => [
                8, 9, true, true, 0,
                ['n2' => 6, 'n1' => 6, 'current' => 12, 'hangus' => 5, 'maxUsable' => 24],
            ],
            'approval tahun sebelumnya mematahkan N-2 tetapi Rule 1 tahun sumber tetap berlaku' => [
                8, 9, true, false, 0,
                ['n2' => 0, 'n1' => 6, 'current' => 12, 'hangus' => 11, 'maxUsable' => 18],
            ],
            'carry statutory tetap masuk ketika ordinary carry tidak eligible' => [
                0, 7, false, false, 4,
                ['n2' => 0, 'n1' => 4, 'current' => 12, 'hangus' => 7, 'maxUsable' => 16],
            ],
            'carry statutory dan ordinary berbagi ruang menuju total 24' => [
                0, 6, true, false, 10,
                ['n2' => 0, 'n1' => 12, 'current' => 12, 'hangus' => 4, 'maxUsable' => 24],
            ],
            'Rule 2 plus statutory tetap dibatasi 24' => [
                6, 6, true, true, 5,
                ['n2' => 6, 'n1' => 6, 'current' => 12, 'hangus' => 5, 'maxUsable' => 24],
            ],
        ];
    }
}
