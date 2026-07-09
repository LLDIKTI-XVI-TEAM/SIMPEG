<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Update roles in users table
        if (Schema::hasTable('users')) {
            DB::table('users')->where('role', 'atasan_langsung')->update(['role' => 'kepala_bagian']);
        }

        // 2. Update roles in employees table
        if (Schema::hasTable('employees')) {
            DB::table('employees')->where('role', 'atasan_langsung')->update(['role' => 'kepala_bagian']);
        }

        // 3. Update roles in roles table
        if (Schema::hasTable('roles')) {
            DB::table('roles')->where('name', 'atasan_langsung')->update(['name' => 'kepala_bagian', 'description' => 'Kepala Bagian — approval stage 1 cuti, read-only data bawahan']);
        }

        if (Schema::hasTable('employees')) {
            // 4. Drop the alias column atasan_langsung_id after ensuring data is safely in kepala_bagian_id
            if (Schema::hasColumn('employees', 'atasan_langsung_id') && Schema::hasColumn('employees', 'kepala_bagian_id')) {
                DB::statement('UPDATE employees SET kepala_bagian_id = atasan_langsung_id WHERE kepala_bagian_id IS NULL AND atasan_langsung_id IS NOT NULL');

                Schema::table('employees', function (Blueprint $table) {
                    try {
                        $table->dropForeign(['atasan_langsung_id']);
                    } catch (Exception $e) {
                    }
                    $table->dropColumn('atasan_langsung_id');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add back the column
        if (Schema::hasTable('employees') && ! Schema::hasColumn('employees', 'atasan_langsung_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->foreignUuid('atasan_langsung_id')
                    ->nullable()
                    ->constrained('employees')
                    ->nullOnDelete();
            });
            DB::statement('UPDATE employees SET atasan_langsung_id = kepala_bagian_id');
        }

        // Revert roles
        if (Schema::hasTable('employees')) {
            DB::table('employees')->where('role', 'kepala_bagian')->update(['role' => 'atasan_langsung']);
        }
        if (Schema::hasTable('users')) {
            DB::table('users')->where('role', 'kepala_bagian')->update(['role' => 'atasan_langsung']);
        }
    }
};
