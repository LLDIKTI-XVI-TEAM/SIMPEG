<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_cancellation_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('leave_request_id')->constrained('leave_requests')->restrictOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500);
            $table->string('status', 20);
            $table->string('resume_status', 40);
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE leave_cancellation_requests ADD CONSTRAINT leave_cancellation_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");
        DB::statement("ALTER TABLE leave_cancellation_requests ADD CONSTRAINT leave_cancellation_requests_resume_status_check CHECK (resume_status IN ('menunggu_approval', 'ditangguhkan'))");
        DB::statement('ALTER TABLE leave_cancellation_requests ADD CONSTRAINT leave_cancellation_requests_reason_check CHECK (char_length(btrim(reason)) BETWEEN 1 AND 500)');
        DB::statement("ALTER TABLE leave_cancellation_requests ADD CONSTRAINT leave_cancellation_requests_decision_check CHECK ((status = 'pending' AND decided_at IS NULL AND decided_by IS NULL) OR (status IN ('approved', 'rejected') AND decided_at IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX leave_cancellation_requests_pending_request_unique ON leave_cancellation_requests (leave_request_id) WHERE status = 'pending'");
        DB::statement('CREATE INDEX leave_cancellation_requests_queue_index ON leave_cancellation_requests (status, created_at, id)');
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_cancellation_requests')
            && DB::table('leave_cancellation_requests')->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena histori permohonan pembatalan cuti sudah tercatat.');
        }

        Schema::dropIfExists('leave_cancellation_requests');
    }
};
