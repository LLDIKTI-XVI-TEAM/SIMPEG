<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->stopWhenLegacyInFlightRequestsExist();

        $this->dropLegacyCheckConstraints();

        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->string('status', 50)->default('menunggu_approval')->change();
        });

        Schema::table('leave_approvals', function (Blueprint $table): void {
            $table->string('action', 50)->change();
        });
    }

    public function down(): void
    {
        $this->dropLegacyCheckConstraints();

        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->string('status', 50)->default('Draft')->change();
        });
    }

    /**
     * Keputusan proyek: belum ada data produksi/open request. Jika data berjalan lama ternyata ada,
     * migrasi berhenti agar request tanpa snapshot tidak menjadi macet diam-diam.
     */
    private function stopWhenLegacyInFlightRequestsExist(): void
    {
        $hasLegacyInFlight = DB::table('leave_requests')
            ->leftJoin('leave_request_steps', 'leave_request_steps.leave_request_id', '=', 'leave_requests.id')
            ->whereNull('leave_request_steps.id')
            ->whereNotIn('leave_requests.status', ['Draft', 'Disetujui', 'Tidak Disetujui'])
            ->exists();

        if ($hasLegacyInFlight) {
            throw new RuntimeException('Migrasi cuti dinamis dihentikan: masih ada pengajuan berjalan tanpa snapshot approval. Reset/backfill data sebelum migrasi.');
        }
    }

    /**
     * Constraint lama dari enum status/action harus dilepas sebelum kolom jadi string bebas.
     */
    private function dropLegacyCheckConstraints(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_status_check');
        DB::statement('ALTER TABLE leave_approvals DROP CONSTRAINT IF EXISTS leave_approvals_action_check');
    }
};
