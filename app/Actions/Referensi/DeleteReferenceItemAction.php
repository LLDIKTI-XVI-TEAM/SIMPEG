<?php

namespace App\Actions\Referensi;

use App\Services\AuditService;
use App\Services\Referensi\ReferenceTableCatalog;
use App\Services\Referensi\ReferenceUsageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class DeleteReferenceItemAction
{
    public function __construct(
        private readonly ReferenceUsageService $usage,
    ) {}

    /**
     * Menghapus permanen item referensi yang belum pernah dipakai. Item yang
     * masih dirujuk data lain ditolak di sini karena sebagian FK pemakai
     * bersifat nullOnDelete: database tidak memblokir dan justru mengosongkan
     * kolom riwayat diam-diam bila penghapusan diteruskan.
     */
    public function execute(Model $item, Request $request): void
    {
        // Baris data sistem ditolak lebih dulu: cek pemakaian saja tidak
        // cukup karena baris bisa saja belum dirujuk data mana pun padahal
        // logika aplikasi mencarinya secara langsung berdasarkan kode.
        $protectionReason = ReferenceTableCatalog::protectionReason($item);

        if ($protectionReason !== null) {
            throw ValidationException::withMessages([
                'referensi' => sprintf('Item tidak dapat dihapus karena %s.', $protectionReason),
            ]);
        }

        $usageDetail = $this->usage->usageDetail($item);

        if ($usageDetail !== []) {
            $usageSummary = collect($usageDetail)
                ->map(fn (int $count, string $label): string => sprintf('%s (%d)', $label, $count))
                ->implode(', ');

            throw ValidationException::withMessages([
                'referensi' => sprintf(
                    'Item tidak dapat dihapus karena masih dipakai oleh: %s. Gunakan nonaktifkan sebagai gantinya.',
                    $usageSummary,
                ),
            ]);
        }

        $snapshot = $item->toArray();
        $item->delete();

        AuditService::log('DELETE', class_basename($item), $item->getKey(), $snapshot, null, $request);
        $this->forgetCaches($item::class);
    }

    private function forgetCaches(string $modelClass): void
    {
        foreach (ReferenceTableCatalog::cacheKeys($modelClass) as $cacheKey) {
            Cache::forget($cacheKey);
        }
    }
}
