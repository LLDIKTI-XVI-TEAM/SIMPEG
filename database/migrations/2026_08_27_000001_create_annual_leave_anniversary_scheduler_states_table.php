<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annual_leave_anniversary_scheduler_states', function (Blueprint $table): void {
            $table->string('scheduler_key')->primary();
            // Cursor tidak memakai FK agar penghapusan pegawai tidak mengubah checkpoint historis scheduler.
            $table->uuid('cursor_employee_id')->nullable();
            $table->timestampTz('cursor_advanced_at')->nullable();
            $table->timestampsTz();
        });

        DB::table('annual_leave_anniversary_scheduler_states')->insert([
            'scheduler_key' => 'annual_leave_entitlement',
            'cursor_employee_id' => null,
            'cursor_advanced_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('annual_leave_anniversary_scheduler_states');
    }
};
