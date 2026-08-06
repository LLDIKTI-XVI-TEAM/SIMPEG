<?php

namespace App\Services\Cuti;

/**
 * Kalkulator murni aturan saldo cuti tahunan.
 *
 * Kelas ini hanya berisi matematika domain saldo dan sengaja bebas database, transaksi, audit, dan RBAC
 * supaya aturan cuti mudah diuji dan dipakai ulang oleh service ledger saldo cuti.
 *
 * Istilah bucket:
 * - "n2"      : sisa saldo dari dua tahun sebelumnya (N-2), hidup pada skenario dua tahun tanpa cuti tahunan.
 * - "n1"      : sisa carry-over dari tahun sebelumnya (N-1), normalnya maksimal 6 hari.
 * - "current" : jatah tahun berjalan, penuh 12 hari tanpa proporsi bulan.
 */
class LeaveBalanceCalculator
{
    /**
     * Jatah dasar cuti tahunan setelah minimal 1 tahun masa kerja.
     * Selalu penuh 12 hari kerja dan tidak pernah diproporsikan menurut bulan pengangkatan.
     */
    private const ANNUAL_ENTITLEMENT = 12;

    /**
     * Batas maksimal carry-over normal per bucket N-1 (dan N-2 pada skenario dua tahun).
     */
    private const CARRY_OVER_CAP = 6;

    /**
     * Mengembalikan jatah cuti tahunan penuh (12 hari, tanpa proporsi).
     */
    public function annualEntitlement(): int
    {
        return self::ANNUAL_ENTITLEMENT;
    }

    /**
     * Menjumlahkan saldo tersedia dari seluruh bucket N-2, N-1, dan tahun berjalan.
     *
     * @param  array{n2:int, n1:int, current:int}  $buckets
     */
    public function availableTotal(array $buckets): int
    {
        return $buckets['n2'] + $buckets['n1'] + $buckets['current'];
    }

    /**
     * Mengecek apakah total saldo tersedia mencukupi jumlah hari kerja yang diminta.
     * Dipakai pada cek saat submit maupun sebelum pemotongan final.
     *
     * @param  array{n2:int, n1:int, current:int}  $buckets
     */
    public function isSufficient(array $buckets, int $requested): bool
    {
        return $this->availableTotal($buckets) >= $requested;
    }

    /**
     * Mengalokasikan pemotongan saldo dengan urutan sumber N-2 -> N-1 -> tahun berjalan.
     *
     * Tidak ada pemotongan sebagian: bila total saldo tidak cukup, alokasi dikembalikan nol
     * dan seluruh bucket tetap utuh, sehingga final approval bisa gagal tanpa merusak saldo.
     *
     * @param  array{n2:int, n1:int, current:int}  $buckets
     * @return array{
     *     success: bool,
     *     allocations: array{n2:int, n1:int, current:int},
     *     remaining: array{n2:int, n1:int, current:int}
     * }
     */
    public function allocateDeduction(array $buckets, int $requested): array
    {
        // Aturan "semua atau tidak sama sekali": tolak lebih dulu bila saldo agregat kurang.
        if (! $this->isSufficient($buckets, $requested)) {
            return [
                'success' => false,
                'allocations' => ['n2' => 0, 'n1' => 0, 'current' => 0],
                'remaining' => $buckets,
            ];
        }

        $allocations = ['n2' => 0, 'n1' => 0, 'current' => 0];
        $remaining = $buckets;
        $sisaKebutuhan = $requested;

        // Urutan sumber dikunci N-2 lalu N-1 lalu tahun berjalan sesuai aturan potong cuti tahunan.
        foreach (['n2', 'n1', 'current'] as $bucket) {
            if ($sisaKebutuhan <= 0) {
                break;
            }

            $ambil = min($remaining[$bucket], $sisaKebutuhan);
            $allocations[$bucket] = $ambil;
            $remaining[$bucket] -= $ambil;
            $sisaKebutuhan -= $ambil;
        }

        return [
            'success' => true,
            'allocations' => $allocations,
            'remaining' => $remaining,
        ];
    }

    /**
     * Mengalokasikan saldo yang dilindungi karena penangguhan dinas dari tahun berjalan ke bucket terlama.
     * Alokasi bersifat semua-atau-tidak-sama-sekali: hari non-positif atau saldo kurang ditolak tanpa mengubah bucket.
     *
     * @param  array{n2:int, n1:int, current:int}  $buckets
     * @return array{
     *     success: bool,
     *     allocations: array{n2:int, n1:int, current:int},
     *     remaining: array{n2:int, n1:int, current:int}
     * }
     */
    public function allocateDutyPostponement(array $buckets, int $days): array
    {
        if ($days <= 0 || ! $this->isSufficient($buckets, $days)) {
            return [
                'success' => false,
                'allocations' => ['n2' => 0, 'n1' => 0, 'current' => 0],
                'remaining' => $buckets,
            ];
        }

        $allocations = ['n2' => 0, 'n1' => 0, 'current' => 0];
        $remaining = $buckets;
        $sisaPerlindungan = $days;

        // Urutan reverse mencegah protected overlap dengan deduction biasa selama invariant reservasi dijaga.
        foreach (['current', 'n1', 'n2'] as $bucket) {
            if ($sisaPerlindungan <= 0) {
                break;
            }

            $ambil = min($remaining[$bucket], $sisaPerlindungan);
            $allocations[$bucket] = $ambil;
            $remaining[$bucket] -= $ambil;
            $sisaPerlindungan -= $ambil;
        }

        return [
            'success' => true,
            'allocations' => $allocations,
            'remaining' => $remaining,
        ];
    }

    /**
     * Menerapkan koreksi saldo bertanda dengan clamp agar saldo tidak pernah negatif.
     *
     * Untuk koreksi debit yang melewati nol, hanya porsi yang benar-benar terpakai yang dicatat
     * sebagai "applied"; niat asli admin disimpan terpisah di reason/metadata oleh pemanggil.
     *
     * @return array{applied:int, newAvailable:int}
     */
    public function clampCorrection(int $available, int $intended): array
    {
        // Saldo tidak boleh negatif; batas bawah dikunci di nol.
        $newAvailable = max(0, $available + $intended);
        $applied = $newAvailable - $available;

        return [
            'applied' => $applied,
            'newAvailable' => $newAvailable,
        ];
    }

    /**
     * Menghitung saldo target dengan memisahkan eligibility carry ordinary dari carry statutory.
     * Satu approval tahunan pada tahun sumber menghanguskan sisa current ordinary, tetapi tidak
     * membatalkan hak penangguhan dinas yang telah tercatat melalui jalur statutory terpisah.
     *
     * @param  int  $previousN1  Sisa bucket N-1 pada akhir tahun sumber.
     * @param  int  $previousCurrent  Sisa jatah current pada akhir tahun sumber.
     * @param  bool  $sourceYearNoApprovedAnnualLeave  True bila tidak ada Cuti Tahunan disetujui pada tahun sumber.
     * @param  bool  $twoYearsNoAnnualLeave  True bila dua tahun bersih; hanya sah jika tahun sumber juga tanpa approval.
     * @param  int  $postponedByDuty  Hak statutory penangguhan dinas yang berlaku satu tahun.
     * @return array{n2:int, n1:int, current:int, hangus:int, maxUsable:int}
     */
    public function calculateRollover(
        int $previousN1,
        int $previousCurrent,
        bool $sourceYearNoApprovedAnnualLeave,
        bool $twoYearsNoAnnualLeave,
        int $postponedByDuty = 0,
    ): array {
        // Carry ordinary hanya lahir ketika tahun sumber tidak mempunyai Cuti Tahunan disetujui.
        $n1 = $sourceYearNoApprovedAnnualLeave
            ? min($previousCurrent, self::CARRY_OVER_CAP)
            : 0;
        $hangus = $previousCurrent - $n1;

        if ($twoYearsNoAnnualLeave) {
            // N-1 lama hanya boleh menua menjadi N-2 setelah dua tahun tanpa approval tahunan.
            $n2 = min($previousN1, self::CARRY_OVER_CAP);
            $hangus += $previousN1 - $n2;
        } else {
            $n2 = 0;
            $hangus += $previousN1;
        }

        // Carry statutory tidak bergantung pada eligibility ordinary, tetapi tetap berbagi cap total 24 hari.
        $carryOverRoom = self::ANNUAL_ENTITLEMENT - $n2 - $n1;
        $postponedCarried = min($postponedByDuty, $carryOverRoom);
        $n1 += $postponedCarried;
        $hangus += $postponedByDuty - $postponedCarried;

        return [
            'n2' => $n2,
            'n1' => $n1,
            'current' => self::ANNUAL_ENTITLEMENT,
            // Hari di luar cap menjadi hangus dan tidak boleh dikonversi menjadi uang atau kompensasi.
            'hangus' => $hangus,
            // Total saldo nyata setelah rollover, bukan plafon statis kebijakan.
            'maxUsable' => $n2 + $n1 + self::ANNUAL_ENTITLEMENT,
        ];
    }
}
