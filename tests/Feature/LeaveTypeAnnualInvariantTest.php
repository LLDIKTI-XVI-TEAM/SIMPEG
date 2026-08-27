<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\AuditService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class LeaveTypeAnnualInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_membersihkan_flag_dirty_lalu_mengunci_semantik_code_tahunan(): void
    {
        $this->requirePostgreSql();
        $annualId = '00000000-0000-4000-8000-000000000a01';
        $nonAnnualId = '00000000-0000-4000-8000-000000000a02';
        $this->prepareMigrationRerun();
        DB::table('ref_jenis_cuti')->insert([
            [
                'id' => $annualId,
                'nama' => 'Nama Pribadi Rahasia Tahunan',
                'code' => 'tahunan',
                'mengurangi_saldo_tahunan' => false,
                'khusus_pns' => false,
            ],
            [
                'id' => $nonAnnualId,
                'nama' => 'Nama Pribadi Rahasia Non Tahunan',
                'code' => 'non_tahunan_dirty',
                'mengurangi_saldo_tahunan' => true,
                'khusus_pns' => false,
            ],
        ]);

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertTrue((bool) DB::table('ref_jenis_cuti')->where('code', 'tahunan')->value('mengurangi_saldo_tahunan'));
        $this->assertFalse((bool) DB::table('ref_jenis_cuti')->where('code', 'non_tahunan_dirty')->value('mengurangi_saldo_tahunan'));
        $audits = AuditLog::query()
            ->where('event', 'CONFIG_UPDATE')
            ->where('auditable_type', 'RefJenisCuti')
            ->orderBy('auditable_id')
            ->get();
        $this->assertCount(2, $audits, 'Setiap row yang dinormalisasi wajib memiliki tepat satu audit.');
        $expected = [
            $annualId => ['code' => 'tahunan', 'old' => false, 'new' => true],
            $nonAnnualId => ['code' => 'non_tahunan_dirty', 'old' => true, 'new' => false],
        ];

        foreach ($audits as $audit) {
            $change = $expected[(string) $audit->auditable_id] ?? null;
            $this->assertNotNull($change, 'Audit tidak boleh menunjuk row di luar fixture dirty.');
            $this->assertNull($audit->user_id);
            $this->assertSame(AuditService::SYSTEM_DATABASE_UPGRADE, $audit->user_name);
            $this->assertSame([
                'id' => $audit->auditable_id,
                'code' => $change['code'],
                'mengurangi_saldo_tahunan' => $change['old'],
            ], $audit->old_values);
            $this->assertSame([
                'id' => $audit->auditable_id,
                'code' => $change['code'],
                'mengurangi_saldo_tahunan' => $change['new'],
                'actor_type' => 'system',
            ], $audit->new_values);
        }
        $encodedAudits = $audits->toJson(JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Nama Pribadi Rahasia Tahunan', $encodedAudits);
        $this->assertStringNotContainsString('Nama Pribadi Rahasia Non Tahunan', $encodedAudits);

        try {
            DB::transaction(fn (): bool => DB::table('ref_jenis_cuti')->insert([
                'id' => (string) Str::uuid(),
                'nama' => 'Jenis Cuti Tidak Konsisten',
                'code' => 'non_tahunan_invalid',
                'mengurangi_saldo_tahunan' => true,
                'khusus_pns' => false,
            ]));
            $this->fail('PostgreSQL wajib menolak jenis non-tahunan yang mengurangi saldo tahunan.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('ref_jenis_cuti_annual_balance_flag_check', $exception->getMessage());
        }

        try {
            DB::transaction(fn (): int => DB::table('ref_jenis_cuti')
                ->where('code', 'tahunan')
                ->update(['mengurangi_saldo_tahunan' => false]));
            $this->fail('PostgreSQL wajib menolak code tahunan yang tidak mengurangi saldo tahunan.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('ref_jenis_cuti_annual_balance_flag_check', $exception->getMessage());
        }
    }

    public function test_migration_memproses_lebih_dari_satu_batch_dengan_query_dan_memori_terbatas(): void
    {
        $this->requirePostgreSql();
        $this->prepareMigrationRerun();
        DB::table('ref_jenis_cuti')->delete();

        $rows = [[
            'id' => '00000000-0000-4000-8000-000000000d00',
            'nama' => 'Cuti Tahunan Batch',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]];
        for ($index = 1; $index <= 200; $index++) {
            $rows[] = [
                'id' => sprintf('00000000-0000-4000-8001-%012d', $index),
                'nama' => 'Cuti Non Tahunan Batch '.$index,
                'code' => sprintf('non_tahunan_batch_%03d', $index),
                'mengurangi_saldo_tahunan' => true,
                'khusus_pns' => false,
            ];
        }
        DB::table('ref_jenis_cuti')->insert($rows);

        /** @var list<string> $queries */
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $sql = preg_replace('/\s+/', ' ', strtolower(trim($query->sql)));
            if (is_string($sql)) {
                $queries[] = $sql;
            }
        });

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $migrationQueries = $queries;

        $candidateSelects = array_values(array_filter(
            $migrationQueries,
            static fn (string $sql): bool => str_starts_with($sql, 'select')
                && str_contains($sql, 'from "ref_jenis_cuti"')
                && str_contains($sql, 'mengurangi_saldo_tahunan is distinct from'),
        ));
        $normalizationUpdates = array_values(array_filter(
            $migrationQueries,
            static fn (string $sql): bool => str_starts_with($sql, 'update "ref_jenis_cuti"'),
        ));
        $auditInserts = array_values(array_filter(
            $migrationQueries,
            static fn (string $sql): bool => str_starts_with($sql, 'insert into "audit_logs"'),
        ));
        $auditVerifications = array_values(array_filter(
            $migrationQueries,
            static fn (string $sql): bool => str_starts_with($sql, 'select count(*) as aggregate from "audit_logs"')
                && str_contains($sql, '"id" in'),
        ));

        $this->assertCount(2, $candidateSelects, '201 kandidat wajib dibaca sebagai dua batch keyset, bukan satu get() tanpa batas.');
        foreach ($candidateSelects as $candidateSelect) {
            $this->assertStringContainsString('limit 200', $candidateSelect);
        }
        $this->assertCount(2, $normalizationUpdates, 'Setiap batch wajib dinormalisasi dengan satu bulk UPDATE.');
        $this->assertCount(2, $auditInserts, 'Setiap batch wajib ditulis dengan satu bulk INSERT audit.');
        $this->assertCount(2, $auditVerifications, 'Jumlah audit yang tertulis wajib diverifikasi per batch.');
        $this->assertSame(0, DB::table('ref_jenis_cuti')
            ->whereRaw(<<<'SQL'
mengurangi_saldo_tahunan IS DISTINCT FROM
    CASE WHEN code = 'tahunan' THEN TRUE ELSE FALSE END
SQL)
            ->count());
        $this->assertSame(201, AuditLog::query()
            ->where('event', 'CONFIG_UPDATE')
            ->where('auditable_type', 'RefJenisCuti')
            ->count());
    }

    public function test_migration_tanpa_row_dirty_adalah_no_op_tanpa_audit_fiktif(): void
    {
        $this->requirePostgreSql();
        DB::table('ref_jenis_cuti')->delete();
        $this->prepareMigrationRerun();

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, AuditLog::query()
            ->where('event', 'CONFIG_UPDATE')
            ->where('auditable_type', 'RefJenisCuti')
            ->count());
        $this->assertTrue($this->annualFlagConstraintExists());
    }

    public function test_kegagalan_audit_membatalkan_normalisasi_constraint_dan_audit_sebelumnya(): void
    {
        $this->requirePostgreSql();
        $annualId = '00000000-0000-4000-8000-000000000b01';
        $rejectedId = '00000000-0000-4000-8000-000000000b02';
        $this->prepareMigrationRerun();
        DB::table('ref_jenis_cuti')->insert([
            [
                'id' => $annualId,
                'nama' => 'Cuti Tahunan Audit Rollback',
                'code' => 'tahunan',
                'mengurangi_saldo_tahunan' => false,
                'khusus_pns' => false,
            ],
            [
                'id' => $rejectedId,
                'nama' => 'Cuti Non Tahunan Audit Rollback',
                'code' => 'non_tahunan_audit_rollback',
                'mengurangi_saldo_tahunan' => true,
                'khusus_pns' => false,
            ],
        ]);
        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION uji_tolak_audit_invariant_tahunan() RETURNS trigger AS \$\$
BEGIN
    RAISE EXCEPTION 'Penulisan audit invariant tahunan ditolak untuk pengujian.';
END;
\$\$ LANGUAGE plpgsql;

CREATE TRIGGER uji_tolak_audit_invariant_tahunan
BEFORE INSERT ON audit_logs
FOR EACH ROW
WHEN (
    NEW.event = 'CONFIG_UPDATE'
    AND NEW.auditable_type = 'RefJenisCuti'
    AND NEW.auditable_id = '{$rejectedId}'
)
EXECUTE FUNCTION uji_tolak_audit_invariant_tahunan();
SQL);

        $failure = null;
        try {
            $this->artisan('migrate', ['--force' => true])->run();
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $this->assertNotNull($failure, 'Migration wajib meneruskan kegagalan audit.');
        $this->assertStringContainsString('audit invariant tahunan ditolak', $failure->getMessage());
        $this->assertFalse((bool) DB::table('ref_jenis_cuti')->where('id', $annualId)->value('mengurangi_saldo_tahunan'));
        $this->assertTrue((bool) DB::table('ref_jenis_cuti')->where('id', $rejectedId)->value('mengurangi_saldo_tahunan'));
        $this->assertSame(0, AuditLog::query()
            ->where('event', 'CONFIG_UPDATE')
            ->where('auditable_type', 'RefJenisCuti')
            ->count());
        $this->assertFalse($this->annualFlagConstraintExists());
        $this->assertSame(0, DB::table('migrations')
            ->where('migration', '2026_08_23_000001_enforce_annual_leave_type_balance_flag')
            ->count());
    }

    public function test_down_hanya_melepas_constraint_tanpa_membalik_normalisasi_atau_audit(): void
    {
        $this->requirePostgreSql();
        $id = '00000000-0000-4000-8000-000000000c01';
        $this->prepareMigrationRerun();
        DB::table('ref_jenis_cuti')->insert([
            'id' => $id,
            'nama' => 'Cuti Tahunan Down Contract',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $auditId = AuditLog::query()
            ->where('event', 'CONFIG_UPDATE')
            ->where('auditable_type', 'RefJenisCuti')
            ->where('auditable_id', $id)
            ->sole()
            ->id;

        $migration = require database_path('migrations/2026_08_23_000001_enforce_annual_leave_type_balance_flag.php');
        $migration->down();

        $this->assertFalse($this->annualFlagConstraintExists());
        $this->assertTrue((bool) DB::table('ref_jenis_cuti')->where('id', $id)->value('mengurangi_saldo_tahunan'));
        $this->assertDatabaseHas('audit_logs', [
            'id' => $auditId,
            'user_id' => null,
            'user_name' => AuditService::SYSTEM_DATABASE_UPGRADE,
        ]);
    }

    private function prepareMigrationRerun(): void
    {
        DB::statement('ALTER TABLE ref_jenis_cuti DROP CONSTRAINT IF EXISTS ref_jenis_cuti_annual_balance_flag_check');
        DB::table('migrations')
            ->where('migration', '2026_08_23_000001_enforce_annual_leave_type_balance_flag')
            ->delete();
    }

    private function annualFlagConstraintExists(): bool
    {
        return DB::table('pg_constraint')->where(
            'conname',
            'ref_jenis_cuti_annual_balance_flag_check',
        )->exists();
    }

    private function requirePostgreSql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Invariant jenis cuti wajib diuji pada PostgreSQL.');
        }
    }
}
