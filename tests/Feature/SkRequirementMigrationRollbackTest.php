<?php

namespace Tests\Feature;

use App\Models\RefJenisPegawai;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

interface SkRequirementMigrationContract
{
    public function up(): void;

    public function down(): void;
}

class SkRequirementMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_FILE = 'migrations/2026_08_24_000001_create_or_reconcile_sk_requirements_table.php';

    private const REQUIREMENTS_TABLE = 'sk_requirements';

    private const OWNERSHIP_TABLE = 'sk_requirements_migration_provenance';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
    }

    public function test_down_drops_table_created_and_owned_by_this_migration(): void
    {
        $migration = $this->migration();

        $this->assertTrue(Schema::hasTable(self::REQUIREMENTS_TABLE));
        $this->assertTrue(Schema::hasTable(self::OWNERSHIP_TABLE));

        try {
            $migration->down();

            $this->assertFalse(Schema::hasTable(self::REQUIREMENTS_TABLE));
            $this->assertFalse(Schema::hasTable(self::OWNERSHIP_TABLE));
        } finally {
            $migration->up();
        }
    }

    public function test_down_preserves_preexisting_table_and_custom_operator_configuration(): void
    {
        $migration = $this->migration();
        $pppk = RefJenisPegawai::query()->where('nama', 'PPPK')->firstOrFail();
        $customId = (string) Str::uuid();

        Schema::dropIfExists(self::OWNERSHIP_TABLE);
        Schema::dropIfExists(self::REQUIREMENTS_TABLE);
        $this->createLegacyRequirementsTable();
        DB::table(self::REQUIREMENTS_TABLE)->insert([
            'id' => $customId,
            'jenis_pegawai_id' => $pppk->id,
            'sk_key' => 'sk_pengangkatan',
            'is_wajib' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $migration->up();

            $this->assertFalse(Schema::hasTable(self::OWNERSHIP_TABLE));
            $this->assertDatabaseHas(self::REQUIREMENTS_TABLE, [
                'id' => $customId,
                'jenis_pegawai_id' => $pppk->id,
                'sk_key' => 'sk_pengangkatan',
                'is_wajib' => true,
            ]);

            $migration->down();

            $this->assertTrue(Schema::hasTable(self::REQUIREMENTS_TABLE));
            $this->assertDatabaseHas(self::REQUIREMENTS_TABLE, [
                'id' => $customId,
                'jenis_pegawai_id' => $pppk->id,
                'sk_key' => 'sk_pengangkatan',
                'is_wajib' => true,
            ]);
        } finally {
            Schema::dropIfExists(self::OWNERSHIP_TABLE);
            Schema::dropIfExists(self::REQUIREMENTS_TABLE);
            $migration->up();
        }
    }

    /** @return Migration&SkRequirementMigrationContract */
    private function migration(): Migration
    {
        return require database_path(self::MIGRATION_FILE);
    }

    /** Meniru struktur legacy agar jalur rekonsiliasi dan rollback diuji tanpa marker ownership. */
    private function createLegacyRequirementsTable(): void
    {
        Schema::create(self::REQUIREMENTS_TABLE, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('jenis_pegawai_id')
                ->constrained('ref_jenis_pegawai')
                ->cascadeOnDelete();
            $table->string('sk_key', 32);
            $table->boolean('is_wajib')->default(false);
            $table->timestamps();
            $table->unique(['jenis_pegawai_id', 'sk_key']);
            $table->index(['jenis_pegawai_id', 'is_wajib']);
        });
    }
}
