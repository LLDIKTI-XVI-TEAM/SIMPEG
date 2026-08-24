<?php

namespace App\Support\Documents;

class DocumentCategory
{
    private const PIMPINAN_HIDDEN = ['ktp_kk'];

    /**
     * Kategori lama tetap dikenali sebagai SK agar arsip existing tidak turun ke
     * Berkas Lainnya. Nilai ini tidak masuk LABELS supaya bukan opsi input baru.
     */
    private const LEGACY_SK_LABELS = [
        'SK' => 'Dokumen SK',
    ];

    public const LABELS = [
        'sk_pengangkatan' => 'SK Pengangkatan',
        'sk_pangkat' => 'SK Kenaikan Pangkat',
        'sk_jabatan' => 'SK Kenaikan Jabatan',
        'sk_kgb' => 'SK KGB',
        'sk_hukuman_disiplin' => 'SK Hukuman Disiplin',
        'sk_mutasi' => 'SK Mutasi',
        'sk_pensiun' => 'SK Pensiun',
        'sk_status_pegawai' => 'SK Perubahan Status Pegawai',
        'ijazah' => 'Ijazah',
        'ktp_kk' => 'KTP & KK',
        'lainnya' => 'Lainnya',
    ];

    public const ALLOWED_FILE_TYPES = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

    public static function labels(): array
    {
        return self::LABELS;
    }

    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * Kategori dokumen yang diizinkan untuk diunggah/diedit secara manual melalui modal.
     * Dokumen status (Mutasi, Pensiun) hanya dikelola via aksi perubahan status.
     */
    public static function editableLabels(): array
    {
        return array_diff_key(self::LABELS, array_flip(['sk_mutasi', 'sk_pensiun']));
    }

    public static function editableKeys(): array
    {
        return array_keys(self::editableLabels());
    }

    /**
     * Kategori yang aman ditampilkan kepada Pimpinan; KTP/KK ditahan karena memuat NIK dan nomor KK.
     *
     * @return list<string>
     */
    public static function visibleToPimpinanKeys(): array
    {
        return array_values(array_diff(self::keys(), self::PIMPINAN_HIDDEN));
    }

    /**
     * Berkas non-SK yang boleh diunggah dari modal profil pegawai.
     *
     * @return list<string>
     */
    public static function otherUploadKeys(): array
    {
        return ['ijazah', 'ktp_kk', 'lainnya'];
    }

    public static function isOtherUpload(?string $category): bool
    {
        return in_array($category, self::otherUploadKeys(), true);
    }

    public static function isProtectedSk(?string $category): bool
    {
        return in_array($category, [
            'sk_pengangkatan',
            'sk_pangkat',
            'sk_jabatan',
            'sk_kgb',
            'sk_hukuman_disiplin',
            'sk_mutasi',
            'sk_pensiun',
            'sk_status_pegawai',
        ], true) || ($category !== null && array_key_exists($category, self::LEGACY_SK_LABELS));
    }

    public static function label(?string $category): string
    {
        if ($category === null) {
            return 'Lainnya';
        }

        return self::LABELS[$category] ?? self::LEGACY_SK_LABELS[$category] ?? 'Lainnya';
    }
}
