<?php

namespace Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: keycloak_username migration dapat di-rollback dengan benar di SQLite.
     *
     * Issue: SQLite down() sebelumnya melakukan early return, menyebabkan
     * kolom tidak benar-benar dihapus. Rollback kemudian gagal saat migrate up
     * lagi karena kolom dan unique index sudah ada.
     */
    public function test_keycloak_username_migration_can_be_rolled_back_on_sqlite(): void
    {
        // Skip if not SQLite (PostgreSQL has different behavior)
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This test is specific to SQLite rollback behavior');
        }

        // Fresh migrate to ensure keycloak_username exists
        Artisan::call('migrate:fresh');

        // Assert: keycloak_username column exists
        $this->assertTrue(
            Schema::hasColumn('users', 'keycloak_username'),
            'keycloak_username should exist after fresh migration'
        );

        // Rollback the keycloak_username migration
        Artisan::call('migrate:rollback', ['--step' => 1]);

        // Assert: keycloak_username column should be removed
        $this->assertFalse(
            Schema::hasColumn('users', 'keycloak_username'),
            'keycloak_username should be removed after rollback'
        );

        // Re-run migration (this would fail with old early-return approach)
        Artisan::call('migrate');

        // Assert: keycloak_username exists again
        $this->assertTrue(
            Schema::hasColumn('users', 'keycloak_username'),
            'keycloak_username should exist after re-running migration'
        );

        // Verify unique index works
        DB::table('users')->insert([
            'id' => fake()->uuid(),
            'name' => 'Test User 1',
            'email' => 'test1@example.com',
            'password' => bcrypt('password'),
            'keycloak_username' => 'user1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Duplicate keycloak_username should fail
        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('users')->insert([
            'id' => fake()->uuid(),
            'name' => 'Test User 2',
            'email' => 'test2@example.com',
            'password' => bcrypt('password'),
            'keycloak_username' => 'user1', // ← Duplicate
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test: employee_id migration dapat di-rollback dengan benar di SQLite.
     */
    public function test_employee_id_migration_can_be_rolled_back_on_sqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This test is specific to SQLite rollback behavior');
        }

        // Fresh migrate
        Artisan::call('migrate:fresh');

        // Assert: employee_id column exists
        $this->assertTrue(
            Schema::hasColumn('users', 'employee_id'),
            'employee_id should exist after fresh migration'
        );

        // Rollback the employee_id migration (need to rollback 2 steps: employee_id, then keycloak_username)
        Artisan::call('migrate:rollback', ['--step' => 2]);

        // Assert: employee_id column should be removed
        $this->assertFalse(
            Schema::hasColumn('users', 'employee_id'),
            'employee_id should be removed after rollback'
        );

        // Re-run migrations
        Artisan::call('migrate');

        // Assert: employee_id exists again
        $this->assertTrue(
            Schema::hasColumn('users', 'employee_id'),
            'employee_id should exist after re-running migration'
        );
    }

    /**
     * Test: Data tetap terjaga setelah rollback dan re-migrate (SQLite rebuild preserves data).
     */
    public function test_user_data_preserved_after_rollback_and_remigrate_on_sqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This test is specific to SQLite rollback behavior');
        }

        // Fresh migrate and insert test data
        Artisan::call('migrate:fresh');

        $testUserId = fake()->uuid();
        DB::table('users')->insert([
            'id' => $testUserId,
            'name' => 'Preserved User',
            'email' => 'preserved@example.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'keycloak_id' => 'kc-123',
            'keycloak_username' => 'preserved_user',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Rollback keycloak_username
        Artisan::call('migrate:rollback', ['--step' => 1]);

        // Assert: User still exists but keycloak_username is gone
        $user = DB::table('users')->where('id', $testUserId)->first();
        $this->assertNotNull($user, 'User should still exist after rollback');
        $this->assertEquals('Preserved User', $user->name);
        $this->assertEquals('preserved@example.com', $user->email);
        $this->assertEquals('super_admin', $user->role);
        $this->assertFalse(
            property_exists($user, 'keycloak_username'),
            'keycloak_username should not exist in user record after rollback'
        );

        // Re-migrate
        Artisan::call('migrate');

        // Assert: User still exists and all fields intact
        $userAfter = DB::table('users')->where('id', $testUserId)->first();
        $this->assertNotNull($userAfter);
        $this->assertEquals('Preserved User', $userAfter->name);
        $this->assertEquals('preserved@example.com', $userAfter->email);
        $this->assertEquals('super_admin', $userAfter->role);
        $this->assertEquals('kc-123', $userAfter->keycloak_id);
    }
}
