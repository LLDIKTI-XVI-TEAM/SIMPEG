<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'is_satyalancana_eligible')) {
                $table->boolean('is_satyalancana_eligible')->default(true)->after('is_kinerja_baik');
            }

            if (! Schema::hasColumn('employees', 'satyalancana_note')) {
                $table->text('satyalancana_note')->nullable()->after('is_satyalancana_eligible');
            }
        });

        foreach ([
            'satyalancana_h180' => '180',
            'satyalancana_h90' => '90',
            'satyalancana_h30' => '30',
        ] as $key => $value) {
            DB::table('ews_configs')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('ews_configs')
            ->whereIn('key', ['satyalancana_h180', 'satyalancana_h90', 'satyalancana_h30'])
            ->delete();

        Schema::table('employees', function (Blueprint $table): void {
            if (Schema::hasColumn('employees', 'satyalancana_note')) {
                $table->dropColumn('satyalancana_note');
            }

            if (Schema::hasColumn('employees', 'is_satyalancana_eligible')) {
                $table->dropColumn('is_satyalancana_eligible');
            }
        });
    }
};
