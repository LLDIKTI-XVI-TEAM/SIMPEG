<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Set the legacy default to four years without overwriting a custom value.
     */
    public function up(): void
    {
        DB::table('ews_configs')
            ->where('key', 'pppk_contract_years')
            ->where('value', '5')
            ->update([
                'value' => '4',
                'updated_at' => now(),
            ]);
    }

    /**
     * This migration must not restore a previous value that may have been customized.
     */
    public function down(): void
    {
        // This migration intentionally does not reverse customized values.
    }
};
