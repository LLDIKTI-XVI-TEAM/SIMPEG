<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ews_configs', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Seed default thresholds and settings
        $defaults = [
            ['key' => 'ews_scheduler_time', 'value' => '07:00', 'created_at' => now(), 'updated_at' => now()],
            
            ['key' => 'pangkat_h90', 'value' => '90', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'pangkat_h60', 'value' => '60', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'pangkat_h30', 'value' => '30', 'created_at' => now(), 'updated_at' => now()],
            
            ['key' => 'kgb_h60', 'value' => '60', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'kgb_h30', 'value' => '30', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'kgb_h14', 'value' => '14', 'created_at' => now(), 'updated_at' => now()],
            
            ['key' => 'pensiun_y1', 'value' => '365', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'pensiun_m6', 'value' => '180', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'pensiun_m3', 'value' => '90', 'created_at' => now(), 'updated_at' => now()],
            
            ['key' => 'pppk_m6', 'value' => '180', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'pppk_m3', 'value' => '90', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'pppk_m1', 'value' => '30', 'created_at' => now(), 'updated_at' => now()],
        ];

        DB::table('ews_configs')->insert($defaults);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ews_configs');
    }
};
