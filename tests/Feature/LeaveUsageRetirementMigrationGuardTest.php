<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefJenisCuti;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\GuardsDestructiveMigrationTestEnvironment;
use Tests\TestCase;

#[Group('guarded-destructive')]
class LeaveUsageRetirementMigrationGuardTest extends TestCase
{
    use GuardsDestructiveMigrationTestEnvironment;

    private const MIGRATION = '2026_09_02_000000_retire_annual_leave_usage_reconciliation.php';

    #[DataProvider('legacyDatasets')]
    public function test_retirement_menolak_data_lama_sebelum_mengubah_histori_atau_schema(bool $withFacts): void
    {
        $this->guardDestructiveMigrationTestEnvironment();
        $paths = collect(glob(database_path('migrations/*.php')) ?: [])
            ->filter(fn (string $path): bool => basename($path) < self::MIGRATION)
            ->sort()->values()->all();
        $this->assertNotEmpty($paths);

        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', [
                '--path' => $paths, '--realpath' => true, '--force' => true,
            ]), Artisan::output());
            $setId = $this->legacyFixture($withFacts);
            $before = $this->legacySnapshot();
            $migration = require database_path('migrations/'.self::MIGRATION);

            // Panggilan langsung membuktikan penolakan terjadi sebelum DDL, bukan hanya akibat rollback migrator.
            try {
                $migration->up();
                $this->fail('Retirement harus menolak data rekonsiliasi tanpa keputusan migrasi data eksplisit.');
            } catch (LogicException $exception) {
                $this->assertSame(
                    'Retirement rekonsiliasi tahunan dihentikan: data legacy masih ada dan memerlukan migrasi data eksplisit.',
                    $exception->getMessage(),
                );
            }

            $this->assertSame($before, $this->legacySnapshot());
            $this->assertTrue(Schema::hasTable('leave_usage_reconciliation_memberships'));
            $this->assertTrue(Schema::hasColumn('leave_usage_records', 'reconciliation_set_id'));
            $this->assertTrue(Schema::hasColumn('leave_usage_documents', 'leave_usage_reconciliation_set_id'));
            $this->assertDatabaseHas('leave_usage_reconciliation_sets', ['id' => $setId, 'status' => 'active']);
            if ($withFacts) {
                $this->assertSame([0, 2, 0], DB::table('leave_usage_records')->orderBy('usage_year')->pluck('workdays')->all());
                $this->assertDatabaseCount('leave_usage_external_approval_steps', 0);
            }
        } finally {
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        }
    }

    public static function legacyDatasets(): array
    {
        return ['set tanpa fakta' => [false], 'set dengan fakta nol dan dokumen' => [true]];
    }

    /** Ringkasan agregat tidak memiliki tanggal periode atau snapshot keputusan cuti manual. */
    private function legacyFixture(bool $withFacts): string
    {
        $employee = Employee::factory()->createQuietly();
        $setId = (string) Str::uuid();
        DB::table('leave_usage_reconciliation_sets')->insert([
            'id' => $setId,
            'employee_id' => $employee->id,
            'balance_year' => 2026,
            'reconciled_at' => '2026-08-20',
            'administrative_note' => 'Ringkasan historis pengujian.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (! $withFacts) {
            return $setId;
        }

        $leaveType = RefJenisCuti::query()->create([
            'code' => 'tahunan',
            'nama' => 'Cuti Tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        foreach ([2024 => 0, 2025 => 2, 2026 => 0] as $year => $workdays) {
            DB::table('leave_usage_records')->insert([
                'id' => (string) Str::uuid(),
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'source_type' => 'annual_reconciliation',
                'reconciliation_set_id' => $setId,
                'usage_year' => $year,
                'effective_date' => $year === 2026 ? '2026-08-20' : "{$year}-12-31",
                'workdays' => $workdays,
                'administrative_note' => 'Ringkasan tanpa rincian periode cuti.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('leave_usage_documents')->insert([
            'id' => (string) Str::uuid(),
            'leave_usage_reconciliation_set_id' => $setId,
            'original_name' => 'ringkasan.pdf',
            'stored_name' => 'ringkasan.pdf',
            'path' => 'fixture/retirement/ringkasan.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 123,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $setId;
    }

    /** Memastikan identitas fakta, target dokumen, dan definisi pengaman tidak ditulis ulang. */
    private function legacySnapshot(): array
    {
        $snapshot = [];
        foreach (['leave_usage_reconciliation_sets', 'leave_usage_records', 'leave_usage_documents'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()
                ->map(fn (object $row): array => (array) $row)->all();
        }
        $snapshot['triggers'] = collect(DB::select(<<<'SQL'
SELECT tgname, pg_get_triggerdef(oid) AS definition, pg_get_functiondef(tgfoid) AS function
FROM pg_trigger
WHERE NOT tgisinternal AND tgrelid IN (
    'leave_usage_records'::regclass,
    'leave_usage_documents'::regclass,
    'leave_usage_reconciliation_sets'::regclass
)
ORDER BY tgrelid, tgname
SQL))->map(fn (object $row): array => (array) $row)->all();

        return $snapshot;
    }
}
