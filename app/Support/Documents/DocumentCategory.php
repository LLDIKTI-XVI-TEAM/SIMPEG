<?php

namespace App\Support\Documents;

class DocumentCategory
{
    public const LABELS = [
        'sk_pengangkatan' => 'SK Pengangkatan',
        'sk_pangkat' => 'SK Kenaikan Pangkat',
        'sk_jabatan' => 'SK Kenaikan Jabatan',
        'sk_kgb' => 'SK KGB',
        'sk_hukuman_disiplin' => 'SK Hukuman Disiplin',
        'sk_mutasi' => 'SK Mutasi',
        'sk_pensiun' => 'SK Pensiun',
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

    public static function label(?string $category): string
    {
        return self::LABELS[$category] ?? 'Lainnya';
    }
}
