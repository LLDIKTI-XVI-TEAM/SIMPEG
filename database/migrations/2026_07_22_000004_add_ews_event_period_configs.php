<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'pangkat_required_years' => '4',
            'kgb_required_years' => '2',
            // Nilai 0 mempertahankan BUP per jabatan yang telah dikonfigurasi.
            'pensiun_required_age_years' => '0',
            'pppk_contract_years' => '5',
            'satyalancana_years_1' => '10',
            'satyalancana_years_2' => '20',
            'satyalancana_years_3' => '30',
        ] as $key => $value) {
            DB::table('ews_configs')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('ews_configs')->whereIn('key', [
            'pangkat_required_years',
            'kgb_required_years',
            'pensiun_required_age_years',
            'pppk_contract_years',
            'satyalancana_years_1',
            'satyalancana_years_2',
            'satyalancana_years_3',
        ])->delete();
    }
};
