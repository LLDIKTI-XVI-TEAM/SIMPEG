<?php

namespace App\Support\EmployeeImport;

use App\Support\EmployeeValidationRules;

/**
 * Kontrak pemetaan kolom import: kolom sumber file -> header kanonis template.
 *
 * Mapping adalah state batch import (disimpan server-side) dan menjadi sumber tunggal
 * yang dipakai ulang oleh preview, validasi, dan eksekusi. Kolom sumber yang dipetakan
 * ke penanda IGNORE tidak dibaca nilainya sama sekali. Role sengaja tidak termasuk
 * target: penetapan role aplikasi berjalan lewat Kelola Akses User, bukan import.
 */
class ImportColumnMapping
{
    /** Penanda kolom sumber yang nilainya tidak dipakai. */
    public const IGNORE = 'tidak_dipakai';

    /** Header sumber yang dikelola oleh domain lain dan tidak boleh masuk import pegawai. */
    private const RESERVED_SOURCES = ['Role'];

    /**
     * Header kanonis yang menjadi target pemetaan valid, yaitu header yang
     * benar-benar dibaca mapper menjadi field model.
     *
     * @return list<string>
     */
    public static function targets(): array
    {
        return EmployeeRowMapper::targets();
    }

    /**
     * Target wajib: header kanonis yang field tujuannya required pada aturan import.
     * Diturunkan dari aturan agar daftar ini tidak drift dari validasi baris.
     *
     * @return list<string>
     */
    public static function requiredTargets(): array
    {
        $rules = EmployeeValidationRules::import();
        $required = [];

        foreach (EmployeeRowMapper::targets() as $header) {
            $field = EmployeeRowMapper::fieldFor($header);
            $fieldRules = $field !== null ? ($rules[$field] ?? []) : [];

            if (in_array('required', $fieldRules, true)) {
                $required[] = $header;
            }
        }

        return $required;
    }

    /**
     * Mapping awal dari nama header file setelah normalisasi spasi dan huruf
     * besar-kecil. 'Person Formula' adalah alias legacy 'Person': hanya dipakai
     * bila kolom 'Person' tidak ada di file, agar tidak menimbulkan target ganda.
     *
     * @param  list<string>  $headers
     * @return array<string, string> peta header sumber -> target kanonis atau IGNORE
     */
    public static function autoMap(array $headers): array
    {
        $normalizedTargets = [];
        foreach (self::targets() as $target) {
            $normalizedTargets[self::normalize($target)] = $target;
        }

        $mapping = [];
        $hasPerson = false;
        foreach ($headers as $header) {
            if (self::normalize($header) === self::normalize('Person')) {
                $hasPerson = true;
            }
        }

        foreach ($headers as $header) {
            $normalized = self::normalize($header);
            $target = $normalizedTargets[$normalized] ?? null;

            if ($target === null && $normalized === self::normalize('Person Formula') && ! $hasPerson) {
                $target = 'Person';
            }

            $mapping[$header] = $target ?? self::IGNORE;
        }

        return $mapping;
    }

    /**
     * Target wajib yang belum dipetakan ke kolom sumber mana pun.
     *
     * @param  array<string, string>  $mapping
     * @return list<string>
     */
    public static function missingRequired(array $mapping): array
    {
        $used = array_filter(array_values($mapping), fn (string $target): bool => $target !== self::IGNORE);

        return array_values(array_diff(self::requiredTargets(), $used));
    }

    /**
     * Target kanonis yang dipilih lebih dari satu kolom sumber.
     *
     * @param  array<string, string>  $mapping
     * @return list<string>
     */
    public static function duplicateTargets(array $mapping): array
    {
        $counts = array_count_values(array_filter(
            array_values($mapping),
            fn (string $target): bool => $target !== self::IGNORE,
        ));

        return array_values(array_keys(array_filter($counts, fn (int $count): bool => $count > 1)));
    }

    /**
     * Source reserved yang dipetakan ke target aktif.
     *
     * Normalisasi disamakan dengan auto-map agar variasi spasi dan kapitalisasi tidak
     * dapat melewati batas domain, sementara pilihan tidak dipakai tetap diizinkan.
     *
     * @param  array<string, string>  $mapping
     * @return list<string>
     */
    public static function reservedSourcesMappedToTargets(array $mapping): array
    {
        $invalidSources = [];

        foreach ($mapping as $sourceHeader => $target) {
            if ($target !== self::IGNORE && self::isReservedSource($sourceHeader)) {
                $invalidSources[] = $sourceHeader;
            }
        }

        return $invalidSources;
    }

    /**
     * Peringatan non-blocking: kolom sumber yang tidak terpakai (nilainya tidak
     * disimpan) dan header wajib yang belum ditemukan pada file.
     *
     * @param  array<string, string>  $mapping
     * @return array{unmatched_columns: list<string>, missing_required: list<string>}
     */
    public static function warnings(array $mapping): array
    {
        return [
            'unmatched_columns' => array_values(array_keys(array_filter(
                $mapping,
                fn (string $target): bool => $target === self::IGNORE,
            ))),
            'missing_required' => self::missingRequired($mapping),
        ];
    }

    /**
     * Terapkan mapping ke satu baris: key sumber diganti menjadi header kanonis,
     * kolom bertanda IGNORE dibuang agar nilainya tidak pernah masuk pipeline.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $mapping
     * @return array<string, mixed>
     */
    public static function apply(array $data, array $mapping): array
    {
        $mapped = [];

        foreach ($data as $sourceHeader => $value) {
            // Pertahanan terakhir: source reserved tidak boleh masuk pipeline walaupun
            // mapping berbahaya melewati endpoint atau state batch rusak.
            if (self::isReservedSource($sourceHeader)) {
                continue;
            }

            $target = $mapping[$sourceHeader] ?? null;

            if ($target === null) {
                // Header tanpa entri mapping diperlakukan seperti kolom tidak dikenal.
                continue;
            }

            if ($target === self::IGNORE) {
                continue;
            }

            $mapped[$target] = $value;
        }

        return $mapped;
    }

    private static function isReservedSource(string $sourceHeader): bool
    {
        foreach (self::RESERVED_SOURCES as $reservedSource) {
            if (self::normalize($sourceHeader) === self::normalize($reservedSource)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? $value;

        return mb_strtolower($value);
    }
}
