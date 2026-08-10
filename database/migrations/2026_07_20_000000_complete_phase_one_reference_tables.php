<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Menyelaraskan reference table yang telah dipakai data lama dengan kontrak Fase 1.
     */
    public function up(): void
    {
        $this->addUnitKerjaHierarchy();
        $this->addJabatanLifecycleFields();
        $this->addStatusPegawaiFields();
        $this->synchronizeStatusPegawai();
        $this->finalizeStatusPegawaiFields();
        $this->createNotificationChannels();
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_notification_channels');

        Schema::table('ref_status_pegawai', function (Blueprint $table): void {
            $table->dropUnique('ref_status_pegawai_kode_unique');
            $table->dropColumn(['kode', 'kelompok']);
        });

        Schema::table('ref_jabatan', function (Blueprint $table): void {
            $table->dropIndex('ref_jabatan_is_active_index');
            $table->dropColumn(['default_bup', 'is_active']);
        });

        // SQLite requires table rebuild to drop columns with foreign keys
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rollbackUnitKerjaHierarchySqlite();
        } else {
            Schema::table('ref_unit_kerja', function (Blueprint $table): void {
                $table->dropForeign('ref_unit_kerja_parent_id_foreign');
                $table->dropIndex('ref_unit_kerja_parent_level_index');
                $table->dropIndex('ref_unit_kerja_is_active_index');
                $table->dropColumn(['parent_id', 'level', 'jenis_unit', 'is_active']);
            });
        }
    }

    private function addUnitKerjaHierarchy(): void
    {
        Schema::table('ref_unit_kerja', function (Blueprint $table): void {
            $table->foreignUuid('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('ref_unit_kerja')
                ->nullOnDelete();
            $table->unsignedSmallInteger('level')->default(0)->after('parent_id');
            $table->string('jenis_unit', 50)->default('unit_kerja')->after('nama');
            $table->boolean('is_active')->default(true)->after('jenis_unit');

            $table->index(['parent_id', 'level'], 'ref_unit_kerja_parent_level_index');
            $table->index('is_active', 'ref_unit_kerja_is_active_index');
        });
    }

    private function addJabatanLifecycleFields(): void
    {
        Schema::table('ref_jabatan', function (Blueprint $table): void {
            $table->unsignedSmallInteger('default_bup')->nullable()->after('eselon_id');
            $table->boolean('is_active')->default(true)->after('default_bup');

            $table->index('is_active', 'ref_jabatan_is_active_index');
        });
    }

    private function addStatusPegawaiFields(): void
    {
        Schema::table('ref_status_pegawai', function (Blueprint $table): void {
            $table->string('kode', 50)->nullable()->after('id');
            $table->string('kelompok', 50)->nullable()->after('nama');
        });
    }

    /**
     * Menggunakan kode stabil untuk status lama agar relasi pegawai yang sudah ada tidak perlu ditulis ulang.
     */
    private function synchronizeStatusPegawai(): void
    {
        $definitions = [
            [
                'kode' => 'AKTIF',
                'nama' => 'Aktif',
                'kelompok' => 'Aktif',
                'keterangan' => 'Pegawai aktif.',
                'is_default' => true,
                'legacy_names' => ['Aktif'],
            ],
            [
                'kode' => 'NONAKTIF',
                'nama' => 'Nonaktif',
                'kelompok' => 'Nonaktif',
                'keterangan' => 'Pegawai tidak aktif.',
                'is_default' => false,
                'legacy_names' => ['Nonaktif', 'Non-Aktif'],
            ],
            [
                'kode' => 'PENSIUN',
                'nama' => 'Pensiun',
                'kelompok' => 'Nonaktif',
                'keterangan' => 'Pegawai telah pensiun.',
                'is_default' => false,
                'legacy_names' => ['Pensiun'],
            ],
            [
                'kode' => 'MUTASI',
                'nama' => 'Mutasi',
                'kelompok' => 'Nonaktif',
                'keterangan' => 'Pegawai mutasi keluar.',
                'is_default' => false,
                'legacy_names' => ['Mutasi'],
            ],
            [
                'kode' => 'CLTN',
                'nama' => 'Cuti Luar Tanggungan Negara',
                'kelompok' => 'Nonaktif',
                'keterangan' => 'Pegawai menjalani cuti luar tanggungan negara.',
                'is_default' => false,
                'legacy_names' => ['Cuti Luar Tanggungan Negara'],
            ],
            [
                'kode' => 'PERPANJANGAN_CLTN',
                'nama' => 'Perpanjangan CLTN',
                'kelompok' => 'Nonaktif',
                'keterangan' => 'Cuti luar tanggungan negara diperpanjang.',
                'is_default' => false,
                'legacy_names' => ['Perpanjangan CLTN'],
            ],
            [
                'kode' => 'TUGAS_BELAJAR',
                'nama' => 'Tugas Belajar',
                'kelompok' => 'Aktif/khusus',
                'keterangan' => 'Pegawai menjalani tugas belajar.',
                'is_default' => false,
                'legacy_names' => ['Tugas Belajar'],
            ],
            [
                'kode' => 'PEMBERHENTIAN_SEMENTARA',
                'nama' => 'Pemberhentian Sementara',
                'kelompok' => 'Nonaktif',
                'keterangan' => 'Pegawai diberhentikan sementara.',
                'is_default' => false,
                'legacy_names' => ['Pemberhentian Sementara'],
            ],
            [
                'kode' => 'WAJIB_MILITER',
                'nama' => 'Wajib Militer',
                'kelompok' => 'Nonaktif/khusus',
                'keterangan' => 'Pegawai menjalani wajib militer.',
                'is_default' => false,
                'legacy_names' => ['Wajib Militer'],
            ],
            [
                'kode' => 'HILANG',
                'nama' => 'PNS Dinyatakan Hilang',
                'kelompok' => 'Nonaktif/khusus',
                'keterangan' => 'PNS dinyatakan hilang.',
                'is_default' => false,
                'legacy_names' => ['PNS Dinyatakan Hilang'],
            ],
        ];

        $now = now();
        $assignedIds = [];

        foreach ($definitions as $definition) {
            $status = null;

            foreach ($definition['legacy_names'] as $legacyName) {
                $status = DB::table('ref_status_pegawai')->where('nama', $legacyName)->first();

                if ($status !== null && ! in_array($status->id, $assignedIds, true)) {
                    break;
                }

                $status = null;
            }

            $attributes = [
                'kode' => $definition['kode'],
                'nama' => $definition['nama'],
                'kelompok' => $definition['kelompok'],
                'keterangan' => $definition['keterangan'],
                'is_default' => $definition['is_default'],
                'updated_at' => $now,
            ];

            if ($status !== null) {
                DB::table('ref_status_pegawai')->where('id', $status->id)->update($attributes);
                $assignedIds[] = $status->id;

                continue;
            }

            DB::table('ref_status_pegawai')->insert([
                'id' => (string) Str::uuid(),
                ...$attributes,
                'created_at' => $now,
            ]);
        }

        DB::table('ref_status_pegawai')
            ->whereNull('kode')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $status) use ($now): void {
                DB::table('ref_status_pegawai')->where('id', $status->id)->update([
                    'kode' => 'LEGACY_'.strtoupper((string) $status->id),
                    'kelompok' => 'Legacy',
                    'updated_at' => $now,
                ]);
            });
    }

    private function finalizeStatusPegawaiFields(): void
    {
        Schema::table('ref_status_pegawai', function (Blueprint $table): void {
            $table->string('kode', 50)->nullable(false)->change();
            $table->string('kelompok', 50)->nullable(false)->change();
            $table->unique('kode');
        });
    }

    private function createNotificationChannels(): void
    {
        Schema::create('ref_notification_channels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->boolean('is_enabled')->default(false);
            $table->json('config')->nullable();
            $table->timestamps();
        });

        $now = now();

        foreach ([
            ['code' => 'in_app', 'name' => 'Notifikasi dalam aplikasi', 'is_enabled' => true, 'config' => null],
            ['code' => 'email', 'name' => 'Email', 'is_enabled' => true, 'config' => null],
            ['code' => 'whatsapp_business', 'name' => 'WhatsApp Business', 'is_enabled' => false, 'config' => null],
        ] as $channel) {
            DB::table('ref_notification_channels')->insert([
                'id' => (string) Str::uuid(),
                ...$channel,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Rollback unit kerja hierarchy columns for SQLite using table rebuild pattern.
     * SQLite doesn't support dropping columns with foreign keys, so we recreate the table.
     */
    private function rollbackUnitKerjaHierarchySqlite(): void
    {
        // Backup existing data
        DB::statement('CREATE TEMPORARY TABLE ref_unit_kerja_backup AS SELECT id, nama, kode, keterangan, created_at, updated_at FROM ref_unit_kerja');

        // Drop original table
        Schema::dropIfExists('ref_unit_kerja');

        // Recreate table without hierarchy columns
        Schema::create('ref_unit_kerja', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nama', 200);
            $table->string('kode', 50)->nullable();
            $table->text('keterangan')->nullable();
            $table->timestamps();
        });

        // Restore data
        DB::statement('INSERT INTO ref_unit_kerja (id, nama, kode, keterangan, created_at, updated_at) SELECT id, nama, kode, keterangan, created_at, updated_at FROM ref_unit_kerja_backup');

        // Drop temporary table
        DB::statement('DROP TABLE ref_unit_kerja_backup');
    }
};
