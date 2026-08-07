<?php

namespace App\Support\Cuti;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tafsir tunggal nilai filter periode cuti, dipakai daftar pengajuan maupun rekap.
 *
 * Format yang dikenal dibatasi pada tahun, tahun-bulan, atau nama bulan Indonesia dan tahun.
 * Nilai lain sengaja tidak menghasilkan filter agar pengguna melihat data apa adanya
 * dan tidak menerima hasil kosong yang menyesatkan.
 */
final class CutiPeriodFilter
{
    /**
     * @var array<string, int>
     */
    private const MONTH_NAMES = [
        'Januari' => 1,
        'Februari' => 2,
        'Maret' => 3,
        'April' => 4,
        'Mei' => 5,
        'Juni' => 6,
        'Juli' => 7,
        'Agustus' => 8,
        'September' => 9,
        'Oktober' => 10,
        'November' => 11,
        'Desember' => 12,
    ];

    private function __construct(
        public readonly int $year,
        public readonly ?int $month,
    ) {}

    /**
     * Mengembalikan null bila nilai tidak dikenali, sehingga pemanggil dapat melewati filter tanpa cabang tambahan.
     */
    public static function parse(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/^(\d{4})$/', $value, $matches) === 1) {
            return self::fromParts((int) $matches[1], null);
        }

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $matches) === 1) {
            return self::fromParts((int) $matches[1], (int) $matches[2]);
        }

        if (preg_match('/^([A-Za-z]+) (\d{4})$/', $value, $matches) !== 1
            || ! array_key_exists($matches[1], self::MONTH_NAMES)) {
            return null;
        }

        return self::fromParts((int) $matches[2], self::MONTH_NAMES[$matches[1]]);
    }

    /**
     * Menolak tahun di luar kalender yang didukung basis data. PostgreSQL tidak mengenal tahun 0,
     * sehingga rentang tanggal yang dibentuk darinya akan ditolak dan menggagalkan permintaan.
     * Nilai seperti itu diperlakukan sama dengan format yang tidak dikenal, yaitu tidak memfilter.
     */
    private static function fromParts(int $year, ?int $month): ?self
    {
        if ($year < 1) {
            return null;
        }

        return new self($year, $month);
    }

    /**
     * Awal rentang, inklusif.
     */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse(sprintf('%04d-%02d-01', $this->year, $this->month ?? 1))->startOfDay();
    }

    /**
     * Batas rentang, eksklusif. Bentuk setengah terbuka dipilih agar tidak perlu menghitung akhir bulan.
     */
    public function endsBeforeAt(): CarbonImmutable
    {
        return $this->month === null
            ? $this->startsAt()->addYear()
            : $this->startsAt()->addMonth();
    }

    /**
     * Memfilter kolom tanggal sebagai rentang, bukan lewat fungsi tanggal, agar indeks kolom tetap dapat dipakai.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    public function applyToDateColumn(Builder $query, string $column): void
    {
        $query->where($column, '>=', $this->startsAt()->toDateString())
            ->where($column, '<', $this->endsBeforeAt()->toDateString());
    }

    /**
     * Label ringkas cakupan periode; dipakai pada nama berkas unduhan rekap.
     */
    public function label(): string
    {
        return $this->month === null
            ? (string) $this->year
            : sprintf('%04d-%02d', $this->year, $this->month);
    }
}
