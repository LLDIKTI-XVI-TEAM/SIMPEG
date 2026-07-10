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
     * Menghitung bucket saldo tahun berikutnya beserta hangus saat rollover.
     *
     * Aturan cap:
     * - Tanpa carry-over: seluruh sisa lama sudah habis, hanya jatah tahun berjalan 12 yang tersedia (maxUsable 12).
     * - Normal: N-2 hilang (0), N-1 diisi sisa tahun berjalan maksimal 6, tahun berjalan 12, batas atas 18.
     * - Dua tahun tanpa cuti tahunan: N-2 dan N-1 masing-masing maksimal 6, tahun berjalan 12, batas atas 24.
     *   Pemanggil wajib mengirim false ketika ada cuti tahunan sebagian, sehingga hasil kembali ke batas normal.
     * - Penangguhan dinas: hak cuti yang tertunda karena dinas mendesak dibawa penuh ke N-1 tahun berikutnya,
     *   tetapi total saldo tersedia tetap dibatasi maksimal 24 termasuk jatah tahun berjalan.
     *   Pemanggil wajib memisahkan sisa normal dan sisa yang benar-benar ditetapkan sebagai penangguhan dinas
     *   agar hari yang sama tidak dihitung dua kali.
     *
     * maxUsable adalah total saldo tersedia sesungguhnya setelah rollover (n2 + n1 + current),
     * bukan plafon statis; nilainya sama dengan availableTotal() untuk bucket hasil rollover.
     *
     * Kelebihan di atas cap tiap bucket dicatat sebagai hangus dan tidak pernah dikonversi menjadi uang.
     *
     * @param  int  $previousN1  Sisa bucket N-1 pada akhir tahun berjalan (carry-over dari dua tahun lalu).
     * @param  int  $previousCurrent  Sisa jatah tahun berjalan pada akhir tahun.
     * @param  bool  $twoYearsNoAnnualLeave  True hanya bila pegawai tidak mengambil cuti tahunan selama dua tahun berturut-turut.
     * @param  int  $postponedByDuty  Sisa cuti yang ditangguhkan atasan karena dinas mendesak; berlaku maksimal 1 tahun.
     * @return array{n2:int, n1:int, current:int, hangus:int, maxUsable:int}
     */
    public function calculateRollover(
        int $previousN1,
        int $previousCurrent,
        bool $twoYearsNoAnnualLeave,
        int $postponedByDuty = 0,
    ): array {
        // Sisa tahun berjalan bergeser menjadi N-1 tahun depan, dibatasi cap carry-over.
        $n1 = min($previousCurrent, self::CARRY_OVER_CAP);
        $hangus = $previousCurrent - $n1;

        if ($twoYearsNoAnnualLeave) {
            // Skenario dua tahun: bucket N-1 lama bergeser menjadi N-2, tetap dibatasi cap.
            $n2 = min($previousN1, self::CARRY_OVER_CAP);
            $hangus += $previousN1 - $n2;
        } else {
            // Skenario normal: bucket N-2 tidak dipertahankan sehingga sisa N-1 lama hangus seluruhnya.
            $n2 = 0;
            $hangus += $previousN1;
        }

        // Ruang carry-over maksimal 12 karena jatah tahun berjalan selalu 12 dari total maksimum 24.
        // Penangguhan dinas dihitung penuh hanya sampai ruang carry-over tersisa menuju total 24 hari.
        $carryOverRoom = self::ANNUAL_ENTITLEMENT - $n2 - $n1;
        $postponedCarried = min($postponedByDuty, $carryOverRoom);
        $n1 += $postponedCarried;
        $hangus += $postponedByDuty - $postponedCarried;

        return [
            'n2' => $n2,
            'n1' => $n1,
            'current' => self::ANNUAL_ENTITLEMENT,
            'hangus' => $hangus,
            // Total saldo tersedia nyata setelah rollover; batasnya 12, maksimal 18, atau maksimal 24 sesuai bucket terisi.
            'maxUsable' => $n2 + $n1 + self::ANNUAL_ENTITLEMENT,
        ];
    }
}
