<?php

namespace App\Actions\Referensi;

use App\Services\AuditService;
use App\Services\Referensi\ReferenceTableCatalog;
use App\Services\Referensi\ReferenceUsageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteReferenceItemAction
{
    public function __construct(
        private readonly ReferenceUsageService $usage,
    ) {}

    /**
     * Menghapus permanen item referensi yang belum pernah dipakai. Item yang
     * masih dirujuk ditolak lebih dulu untuk memberi pesan yang dapat
     * ditindaklanjuti admin; lapisan ini tetap diperlukan bagi FK legacy yang
     * nullOnDelete maupun FK RESTRICT yang menjadi backstop jalur di luar Action.
     */
    public function execute(Model $item, Request $request): void
    {
        DB::transaction(function () use ($item, $request): void {
            // Lock baris katalog sebelum memeriksa pemakaian. FK pemakai yang
            // menyisip bersamaan membutuhkan key-share lock pada baris ini, jadi
            // urutan ini menyerialkan pembuatan riwayat dengan penghapusan dan
            // menyerialkan pembuatan riwayat dengan penghapusan dan memastikan
            // pemeriksaan pemakaian melihat relasi yang sudah committed.
            $lockedItem = $item->newQuery()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Baris data sistem ditolak lebih dulu: cek pemakaian saja tidak
            // cukup karena baris bisa saja belum dirujuk data mana pun padahal
            // logika aplikasi mencarinya secara langsung berdasarkan kode.
            $protectionReason = ReferenceTableCatalog::protectionReason($lockedItem);

            if ($protectionReason !== null) {
                throw ValidationException::withMessages([
                    'referensi' => sprintf('Item tidak dapat dihapus karena %s.', $protectionReason),
                ]);
            }

            $usageDetail = $this->usage->usageDetail($lockedItem);

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

            $snapshot = $lockedItem->toArray();
            $lockedItem->delete();

            AuditService::logOrFail('DELETE', class_basename($lockedItem), $lockedItem->getKey(), $snapshot, null, $request);
            ReferenceTableCatalog::forgetCachesAfterCommit($lockedItem::class);
        });
    }
}
