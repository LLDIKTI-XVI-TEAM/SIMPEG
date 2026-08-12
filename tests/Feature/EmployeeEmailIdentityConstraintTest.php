<?php

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class EmployeeEmailIdentityConstraintTest extends TestCase
{
    use RefreshDatabase;

    /** Migrasi tidak boleh menebak pemilik identitas dengan menulis email sintetis. */
    public function test_unique_email_migration_fails_without_mutating_duplicate_identity_data(): void
    {
        DB::statement('DROP INDEX IF EXISTS employees_email_pribadi_unique');

        $first = Employee::factory()->create(['email_pribadi' => 'duplikat.migrasi@example.com']);
        $second = Employee::factory()->create(['email_pribadi' => 'alamat.lain@example.com']);
        DB::table('employees')->where('id', $second->id)->update([
            'email_pribadi' => 'DUPLIKAT.MIGRASI@example.com',
            'email' => 'DUPLIKAT.MIGRASI@example.com',
        ]);

        $migration = require database_path('migrations/2026_08_12_100000_add_email_pribadi_unique_to_employees_table.php');

        try {
            $migration->up();
            $this->fail('Migrasi harus berhenti ketika identitas email masih ambigu.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Bersihkan duplikat', $exception->getMessage());
            $this->assertStringNotContainsString('duplikat.migrasi@example.com', strtolower($exception->getMessage()));
        }

        $this->assertSame(
            'duplikat.migrasi@example.com',
            DB::table('employees')->where('id', $first->id)->value('email_pribadi'),
        );
        $this->assertSame(
            'DUPLIKAT.MIGRASI@example.com',
            DB::table('employees')->where('id', $second->id)->value('email_pribadi'),
        );
    }
}
