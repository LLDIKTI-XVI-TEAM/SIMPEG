<?php

namespace App\Services\Referensi;

use App\Models\RefStatusPegawai;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ReferenceUsageService
{
    public const CORE_CODE_IMMUTABLE_MESSAGE = 'Kode status inti tidak dapat diubah karena menjadi identitas kanonis sistem.';

    public const CORE_NAME_IMMUTABLE_MESSAGE = 'Nama status inti tidak dapat diubah karena menjadi identitas kanonis sistem.';

    public const GROUP_INVALID_MESSAGE = 'Kelompok status wajib berupa teks.';

    public const CLASSIFICATION_IMMUTABLE_MESSAGE = 'Kelompok status yang dipakai atau dilindungi sistem tidak dapat berpindah klasifikasi aktif/nonaktif tanpa workflow migrasi massal.';

    /**
     * Menghasilkan error mutasi yang sama bagi FormRequest dan Action. Key yang
     * tidak dikirim berarti field tidak diubah; nilai eksplisit invalid ditolak.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public function statusMutationErrors(RefStatusPegawai $status, array $data): array
    {
        $errors = [];

        if (ReferenceTableCatalog::isCoreEmployeeStatus($status)) {
            if (array_key_exists('kode', $data) && $data['kode'] !== $status->kode) {
                $errors['kode'] = self::CORE_CODE_IMMUTABLE_MESSAGE;
            }

            if (array_key_exists('nama', $data) && $data['nama'] !== $status->nama) {
                $errors['nama'] = self::CORE_NAME_IMMUTABLE_MESSAGE;
            }
        }

        if (! array_key_exists('kelompok', $data)) {
            return $errors;
        }

        if (! is_string($data['kelompok'])) {
            $errors['kelompok'] = self::GROUP_INVALID_MESSAGE;

            return $errors;
        }

        $classificationError = $this->statusClassificationChangeError($status, $data['kelompok']);
        if ($classificationError !== null) {
            $errors['kelompok'] = $classificationError;
        }

        return $errors;
    }

    /**
     * Menolak perpindahan klasifikasi aktif/nonaktif yang dapat mengubah banyak consumer sekaligus.
     * Metadata dan perubahan nama kelompok dalam klasifikasi yang sama tetap aman diperbarui.
     */
    public function statusClassificationChangeError(RefStatusPegawai $status, mixed $newGroup): ?string
    {
        if (! is_string($newGroup)
            || RefStatusPegawai::isActiveGroup($status->kelompok) === RefStatusPegawai::isActiveGroup($newGroup)) {
            return null;
        }

        if (! ReferenceTableCatalog::isCoreEmployeeStatus($status) && ! $this->isInUse($status)) {
            return null;
        }

        return self::CLASSIFICATION_IMMUTABLE_MESSAGE;
    }

    /**
     * Menghitung pemakaian item referensi per tabel pemakai. Hanya entri dengan
     * jumlah lebih dari nol yang dikembalikan agar bisa langsung dipakai sebagai
     * pesan penolakan penghapusan.
     *
     * @return array<string, int> label pemakai => jumlah baris
     */
    public function usageDetail(Model $item): array
    {
        $detail = [];

        foreach (ReferenceTableCatalog::usageReferences($item::class) as $reference) {
            $count = DB::table($reference['table'])
                ->where($reference['column'], $item->getKey())
                ->count();

            if ($count > 0) {
                $detail[$reference['label']] = $count;
            }
        }

        return $detail;
    }

    public function isInUse(Model $item): bool
    {
        return $this->usageDetail($item) !== [];
    }

    /**
     * Menghitung jumlah pemakaian per item untuk satu reference table secara
     * agregat: satu query GROUP BY per tabel pemakai, bukan satu query count
     * per baris, supaya halaman daftar data master tetap ringan.
     *
     * @return array<string, int> id item => total baris pemakai
     */
    public function usageCountMap(string $modelClass): array
    {
        $map = [];

        foreach (ReferenceTableCatalog::usageReferences($modelClass) as $reference) {
            $counts = DB::table($reference['table'])
                ->select($reference['column'], DB::raw('count(*) as total'))
                ->whereNotNull($reference['column'])
                ->groupBy($reference['column'])
                ->pluck('total', $reference['column']);

            foreach ($counts as $id => $total) {
                $map[(string) $id] = ($map[(string) $id] ?? 0) + (int) $total;
            }
        }

        return $map;
    }
}
