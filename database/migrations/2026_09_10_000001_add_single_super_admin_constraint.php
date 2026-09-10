<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_single_super_admin_unique ON users (role) WHERE role = \'super_admin\'');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS users_single_super_admin_unique ON users (role) WHERE role = 'super_admin'");

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            if (! Schema::hasColumn('users', 'single_super_admin_guard')) {
                DB::statement("ALTER TABLE users ADD COLUMN single_super_admin_guard VARCHAR(20) GENERATED ALWAYS AS (CASE WHEN role = 'super_admin' THEN 'super_admin' ELSE NULL END) STORED");
            }
            // Check if index exists to avoid duplicate.
            $indexExists = collect(DB::select("SHOW INDEX FROM users WHERE Key_name = 'users_single_super_admin_unique'"))->isNotEmpty();
            if (! $indexExists) {
                DB::statement('ALTER TABLE users ADD UNIQUE INDEX users_single_super_admin_unique (single_super_admin_guard)');
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS users_single_super_admin_unique');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS users_single_super_admin_unique');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $indexExists = collect(DB::select("SHOW INDEX FROM users WHERE Key_name = 'users_single_super_admin_unique'"))->isNotEmpty();
            if ($indexExists) {
                DB::statement('ALTER TABLE users DROP INDEX users_single_super_admin_unique');
            }
            if (Schema::hasColumn('users', 'single_super_admin_guard')) {
                DB::statement('ALTER TABLE users DROP COLUMN single_super_admin_guard');
            }
        }
    }
};
