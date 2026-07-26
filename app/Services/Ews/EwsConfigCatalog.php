<?php

namespace App\Services\Ews;

/**
 * Katalog kunci konfigurasi EWS: label tampilan dan nilai default.
 * Menjadi satu sumber kebenaran agar halaman konfigurasi, aksi update,
 * dan audit log tidak menduplikasi daftar kunci yang sama.
 */
final class EwsConfigCatalog
{
    /** @var array<string, string> */
    public const LABELS = [
        'ews_scheduler_time' => 'Scheduler Time',
        'pangkat_required_years' => 'Pangkat Masa Kenaikan (Tahun)',
        'pangkat_h90' => 'Pangkat Tahap 1 (Hari)',
        'pangkat_h60' => 'Pangkat Tahap 2 (Hari)',
        'pangkat_h30' => 'Pangkat Tahap 3 (Hari)',
        'kgb_required_years' => 'KGB Masa Kenaikan (Tahun)',
        'kgb_h60' => 'KGB Tahap 1 (Hari)',
        'kgb_h30' => 'KGB Tahap 2 (Hari)',
        'kgb_h14' => 'KGB Tahap 3 (Hari)',
        'pensiun_required_age_years' => 'Pensiun Usia BUP Global (Tahun)',
        'pensiun_y1' => 'Pensiun Tahap 1 (Hari)',
        'pensiun_m6' => 'Pensiun Tahap 2 (Hari)',
        'pensiun_m3' => 'Pensiun Tahap 3 (Hari)',
        'pppk_contract_years' => 'PPPK Masa Kontrak (Tahun)',
        'pppk_m6' => 'PPPK Tahap 1 (Hari)',
        'pppk_m3' => 'PPPK Tahap 2 (Hari)',
        'pppk_m1' => 'PPPK Tahap 3 (Hari)',
        'satyalancana_years_1' => 'Satyalancana Milestone 1 (Tahun)',
        'satyalancana_years_2' => 'Satyalancana Milestone 2 (Tahun)',
        'satyalancana_years_3' => 'Satyalancana Milestone 3 (Tahun)',
        'satyalancana_h180' => 'Satyalancana Tahap 1 (Hari)',
        'satyalancana_h90' => 'Satyalancana Tahap 2 (Hari)',
        'satyalancana_h30' => 'Satyalancana Tahap 3 (Hari)',
    ];

    /** @var array<string, string> */
    public const DEFAULTS = [
        'ews_scheduler_time' => '07:00',
        'pangkat_required_years' => '4',
        'pangkat_h90' => '90',
        'pangkat_h60' => '60',
        'pangkat_h30' => '30',
        'kgb_required_years' => '2',
        'kgb_h60' => '60',
        'kgb_h30' => '30',
        'kgb_h14' => '14',
        'pensiun_required_age_years' => '0',
        'pensiun_y1' => '365',
        'pensiun_m6' => '180',
        'pensiun_m3' => '90',
        'pppk_contract_years' => '4',
        'pppk_m6' => '180',
        'pppk_m3' => '90',
        'pppk_m1' => '30',
        'satyalancana_years_1' => '10',
        'satyalancana_years_2' => '20',
        'satyalancana_years_3' => '30',
        'satyalancana_h180' => '180',
        'satyalancana_h90' => '90',
        'satyalancana_h30' => '30',
    ];

    public static function labelFor(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }
}
