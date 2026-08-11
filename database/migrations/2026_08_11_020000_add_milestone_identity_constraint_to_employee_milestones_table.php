<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_SLOT_UNIQUE_INDEX = 'employee_milestones_active_slot_unique';

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

        // Partial unique index mempertahankan banyak riwayat nonaktif tanpa membiarkan
        // dua kalkulasi bersamaan menghasilkan lebih dari satu milestone aktif per slot.
        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON employee_milestones (employee_id, type, milestone_key) WHERE is_active = true',
            self::ACTIVE_SLOT_UNIQUE_INDEX,
        ));
    }

    /** Menghapus invariant slot saat rollback tanpa menyentuh data milestone. */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::ACTIVE_SLOT_UNIQUE_INDEX);

        Schema::table('employee_milestones', function (Blueprint $table): void {
            $table->dropColumn('milestone_key');
        });
    }

    /** Mengisi slot Satyalancana dari metadata 10/20/30 yang sudah tersimpan. */
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
                    $milestoneKey = is_numeric($years)
                        ? (string) (int) $years
                        : 'legacy:'.(string) $milestone->milestone_date;

                    DB::table('employee_milestones')
                        ->where('id', $milestone->id)
                        ->update(['milestone_key' => $milestoneKey]);
                }
            });
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
