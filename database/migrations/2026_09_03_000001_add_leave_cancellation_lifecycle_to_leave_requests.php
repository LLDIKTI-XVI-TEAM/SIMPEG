<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $previousStatuses = [
        'menunggu_approval',
        'ditangguhkan',
        'ditangguhkan_tugas_dinas',
        'perlu_perubahan',
        'disetujui',
        'tidak_disetujui',
        'dikembalikan_karena_rollover',
    ];

    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->bigInteger('revision_version')->default(1)->after('status');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $statuses = [...$this->previousStatuses, 'menunggu_pembatalan', 'dibatalkan'];
        $quotedStatuses = collect($statuses)
            ->map(fn (string $status): string => "'{$status}'")
            ->implode(', ');

        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_status_check');
        DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_status_check CHECK (status IN ({$quotedStatuses}))");
        DB::statement('ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_revision_version_check CHECK (revision_version > 0)');
    }

    public function down(): void
    {
        $this->stopRollbackWhenCancellationEvidenceExists();

        if (DB::getDriverName() === 'pgsql') {
            $quotedStatuses = collect($this->previousStatuses)
                ->map(fn (string $status): string => "'{$status}'")
                ->implode(', ');

            DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_revision_version_check');
            DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_status_check');
            DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_status_check CHECK (status IN ({$quotedStatuses}))");
        }

        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropColumn('revision_version');
        });
    }

    /** Rollback tidak boleh menghapus lifecycle pembatalan yang sudah menjadi bukti workflow. */
    private function stopRollbackWhenCancellationEvidenceExists(): void
    {
        // Histori cancellation tetap menjadi bukti meski request utama telah kembali ke status resume.
        if (Schema::hasTable('leave_cancellation_requests')
            && DB::table('leave_cancellation_requests')->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena histori permohonan pembatalan cuti sudah tercatat.');
        }

        if (DB::table('leave_requests')
            ->whereIn('status', ['menunggu_pembatalan', 'dibatalkan'])
            ->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena leave_requests sudah berisi bukti lifecycle pembatalan.');
        }
    }
};
