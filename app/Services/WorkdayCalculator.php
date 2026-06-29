<?php

namespace App\Services;

use App\Models\RefHariLibur;
use Illuminate\Support\Carbon;

/**
 * Menghitung jumlah hari kerja untuk pengajuan cuti.
 *
 * Aturan domain:
 * hari kerja = total hari kalender - Sabtu - Minggu - hari libur nasional - cuti bersama.
 * Hari libur nasional dan cuti bersama sama-sama mengurangi hari kerja dan diambil dari ref_hari_libur.
 * Catatan: di scope kalkulasi ini cuti bersama hanya di-exclude dari hitungan hari, BUKAN memotong saldo
 * tahunan (pemotongan saldo karena cuti bersama adalah domain saldo cuti, terpisah dari kalkulasi durasi).
 */
class WorkdayCalculator
{
    /**
     * Menghitung jumlah hari kerja pada rentang tanggal (inklusif kedua ujung).
     * Mengembalikan 0 secara defensif bila rentang tidak valid (mulai > selesai)
     * agar pemanggil tidak perlu menangani exception untuk input yang sudah divalidasi di layer request.
     */
    public function calculate(Carbon $start, Carbon $end): int
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();

        // Rentang terbalik dianggap kosong; validasi formal tetap dilakukan di FormRequest.
        if ($start->greaterThan($end)) {
            return 0;
        }

        // Ambil seluruh tanggal libur/cuti bersama dalam rentang sekali query untuk menghindari N+1.
        $tanggalLibur = $this->tanggalLiburDalamRentang($start, $end);

        $jumlahHariKerja = 0;

        // Iterasi tiap hari pada rentang; lewati akhir pekan dan tanggal libur.
        for ($tanggal = $start->copy(); $tanggal->lessThanOrEqualTo($end); $tanggal->addDay()) {
            if ($this->bukanHariKerja($tanggal, $tanggalLibur)) {
                continue;
            }

            $jumlahHariKerja++;
        }

        return $jumlahHariKerja;
    }

    /**
     * Menggabungkan jumlah hari kerja dan peringatan dalam sekali ambil data libur.
     * Dipakai endpoint kalkulasi agar himpunan tanggal libur hanya di-query satu kali per permintaan,
     * menghindari kueri ganda bila jumlah hari kerja dan peringatan dihitung terpisah.
     *
     * @return array{jumlah_hari_kerja: int, warnings: list<string>}
     */
    public function summarize(Carbon $start, Carbon $end): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();

        // Rentang terbalik dianggap kosong; validasi formal tetap dilakukan di FormRequest.
        if ($start->greaterThan($end)) {
            return ['jumlah_hari_kerja' => 0, 'warnings' => []];
        }

        $tanggalLibur = $this->tanggalLiburDalamRentang($start, $end);

        $jumlahHariKerja = 0;

        for ($tanggal = $start->copy(); $tanggal->lessThanOrEqualTo($end); $tanggal->addDay()) {
            if ($this->bukanHariKerja($tanggal, $tanggalLibur)) {
                continue;
            }

            $jumlahHariKerja++;
        }

        $warnings = [];

        if ($this->bukanHariKerja($start, $tanggalLibur)) {
            $warnings[] = 'Tanggal mulai jatuh pada akhir pekan atau hari libur.';
        }

        if ($this->bukanHariKerja($end, $tanggalLibur)) {
            $warnings[] = 'Tanggal selesai jatuh pada akhir pekan atau hari libur.';
        }

        return ['jumlah_hari_kerja' => $jumlahHariKerja, 'warnings' => $warnings];
    }

    /**
     * Menghasilkan daftar peringatan bila tanggal mulai atau selesai jatuh pada akhir pekan/hari libur.
     * Peringatan bersifat informatif untuk form pengajuan; tidak memblok pengajuan.
     *
     * @return list<string>
     */
    public function warnings(Carbon $start, Carbon $end): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();

        if ($start->greaterThan($end)) {
            return [];
        }

        $tanggalLibur = $this->tanggalLiburDalamRentang($start, $end);

        $warnings = [];

        if ($this->bukanHariKerja($start, $tanggalLibur)) {
            $warnings[] = 'Tanggal mulai jatuh pada akhir pekan atau hari libur.';
        }

        if ($this->bukanHariKerja($end, $tanggalLibur)) {
            $warnings[] = 'Tanggal selesai jatuh pada akhir pekan atau hari libur.';
        }

        return $warnings;
    }

    /**
     * Mengambil himpunan tanggal libur (libur nasional + cuti bersama) untuk rentang, dalam format Y-m-d.
     * Query memakai kolom integer tahun (terindeks, inklusif, dan bebas dari ambiguitas batas tanggal)
     * sehingga rentang yang melintasi tahun tetap mengikutkan libur dari seluruh tahun terkait.
     * Tanggal libur di luar rentang aktual tidak berdampak karena loop hanya mencocokkan hari dalam rentang.
     *
     * @return array<string, true>
     */
    private function tanggalLiburDalamRentang(Carbon $start, Carbon $end): array
    {
        return RefHariLibur::query()
            ->whereBetween('tahun', [(int) $start->year, (int) $end->year])
            ->pluck('tanggal')
            ->mapWithKeys(fn (Carbon $tanggal): array => [$tanggal->toDateString() => true])
            ->all();
    }

    /**
     * Menentukan sebuah tanggal bukan hari kerja: jatuh di akhir pekan ATAU terdaftar sebagai libur.
     *
     * @param  array<string, true>  $tanggalLibur
     */
    private function bukanHariKerja(Carbon $tanggal, array $tanggalLibur): bool
    {
        return $tanggal->isWeekend() || isset($tanggalLibur[$tanggal->toDateString()]);
    }
}
