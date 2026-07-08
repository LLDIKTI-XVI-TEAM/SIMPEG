<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EwsConfigSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['key' => 'ews_scheduler_time', 'value' => '07:00'],
            ['key' => 'pangkat_h90', 'value' => '90'],
            ['key' => 'pangkat_h60', 'value' => '60'],
            ['key' => 'pangkat_h30', 'value' => '30'],
            ['key' => 'kgb_h60', 'value' => '60'],
            ['key' => 'kgb_h30', 'value' => '30'],
            ['key' => 'kgb_h14', 'value' => '14'],
            ['key' => 'pensiun_y1', 'value' => '365'],
            ['key' => 'pensiun_m6', 'value' => '180'],
            ['key' => 'pensiun_m3', 'value' => '90'],
            ['key' => 'pppk_m6', 'value' => '180'],
            ['key' => 'pppk_m3', 'value' => '90'],
            ['key' => 'pppk_m1', 'value' => '30'],
            ['key' => 'satyalancana_h180', 'value' => '180'],
            ['key' => 'satyalancana_h90', 'value' => '90'],
            ['key' => 'satyalancana_h30', 'value' => '30'],
        ];

        foreach ($defaults as $config) {
            DB::table('ews_configs')->updateOrInsert(
                ['key' => $config['key']],
                ['value' => $config['value'], 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
