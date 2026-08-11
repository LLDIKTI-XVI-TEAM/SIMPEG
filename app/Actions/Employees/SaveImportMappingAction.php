<?php

namespace App\Actions\Employees;

use App\Models\User;
use App\Support\EmployeeImport\ImportColumnMapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class SaveImportMappingAction
{
    /**
     * Menyimpan pemetaan kolom pilihan admin sebagai state batch import.
     *
     * Setelah disimpan, mapping manual menjadi sumber kebenaran pada tahap validasi
     * dan heuristik kolom bergeser tidak boleh lagi mengubah tafsir nilai baris.
     * Key mapping diverifikasi terhadap header sumber batch agar client tidak bisa
     * menyuntikkan kolom di luar isi file.
     *
     * @param  array<string, string>  $mapping
     *
     * @throws ValidationException
     */
    public function execute(string $batchId, array $mapping, ?User $user): array
    {
        $batch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);

        if ($batch === null) {
            abort(404, 'Batch import tidak ditemukan atau sudah kedaluwarsa. Silakan upload ulang.');
        }

        if ($batch['user_id'] !== null && ($user === null || $batch['user_id'] !== $user->id)) {
            abort(403, 'Anda tidak memiliki akses ke batch import ini.');
        }

        $unknownSources = array_diff(array_keys($mapping), $batch['headers']);

        if ($unknownSources !== []) {
            throw ValidationException::withMessages([
                'mapping' => ['Kolom sumber tidak dikenal pada batch ini: '.implode(', ', $unknownSources).'.'],
            ]);
        }

        // Kolom source yang reserved selalu dipaksa ke tidak_dipakai — ini adalah domain
        // invariant, bukan pilihan UI. Normalisasi dilakukan sebelum merge agar pilihan
        // admin sebelumnya (bila ada) juga tidak dapat mewariskan mapping terlarang.
        $mapping = ImportColumnMapping::normalizeReservedSources($mapping);

        // Header yang tidak dikirim client dipertahankan pada mapping sebelumnya agar
        // penyimpanan parsial (admin baru mengubah sebagian dropdown) tetap aman.
        $merged = array_merge($batch['mapping'] ?? [], $mapping);

        $duplicates = ImportColumnMapping::duplicateTargets($merged);

        if ($duplicates !== []) {
            throw ValidationException::withMessages([
                'mapping' => ['Satu field tujuan hanya boleh dipetakan dari satu kolom sumber: '.implode(', ', $duplicates).'.'],
            ]);
        }

        $mappingChanged = ($batch['mapping'] ?? []) !== $merged;

        // Hanya perubahan pilihan admin yang mengubah sumber mapping menjadi manual.
        // UI tetap menyimpan mapping sebelum validasi meski dropdown tidak disentuh.
        if ($mappingChanged) {
            $batch['validation'] = null;
            $batch['mapping_source'] = 'manual';
        }

        $batch['mapping'] = $merged;
        $batch['warnings'] = ImportColumnMapping::warnings($merged);
        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));

        return [
            'batch_id' => $batchId,
            'mapping' => $merged,
            'warnings' => $batch['warnings'],
        ];
    }
}
