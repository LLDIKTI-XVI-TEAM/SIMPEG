<?php

namespace App\Support\Documents;

class DocumentCategory
{
    private const PIMPINAN_HIDDEN = ['ktp_kk'];

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
     * SK yang hanya boleh ditambah sebagai riwayat baru. File lama tetap arsip.
     *
     * @return list<string>
     */
    public static function appendOnlySkKeys(): array
    {
        return ['sk_pangkat', 'sk_jabatan', 'sk_kgb'];
    }

    /**
     * SK yang mengganti data pengangkatan dan satu dokumen aktif.
     *
     * @return list<string>
     */
    public static function replaceSkKeys(): array
    {
        return ['sk_pengangkatan'];
    }

    /**
     * Kategori yang boleh diunggah dari section Dokumen SK di detail pegawai.
     *
     * @return list<string>
     */
    public static function tabSkKeys(): array
    {
        return [...self::appendOnlySkKeys(), ...self::replaceSkKeys()];
    }

    /**
     * Berkas non-SK yang boleh diunggah/dihapus dari section Berkas Lainnya.
     *
     * @return list<string>
     */
    public static function otherUploadKeys(): array
    {
        return ['ijazah', 'ktp_kk', 'lainnya'];
    }

    public static function isDeletable(?string $category): bool
    {
        return in_array($category, self::otherUploadKeys(), true);
    }

    public static function isProtectedSk(?string $category): bool
    {
        return in_array($category, [
            ...self::tabSkKeys(),
            'sk_hukuman_disiplin',
            'sk_mutasi',
            'sk_pensiun',
            'sk_status_pegawai',
        ], true);
    }

    public static function label(?string $category): string
    {
        return self::LABELS[$category] ?? 'Lainnya';
    }
}
