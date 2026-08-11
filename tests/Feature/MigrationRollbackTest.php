<?php

namespace Tests\Feature;

use App\Models\Employee;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationRollbackTest extends TestCase
{
    // Note: Not using RefreshDatabase trait because SQLite VACUUM cannot run within transactions.
    // Our table rebuild migrations trigger VACUUM through Laravel's Schema::create().
    // These tests manually manage migration state instead.

    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations fresh for each test
        if (DB::connection()->getDriverName() === 'sqlite') {
            Artisan::call('migrate:fresh');
        }
    }

    protected function tearDown(): void
    {
        // Re-run fresh migrations to restore clean state.
        // Using migrate:fresh instead of migrate:reset because migrate:reset rolls back
        // every migration one-by-one which can fail on SQLite when migrations have
        // interdependencies (e.g. NOT NULL constraints on role during ALTER TABLE rebuild).
        if (DB::connection()->getDriverName() === 'sqlite') {
            Artisan::call('migrate:fresh');
        }

        parent::tearDown();
    }

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
        Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_06_20_000000_add_keycloak_username_to_users_table.php']);

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
        Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_06_20_000001_add_employee_id_to_users_table.php']);

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
        Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_06_20_000000_add_keycloak_username_to_users_table.php']);

        // Assert: User still exists but keycloak_username is gone
        $user = DB::table('users')->where('id', $testUserId)->first();
        $this->assertNotNull($user, 'User should still exist after rollback');
        $this->assertEquals('Preserved User', $user->name);
        $this->assertEquals('preserved@example.com', $user->email);
        $this->assertEquals('super_admin', $user->role);
        $this->assertFalse(
            Schema::hasColumn('users', 'keycloak_username'),
            'keycloak_username column should not exist after rollback'
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

    /**
     * Test: Hierarchy columns (parent_id, level, jenis_unit, is_active) can be rolled back on SQLite.
     *
     * Issue: down() in complete_phase_one_reference_tables migration skipped column
     * removal for SQLite, causing rollback to succeed but columns remain. Re-running
     * migration would fail because hierarchy columns still exist.
     */
    public function test_unit_kerja_hierarchy_migration_rollback_on_sqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This test is specific to SQLite rollback behavior.');
        }

        // Verify hierarchy columns exist after migration
        $this->assertTrue(
            Schema::hasColumn('ref_unit_kerja', 'parent_id'),
            'parent_id should exist after migration'
        );
        $this->assertTrue(
            Schema::hasColumn('ref_unit_kerja', 'level'),
            'level should exist after migration'
        );
        $this->assertTrue(
            Schema::hasColumn('ref_unit_kerja', 'jenis_unit'),
            'jenis_unit should exist after migration'
        );
        $this->assertTrue(
            Schema::hasColumn('ref_unit_kerja', 'is_active'),
            'is_active should exist after migration'
        );

        // Rollback the migration
        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_07_20_000000_complete_phase_one_reference_tables.php',
            '--force' => true,
        ]);

        // Verify hierarchy columns are removed
        $this->assertFalse(
            Schema::hasColumn('ref_unit_kerja', 'parent_id'),
            'parent_id should be removed after rollback'
        );
        $this->assertFalse(
            Schema::hasColumn('ref_unit_kerja', 'level'),
            'level should be removed after rollback'
        );
        $this->assertFalse(
            Schema::hasColumn('ref_unit_kerja', 'jenis_unit'),
            'jenis_unit should be removed after rollback'
        );
        $this->assertFalse(
            Schema::hasColumn('ref_unit_kerja', 'is_active'),
            'is_active should be removed after rollback'
        );

        // Verify we can re-run the migration
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_07_20_000000_complete_phase_one_reference_tables.php',
            '--force' => true,
        ]);

        // Verify hierarchy columns exist again
        $this->assertTrue(
            Schema::hasColumn('ref_unit_kerja', 'parent_id'),
            'parent_id should exist after re-running migration'
        );
        $this->assertTrue(
            Schema::hasColumn('ref_unit_kerja', 'level'),
            'level should exist after re-running migration'
        );
        $this->assertTrue(
            Schema::hasColumn('ref_unit_kerja', 'jenis_unit'),
            'jenis_unit should exist after re-running migration'
        );
        $this->assertTrue(
            Schema::hasColumn('ref_unit_kerja', 'is_active'),
            'is_active should exist after re-running migration'
        );
    }

    /**
     * Test: Data is preserved after rollback and re-migration of unit_kerja hierarchy.
     */
    public function test_unit_kerja_data_preserved_after_hierarchy_rollback(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This test is specific to SQLite rollback behavior.');
        }

        // Insert test data (using only original schema columns: id, nama, keterangan, timestamps)
        DB::table('ref_unit_kerja')->insert([
            'id' => '123e4567-e89b-12d3-a456-426614174000',
            'nama' => 'Test Unit Kerja',
            'keterangan' => 'Test keterangan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Rollback the migration
        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_07_20_000000_complete_phase_one_reference_tables.php',
            '--force' => true,
        ]);

        // Verify data is preserved after rollback (only original schema columns)
        $unit = DB::table('ref_unit_kerja')
            ->where('id', '123e4567-e89b-12d3-a456-426614174000')
            ->first();

        $this->assertNotNull($unit, 'Data should be preserved after rollback');
        $this->assertEquals('Test Unit Kerja', $unit->nama);
        $this->assertEquals('Test keterangan', $unit->keterangan);
        // Note: 'kode' column was added in later migration, not part of original schema
    }

    /**
     * Test: SSO users with null password can be rolled back successfully.
     *
     * Issue: Rebuild table schema defined password as NOT NULL, but original
     * schema allows null for SSO users. Rollback would fail when trying to
     * restore SSO user data with null password.
     */
    public function test_keycloak_username_rollback_preserves_sso_users_with_null_password(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This test is specific to SQLite rollback behavior.');
        }

        // Fresh migrate
        Artisan::call('migrate:fresh');

        // Insert SSO user with null password
        $ssoUserId = fake()->uuid();
        DB::table('users')->insert([
            'id' => $ssoUserId,
            'name' => 'SSO User',
            'email' => 'sso@example.com',
            'password' => null, // ← SSO user has no password
            'role' => 'pegawai',
            'keycloak_id' => 'kc-sso-123',
            'keycloak_username' => 'sso_user',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Rollback keycloak_username migration
        Artisan::call('migrate:rollback', ['--step' => 1]);

        // Verify SSO user is preserved with null password
        $user = DB::table('users')->where('id', $ssoUserId)->first();
        $this->assertNotNull($user, 'SSO user should be preserved after rollback');
        $this->assertEquals('SSO User', $user->name);
        $this->assertEquals('sso@example.com', $user->email);
        $this->assertNull($user->password, 'Password should remain null for SSO user');
        $this->assertEquals('kc-sso-123', $user->keycloak_id);
    }

    /**
     * Test: employee_id rollback preserves SSO users with null password.
     */
    public function test_employee_id_rollback_preserves_sso_users_with_null_password(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This test is specific to SQLite rollback behavior.');
        }

        // Fresh migrate
        Artisan::call('migrate:fresh');
        $this->seed(ReferenceSeeder::class);

        // Insert SSO user with null password and employee_id
        $ssoUserId = fake()->uuid();

        // Create employee using factory (ensures all required fields and references)
        $employee = Employee::factory()->create([
            'nip' => '199001012020121001',
            'nama_lengkap' => 'Test Employee',
            'email' => 'employee@example.com',
        ]);
        $employeeId = $employee->id;

        DB::table('users')->insert([
            'id' => $ssoUserId,
            'name' => 'SSO Employee User',
            'email' => 'sso.employee@example.com',
            'password' => null, // ← SSO user has no password
            'role' => 'pegawai',
            'keycloak_id' => 'kc-sso-emp-456',
            'keycloak_username' => 'sso_employee',
            'employee_id' => $employeeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Rollback employee_id migration (2 steps: employee_id, then keycloak_username)
        Artisan::call('migrate:rollback', ['--step' => 2]);

        // Verify SSO user is preserved with null password
        $user = DB::table('users')->where('id', $ssoUserId)->first();
        $this->assertNotNull($user, 'SSO user should be preserved after rollback');
        $this->assertEquals('SSO Employee User', $user->name);
        $this->assertNull($user->password, 'Password should remain null for SSO user');
        $this->assertEquals('kc-sso-emp-456', $user->keycloak_id);
    }
}
