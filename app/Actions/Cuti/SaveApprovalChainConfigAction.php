<?php

namespace App\Actions\Cuti;

use App\Models\ApprovalConfig;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Menyimpan konfigurasi rantai approval cuti (approver stage 2 dan stage 3).
 * Action ini menjadi satu titik orkestrasi: menyimpan nilai konfigurasi dan mencatat audit
 * untuk setiap kunci yang benar-benar berubah, sehingga riwayat perubahan dapat ditelusuri.
 */
class SaveApprovalChainConfigAction
{
    /**
     * Daftar kunci approval_configs yang dikelola oleh form ini.
     * Dipusatkan di sini agar penyimpanan dan audit selalu memakai kunci yang sama.
     */
    private const STAGE2_KEY = 'stage2_approver_id';

    private const STAGE3_KEY = 'stage3_approver_id';

    /**
     * Menyimpan perubahan konfigurasi approver dan mencatat audit per perubahan.
     *
     * @param  array<string, mixed>  $data  Data tervalidasi: stage2_approver_id, stage3_approver_id, reason
     * @return array<string, bool> Penanda kunci mana yang berubah (untuk pesan/umpan balik)
     */
    public function execute(array $data, Request $request): array
    {
        $reason = (string) $data['reason'];

        // Nilai lama dibaca sebelum penyimpanan agar bisa dibandingkan dan dipakai sebagai old_values audit.
        $oldStage2 = ApprovalConfig::getVal(self::STAGE2_KEY);
        $oldStage3 = ApprovalConfig::getVal(self::STAGE3_KEY);

        $newStage2 = (string) $data['stage2_approver_id'];
        $newStage3 = (string) $data['stage3_approver_id'];

        // Penyimpanan kedua kunci dibungkus transaksi agar konfigurasi tidak tersimpan separuh
        // bila salah satu operasi gagal di tengah jalan.
        $changed = DB::transaction(function () use ($oldStage2, $oldStage3, $newStage2, $newStage3, $reason, $request) {
            $changed = [
                self::STAGE2_KEY => false,
                self::STAGE3_KEY => false,
            ];

            // Hanya simpan dan audit bila nilai benar-benar berbeda, supaya tidak ada entri audit kosong
            // saat super_admin menekan simpan tanpa mengubah approver.
            if ((string) $oldStage2 !== $newStage2) {
                ApprovalConfig::setVal(self::STAGE2_KEY, $newStage2);
                $this->audit(self::STAGE2_KEY, $oldStage2, $newStage2, $reason, $request);
                $changed[self::STAGE2_KEY] = true;
            }

            if ((string) $oldStage3 !== $newStage3) {
                ApprovalConfig::setVal(self::STAGE3_KEY, $newStage3);
                $this->audit(self::STAGE3_KEY, $oldStage3, $newStage3, $reason, $request);
                $changed[self::STAGE3_KEY] = true;
            }

            return $changed;
        });

        return $changed;
    }

    /**
     * Mencatat satu perubahan konfigurasi approver ke audit log.
     * Nama approver lama dan baru disertakan agar audit mudah dibaca tanpa harus me-resolve id pengguna lagi.
     */
    private function audit(string $key, ?string $oldId, string $newId, string $reason, Request $request): void
    {
        $oldName = $oldId !== null ? User::find($oldId)?->name : null;
        $newName = User::find($newId)?->name;

        // Kunci konfigurasi disimpan di dalam payload, bukan pada auditable_id, karena kolom auditable_id
        // bertipe UUID sedangkan kunci konfigurasi (mis. "stage2_approver_id") bukan UUID.
        // Menyimpan kunci ke auditable_id akan gagal di PostgreSQL meskipun lolos di SQLite.
        AuditService::log(
            'UPDATE',
            'ApprovalConfig',
            null,
            ['key' => $key, 'approver_id' => $oldId, 'approver_name' => $oldName],
            ['key' => $key, 'approver_id' => $newId, 'approver_name' => $newName, 'reason' => $reason],
            $request,
        );
    }
}
