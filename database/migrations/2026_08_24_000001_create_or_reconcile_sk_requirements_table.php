<?php

use App\Support\Documents\SkCompleteness;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const REQUIREMENTS_TABLE = 'sk_requirements';

    private const OWNERSHIP_TABLE = 'sk_requirements_migration_provenance';

    public function up(): void
    {
        if (! Schema::hasTable(self::REQUIREMENTS_TABLE)) {
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

            $this->recordTableOwnership();
        }

        $this->reconcileCanonicalDefaults();
    }

    public function down(): void
    {
        // Tanpa marker, tabel berasal dari deployment legacy dan konfigurasi
        // operator harus dipertahankan. Rollback gagal aman alih-alih menghapus data.
        if (! $this->ownsRequirementsTable()) {
            return;
        }

        Schema::dropIfExists(self::REQUIREMENTS_TABLE);
        Schema::dropIfExists(self::OWNERSHIP_TABLE);
    }

    /** Marker hanya dibuat ketika migration ini benar-benar membuat tabel utama. */
    private function recordTableOwnership(): void
    {
        if (! Schema::hasTable(self::OWNERSHIP_TABLE)) {
            Schema::create(self::OWNERSHIP_TABLE, function (Blueprint $table): void {
                $table->string('resource', 64)->primary();
                $table->boolean('created_by_migration');
            });
        }

        DB::table(self::OWNERSHIP_TABLE)->updateOrInsert(
            ['resource' => self::REQUIREMENTS_TABLE],
            ['created_by_migration' => true],
        );
    }

    /** Menghapus tabel hanya jika provenance membuktikan ownership migration. */
    private function ownsRequirementsTable(): bool
    {
        return Schema::hasTable(self::OWNERSHIP_TABLE)
            && DB::table(self::OWNERSHIP_TABLE)
                ->where('resource', self::REQUIREMENTS_TABLE)
                ->where('created_by_migration', true)
                ->exists();
    }

    /**
     * Mengisi default yang sudah disahkan tanpa menimpa custom matrix existing.
     * Jenis tanpa default tidak disentuh agar konfigurasi operator tetap aman.
     */
    private function reconcileCanonicalDefaults(): void
    {
        if (! Schema::hasTable('ref_jenis_pegawai')) {
            return;
        }

        DB::transaction(function (): void {
            $now = now();
            $types = DB::table('ref_jenis_pegawai')
                ->whereIn('nama', array_keys(SkCompleteness::DEFAULTS))
                ->pluck('id', 'nama');

            foreach (SkCompleteness::DEFAULTS as $typeName => $requiredKeys) {
                $typeId = $types->get($typeName);
                if ($typeId === null) {
                    continue;
                }

                foreach ($requiredKeys as $skKey) {
                    DB::table(self::REQUIREMENTS_TABLE)->insertOrIgnore([
                        'id' => (string) Str::uuid(),
                        'jenis_pegawai_id' => $typeId,
                        'sk_key' => $skKey,
                        'is_wajib' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }
};
