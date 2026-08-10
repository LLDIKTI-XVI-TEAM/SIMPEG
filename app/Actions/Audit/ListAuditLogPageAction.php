<?php

namespace App\Actions\Audit;

use App\Models\AuditLog;
use App\Support\Audit\AuditFilterValue;
use App\Support\Audit\AuditLogViewPayload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Menyiapkan halaman daftar audit log.
 *
 * Penyaringan, pencarian, pengurutan, dan pemotongan halaman dijalankan di basis data karena
 * volume audit terus bertambah tanpa mekanisme penghapusan, sehingga memuat seluruh tabel akan
 * memperlambat halaman seiring pemakaian.
 */
class ListAuditLogPageAction
{
    /**
     * Batas jumlah baris per halaman. Nilai bawaan mengikuti kriteria halaman audit, sedangkan
     * batas atas menjaga permintaan buatan tangan tidak menarik seluruh tabel.
     */
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * Pilihan pada penyaring diambil dari basis data dan dibatasi jumlahnya supaya daftar
     * pilihan tidak tumbuh tanpa batas ketika jumlah operator bertambah.
     */
    private const MAX_FILTER_OPTIONS = 200;

    /**
     * Kolom yang boleh dipakai mengurutkan, dipetakan dari nama kolom pada tampilan ke kolom
     * basis data. Daftar tertutup ini mencegah nama kolom sembarang masuk ke klausa order.
     */
    private const SORTABLE_COLUMNS = [
        'timestamp' => 'created_at',
        'operator' => 'user_name',
        'event' => 'event',
        'modul' => 'auditable_type',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return array{auditLogs: LengthAwarePaginator<int, array<string, mixed>>, operatorOptions: list<string>, modulOptions: list<string>, eventOptions: list<string>, activeFilters: array<string, string>}
     */
    public function execute(array $filters): array
    {
        $activeFilters = self::normalizeFilters($filters);

        $paginator = $this->baseQuery($activeFilters)
            ->orderBy(
                self::SORTABLE_COLUMNS[$activeFilters['sort']],
                $activeFilters['direction'],
            )
            ->paginate(self::perPage($filters))
            ->withQueryString()
            ->through(fn (AuditLog $log): array => AuditLogViewPayload::forView($log));

        return [
            'auditLogs' => $paginator,
            'operatorOptions' => $this->distinctValues('user_name'),
            'modulOptions' => $this->distinctValues('auditable_type'),
            'eventOptions' => $this->distinctValues('event'),
            'activeFilters' => $activeFilters,
        ];
    }

    /**
     * @param  array<string, string>  $filters
     * @return Builder<AuditLog>
     */
    private function baseQuery(array $filters): Builder
    {
        $query = AuditLog::query();

        if ($filters['q'] !== '') {
            $query->where(function (Builder $pencarian) use ($filters): void {
                // Nama operator dicari sebagian. Pengenal record dicocokkan utuh karena bentuknya
                // dapat berupa UUID pada data transaksional, atau kunci konfigurasi yang tersimpan
                // pada payload untuk kejadian yang tidak menunjuk satu baris tabel.
                $pencarian->whereRaw('lower(user_name) like ?', ['%'.Str::lower($filters['q']).'%']);

                if (Str::isUuid($filters['q'])) {
                    $pencarian->orWhere('auditable_id', $filters['q']);

                    return;
                }

                $pencarian
                    ->orWhere('new_values->key', $filters['q'])
                    ->orWhere('old_values->key', $filters['q']);
            });
        }

        if ($filters['event'] !== '') {
            $query->where('event', $filters['event']);
        }

        if ($filters['operator'] !== '') {
            $query->where('user_name', $filters['operator']);
        }

        if ($filters['modul'] !== '') {
            $query->where('auditable_type', $filters['modul']);
        }

        if ($filters['from'] !== '') {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }

        if ($filters['to'] !== '') {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }

        return $query;
    }

    /**
     * Pilihan penyaring diambil dari nilai yang benar-benar ada pada audit, supaya daftarnya
     * tidak menawarkan kombinasi yang pasti kosong.
     *
     * @return list<string>
     */
    private function distinctValues(string $column): array
    {
        return AuditLog::query()
            ->whereNotNull($column)
            ->distinct()
            ->reorder($column)
            ->limit(self::MAX_FILTER_OPTIONS)
            ->pluck($column)
            ->map(fn (mixed $value): string => (string) $value)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private static function normalizeFilters(array $filters): array
    {
        $ambil = static fn (mixed $value): string => AuditFilterValue::text($value);

        $sort = $ambil($filters['sort'] ?? null);
        $direction = Str::lower($ambil($filters['direction'] ?? null));

        return [
            'q' => $ambil($filters['q'] ?? null),
            'event' => $ambil($filters['event'] ?? null),
            'operator' => $ambil($filters['operator'] ?? null),
            'modul' => $ambil($filters['modul'] ?? null),
            'from' => AuditFilterValue::date($filters['from'] ?? null),
            'to' => AuditFilterValue::date($filters['to'] ?? null),
            'sort' => array_key_exists($sort, self::SORTABLE_COLUMNS) ? $sort : 'timestamp',
            'direction' => $direction === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private static function perPage(array $filters): int
    {
        $diminta = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);

        if ($diminta < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($diminta, self::MAX_PER_PAGE);
    }
}
