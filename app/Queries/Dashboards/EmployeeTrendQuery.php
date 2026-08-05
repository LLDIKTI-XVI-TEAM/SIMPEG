<?php

namespace App\Queries\Dashboards;

use App\Models\Employee;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Menyediakan titik bulanan widget tren pegawai untuk dashboard Admin dan Pimpinan.
 *
 * Dipusatkan di satu kelas karena kontrak payload dashboard mewajibkan kedua surface
 * memakai metode perhitungan yang sama; sebelumnya logika ini diduplikasi di dua Action
 * sehingga perubahan basis perhitungan berisiko diterapkan tidak serempak.
 *
 * Basis perhitungan memakai data domain, bukan metadata pencatatan. Memakai created_at
 * membuat impor massal terlihat seperti rekrutmen serentak: seluruh pegawai seolah masuk
 * pada bulan berkas diunggah, sehingga kurva melonjak sekali lalu datar.
 */
class EmployeeTrendQuery
{
    private const JUMLAH_BULAN = 12;

    /** Nilai status yang berarti pegawai masih dihitung sebagai pegawai aktif. */
    private const STATUS_AKTIF = 'Aktif';

    /**
     * Menghitung jumlah pegawai aktif pada akhir setiap bulan selama satu tahun terakhir.
     *
     * @return list<array{label: string, jumlah: int}>
     */
    public function monthlyActiveCounts(?Carbon $sekarang = null): array
    {
        $sekarang = $sekarang?->copy() ?? now();
        $awalRentang = $sekarang->copy()->subMonths(self::JUMLAH_BULAN - 1)->startOfMonth();

        $agregat = DB::query()->fromSub($this->batasMasaAktif($awalRentang), 'batas');
        $akhirBulanPerTitik = [];

        foreach (range(self::JUMLAH_BULAN - 1, 0) as $urutan => $offset) {
            $akhirBulan = $sekarang->copy()->subMonths($offset)->endOfMonth()->toDateString();
            $akhirBulanPerTitik[$urutan] = $sekarang->copy()->subMonths($offset);

            // Satu ekspresi agregat per bulan supaya seluruh titik selesai dalam satu query,
            // menggantikan dua belas query COUNT terpisah yang dipakai sebelumnya.
            $agregat->selectRaw(
                "sum(case when (mulai is null or mulai <= ?) and (keluar is null or keluar > ?) then 1 else 0 end) as titik_{$urutan}",
                [$akhirBulan, $akhirBulan],
            );
        }

        $hasil = $agregat->first();
        $titik = [];

        foreach ($akhirBulanPerTitik as $urutan => $bulan) {
            $titik[] = [
                'label' => $bulan->translatedFormat('M Y'),
                'jumlah' => (int) ($hasil?->{"titik_{$urutan}"} ?? 0),
            ];
        }

        return $titik;
    }

    /**
     * Menyusun satu baris per pegawai berisi tanggal mulai dan tanggal berhenti dihitung.
     *
     * Aturan yang dijaga di sini:
     * - mulai diambil dari TMT pengangkatan paling awal; pegawai tanpa riwayat pengangkatan
     *   dianggap sudah aktif sebelum rentang karena mayoritas pegawai adalah pegawai lama
     *   dan mengecualikan mereka membuat grafik jauh di bawah jumlah pegawai sebenarnya.
     * - keluar mengikuti riwayat status terakhir bila status itu bukan aktif, sehingga pegawai
     *   yang pernah nonaktif lalu kembali bertugas tidak dianggap keluar permanen.
     * - di antara riwayat status dan tanggal pensiun dipilih tanggal yang paling awal. Migrasi
     *   riwayat status mengisi tanggal efektif dengan waktu migrasi ketika pegawai tidak punya
     *   tanggal status, sehingga pegawai yang sudah lama pensiun bisa memiliki riwayat bertanggal
     *   jauh lebih baru daripada tanggal pensiunnya. Mempercayai riwayat itu akan membuat grafik
     *   mengklaim pegawai tersebut masih aktif sampai hari migrasi.
     * - pegawai yang statusnya bukan aktif tetapi tidak punya satu pun jejak tanggal diberi
     *   tanggal keluar sebelum rentang, agar sistem tidak mengklaim seseorang aktif di masa
     *   lalu tanpa bukti tanggal apa pun.
     *
     * Riwayat status diasumsikan hanya punya satu baris is_latest per pegawai, mengikuti pola
     * append-only yang dipakai seluruh tabel riwayat kepegawaian.
     */
    private function batasMasaAktif(Carbon $awalRentang): BuilderContract
    {
        $sebelumRentang = $awalRentang->copy()->subDay()->toDateString();

        $pengangkatanTerawal = DB::table('appointments')
            ->select('employee_id', DB::raw('min(tmt_pengangkatan) as tmt_mulai'))
            ->whereNotNull('tmt_pengangkatan')
            ->groupBy('employee_id');

        $statusTerakhir = DB::table('employee_status_histories')
            ->select('employee_id', 'status_nama', 'tanggal_efektif')
            ->where('is_latest', true);

        return Employee::query()
            ->leftJoinSub($pengangkatanTerawal, 'pengangkatan', 'pengangkatan.employee_id', '=', 'employees.id')
            ->leftJoinSub($statusTerakhir, 'status_terakhir', 'status_terakhir.employee_id', '=', 'employees.id')
            ->selectRaw('pengangkatan.tmt_mulai as mulai')
            ->selectRaw(
                'case '
                .'when status_terakhir.status_nama is not null and status_terakhir.status_nama <> ? '
                .'then '.$this->tanggalTerawal('status_terakhir.tanggal_efektif', 'employees.tanggal_pensiun').' '
                .'when employees.status_aktif <> ? '
                .'then case '
                .'when employees.status_tanggal is null and employees.tanggal_pensiun is null then ? '
                .'else '.$this->tanggalTerawal('employees.status_tanggal', 'employees.tanggal_pensiun').' end '
                .'else employees.tanggal_pensiun end as keluar',
                [self::STATUS_AKTIF, self::STATUS_AKTIF, $sebelumRentang],
            )
            ->toBase();
    }

    /**
     * Menghasilkan ekspresi tanggal paling awal di antara dua kolom yang boleh kosong.
     *
     * Perbandingan ditulis manual alih-alih memakai least() karena fungsi itu tidak tersedia
     * di SQLite yang masih dipakai suite pengujian standar, sedangkan min() dua argumen
     * sebaliknya tidak tersedia di PostgreSQL. Bentuk case juga menjaga semantik kosong:
     * kolom kosong dilewati, dan hasil tetap kosong hanya bila kedua kolom kosong.
     */
    private function tanggalTerawal(string $kiri, string $kanan): string
    {
        return "case when {$kiri} is not null and ({$kanan} is null or {$kiri} < {$kanan}) "
            ."then {$kiri} else {$kanan} end";
    }
}
