<?php

namespace App\Actions\HariLibur;

use App\Models\RefHariLibur;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ShowHariLiburPageAction
{
    /**
     * Pilihan jumlah baris yang diizinkan; dipakai bersama FormRequest agar UI
     * dan backend tidak bisa berbeda diam-diam.
     *
     * @var list<int>
     */
    private const PER_PAGE_OPTIONS = [10, 25, 50];

    private const DEFAULT_PER_PAGE = 10;

    /**
     * Menyiapkan data halaman kelola hari libur dari ref_hari_libur.
     *
     * Filter, pengurutan, dan paginasi dijalankan di database karena kalender
     * libur bertambah setiap tahun; mengirim seluruh baris ke browser akan
     * membuat halaman ini melambat seiring waktu.
     *
     * @param  array{tahun?: int|string|null, tipe?: string|null, search?: string|null, per_page?: int|string|null}  $filters
     * @return array{
     *     hariLibur: LengthAwarePaginator,
     *     tahunTersedia: array<int, int>,
     *     filters: array{tahun: int, tipe: string, search: string, per_page: int},
     *     perPageOptions: list<int>
     * }
     */
    public function execute(array $filters): array
    {
        $tahunTersedia = $this->tahunTersedia();
        $tahunAktif = $this->tahunAktif($filters['tahun'] ?? null, $tahunTersedia);
        $tipe = $this->tipe($filters['tipe'] ?? null);
        $search = trim((string) ($filters['search'] ?? ''));
        $perPage = $this->perPage($filters['per_page'] ?? null);

        $hariLibur = RefHariLibur::query()
            ->where('tahun', $tahunAktif)
            // Cuti bersama disimpan sebagai flag boolean, sedangkan UI memakai
            // istilah tipe sesuai formulir; pemetaannya dilakukan di sini agar
            // Blade tidak perlu tahu representasi kolomnya.
            ->when($tipe !== '', fn ($query) => $query->where('is_cuti_bersama', $tipe === 'cuti_bersama'))
            ->when($search !== '', function ($query) use ($search): void {
                $keyword = '%'.mb_strtolower($search).'%';
                $query->whereRaw('lower(nama) like ?', [$keyword]);
            })
            ->orderBy('tanggal')
            ->paginate($perPage)
            ->withQueryString();

        return [
            'hariLibur' => $hariLibur,
            'tahunTersedia' => $tahunTersedia,
            'filters' => [
                'tahun' => $tahunAktif,
                'tipe' => $tipe,
                'search' => $search,
                'per_page' => $perPage,
            ],
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ];
    }

    /**
     * Menghitung jumlah hari libur per tahun lewat satu query agregat, bukan
     * satu query per tahun, supaya tab tahun tetap murah saat data bertambah.
     *
     * @return array<int, int>
     */
    private function tahunTersedia(): array
    {
        /** @var array<int, int> $jumlahPerTahun */
        $jumlahPerTahun = RefHariLibur::query()
            ->selectRaw('tahun, count(*) as jumlah')
            ->groupBy('tahun')
            ->orderByDesc('tahun')
            ->pluck('jumlah', 'tahun')
            ->map(fn ($jumlah): int => (int) $jumlah)
            ->all();

        return $jumlahPerTahun;
    }

    /**
     * Menentukan tahun yang ditampilkan: pilihan pengguna bila valid, lalu
     * tahun berjalan bila datanya ada, lalu tahun terbaru yang tersedia.
     *
     * @param  array<int, int>  $tahunTersedia
     */
    private function tahunAktif(int|string|null $tahunDiminta, array $tahunTersedia): int
    {
        if ($tahunDiminta !== null && $tahunDiminta !== '') {
            return (int) $tahunDiminta;
        }

        $tahunBerjalan = (int) now()->format('Y');

        if (array_key_exists($tahunBerjalan, $tahunTersedia)) {
            return $tahunBerjalan;
        }

        $tahunTerbaru = array_key_first($tahunTersedia);

        return $tahunTerbaru !== null ? (int) $tahunTerbaru : $tahunBerjalan;
    }

    private function tipe(?string $tipe): string
    {
        return in_array($tipe, ['libur_nasional', 'cuti_bersama'], true) ? $tipe : '';
    }

    private function perPage(int|string|null $perPage): int
    {
        $nilai = (int) ($perPage ?? self::DEFAULT_PER_PAGE);

        return in_array($nilai, self::PER_PAGE_OPTIONS, true) ? $nilai : self::DEFAULT_PER_PAGE;
    }
}
