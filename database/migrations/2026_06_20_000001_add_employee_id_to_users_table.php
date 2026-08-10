<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUuid('employee_id')->nullable()->unique()->after('keycloak_username')->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // SQLite requires table rebuild to properly drop foreign key columns with constraints
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rebuildTableForSQLite();

            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('employee_id');
        });
    }

    private function rebuildTableForSQLite(): void
    {
        // Disable foreign keys temporarily
        DB::statement('PRAGMA foreign_keys = OFF');

        // Get all users data
        $users = DB::table('users')->get();

        // Drop and recreate table without employee_id
        DB::statement('DROP TABLE IF EXISTS users');

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable(); // Nullable for SSO users
            $table->string('role')->nullable();
            $table->string('keycloak_id')->nullable()->unique();
            $table->string('keycloak_username')->nullable()->unique();
            // employee_id NOT added back (this is what we're rolling back)
            $table->rememberToken();
            $table->timestamps();
        });

        // Restore data
        foreach ($users as $user) {
            DB::table('users')->insert([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'password' => $user->password,
                'role' => $user->role,
                'keycloak_id' => $user->keycloak_id,
                'keycloak_username' => $user->keycloak_username,
                // employee_id omitted - this column is being removed
                'remember_token' => $user->remember_token,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ]);
        }

        // Re-enable foreign keys
        DB::statement('PRAGMA foreign_keys = ON');
    }
};
