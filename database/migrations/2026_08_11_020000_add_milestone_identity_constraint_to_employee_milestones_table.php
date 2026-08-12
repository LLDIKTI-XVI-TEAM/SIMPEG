<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_SLOT_UNIQUE_INDEX = 'employee_milestones_active_slot_unique';

    private const SLOT_DOMAIN_CHECK = 'employee_milestones_slot_domain_check';

    /**
     * Menambahkan identitas slot stabil dan invariant satu milestone aktif per slot.
     */
    public function up(): void
    {
        Schema::table('employee_milestones', function (Blueprint $table): void {
            $table->string('milestone_key', 50)->default('default')->after('type');
        });

        $this->backfillMilestoneKeys();
        $this->deactivateDuplicateActiveMilestones();
        $this->addSlotDomainConstraint();

        // Partial unique index mempertahankan banyak riwayat nonaktif tanpa membiarkan
        // dua kalkulasi bersamaan menghasilkan lebih dari satu milestone aktif per slot.
        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON employee_milestones (employee_id, type, milestone_key) WHERE is_active = true',
            self::ACTIVE_SLOT_UNIQUE_INDEX,
        ));
    }

    /**
     * Menghapus invariant slot tanpa memulihkan status/data legacy yang dinormalisasi saat upgrade.
     * Normalisasi dan deduplikasi sengaja irreversible agar rollback tidak mengaktifkan duplikat lama.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::ACTIVE_SLOT_UNIQUE_INDEX);

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(includeMilestoneKey: false);

            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employee_milestones DROP CONSTRAINT IF EXISTS '.self::SLOT_DOMAIN_CHECK);
        }

        Schema::table('employee_milestones', function (Blueprint $table): void {
            $table->dropColumn('milestone_key');
        });
    }

    /** Mengisi slot Satyalancana dan menonaktifkan metadata legacy yang tidak dapat dipercaya. */
    private function backfillMilestoneKeys(): void
    {
        DB::table('employee_milestones')
            ->select(['id', 'milestone_date', 'metadata'])
            ->where('type', 'satyalancana')
            ->orderBy('id')
            ->chunk(500, function ($milestones): void {
                foreach ($milestones as $milestone) {
                    $metadata = $this->decodeMetadata($milestone->metadata);
                    $years = $metadata['satyalancana_years'] ?? $metadata['years_of_service'] ?? null;
                    $milestoneKey = $this->canonicalSatyalancanaKey($years);

                    if ($milestoneKey === null) {
                        // Baris invalid dipertahankan sebagai riwayat nonaktif. Key 10 hanya placeholder
                        // domain; nilai asli dan alasan normalisasi tetap tersimpan dalam metadata.
                        $metadata['milestone_key_normalization'] = [
                            'reason' => 'invalid_legacy_satyalancana_years',
                            'original_value' => $years,
                        ];

                        DB::table('employee_milestones')
                            ->where('id', $milestone->id)
                            ->update([
                                'milestone_key' => '10',
                                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                                'is_active' => false,
                                'updated_at' => now(),
                            ]);

                        continue;
                    }

                    DB::table('employee_milestones')
                        ->where('id', $milestone->id)
                        ->update(['milestone_key' => $milestoneKey]);
                }
            });
    }

    /** Mengembalikan key kanonis hanya untuk tiga slot Satyalancana resmi. */
    private function canonicalSatyalancanaKey(mixed $years): ?string
    {
        if (! is_numeric($years) || (float) $years !== (float) (int) $years) {
            return null;
        }

        $key = (string) (int) $years;

        return in_array($key, ['10', '20', '30'], true) ? $key : null;
    }

    /** Memasang CHECK lintas engine agar tipe dan slot tidak dapat menyimpang. */
    private function addSlotDomainConstraint(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                "ALTER TABLE employee_milestones ADD CONSTRAINT %s CHECK ((type = 'satyalancana' AND milestone_key IN ('10', '20', '30')) OR (type <> 'satyalancana' AND milestone_key = 'default'))",
                self::SLOT_DOMAIN_CHECK,
            ));

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(includeMilestoneKey: true);

            return;
        }

        throw new RuntimeException('Driver database tidak mendukung CHECK milestone_key yang diwajibkan.');
    }

    /**
     * SQLite tidak mendukung ADD/DROP CHECK; tabel kecil ini dibangun ulang sambil
     * mempertahankan seluruh data, foreign key, dan indeks pencarian milestone.
     */
    private function rebuildSqliteTable(bool $includeMilestoneKey): void
    {
        $milestoneKeyColumn = $includeMilestoneKey
            ? "milestone_key varchar(50) not null default 'default',"
            : '';
        $milestoneKeySelect = $includeMilestoneKey ? ', milestone_key' : '';
        $slotConstraint = $includeMilestoneKey
            ? ', constraint '.self::SLOT_DOMAIN_CHECK." check ((type = 'satyalancana' and milestone_key in ('10', '20', '30')) or (type <> 'satyalancana' and milestone_key = 'default'))"
            : '';

        DB::statement('DROP TABLE IF EXISTS employee_milestones_new');
        DB::statement("CREATE TABLE employee_milestones_new (
            id varchar not null primary key,
            employee_id varchar not null,
            type varchar(50) not null,
            {$milestoneKeyColumn}
            milestone_date date not null,
            calculated_at date not null,
            metadata text null,
            is_active tinyint(1) not null default '1',
            created_at datetime null,
            updated_at datetime null,
            foreign key(employee_id) references employees(id) on delete cascade
            {$slotConstraint}
        )");
        DB::statement("INSERT INTO employee_milestones_new (id, employee_id, type{$milestoneKeySelect}, milestone_date, calculated_at, metadata, is_active, created_at, updated_at)
            SELECT id, employee_id, type{$milestoneKeySelect}, milestone_date, calculated_at, metadata, is_active, created_at, updated_at FROM employee_milestones");
        DB::statement('DROP TABLE employee_milestones');
        DB::statement('ALTER TABLE employee_milestones_new RENAME TO employee_milestones');
        DB::statement('CREATE INDEX employee_milestones_employee_id_type_index ON employee_milestones (employee_id, type)');
        DB::statement('CREATE INDEX employee_milestones_milestone_date_is_active_index ON employee_milestones (milestone_date, is_active)');
        DB::statement('CREATE INDEX employee_milestones_type_index ON employee_milestones (type)');
    }

    /**
     * Menonaktifkan versi aktif yang lebih lama sebelum unique index dipasang.
     */
    private function deactivateDuplicateActiveMilestones(): void
    {
        $duplicateSlots = DB::table('employee_milestones')
            ->select(['employee_id', 'type', 'milestone_key'])
            ->where('is_active', true)
            ->groupBy(['employee_id', 'type', 'milestone_key'])
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateSlots as $slot) {
            $duplicateIds = DB::table('employee_milestones')
                ->where('employee_id', $slot->employee_id)
                ->where('type', $slot->type)
                ->where('milestone_key', $slot->milestone_key)
                ->where('is_active', true)
                ->orderByDesc('calculated_at')
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->skip(1)
                ->pluck('id')
                ->all();

            if ($duplicateIds !== []) {
                DB::table('employee_milestones')
                    ->whereIn('id', $duplicateIds)
                    ->update([
                        'is_active' => false,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (! is_string($metadata) || $metadata === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }
};
