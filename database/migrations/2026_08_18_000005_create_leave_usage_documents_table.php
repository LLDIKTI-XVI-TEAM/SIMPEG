<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_usage_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('leave_usage_record_id')->nullable();
            $table->uuid('leave_usage_reconciliation_set_id')->nullable();
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('path');
            $table->string('disk', 40);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('leave_usage_record_id', 'leave_usage_documents_leave_usage_record_id_foreign')
                ->references('id')->on('leave_usage_records')->restrictOnDelete();
            $table->foreign('leave_usage_reconciliation_set_id', 'leave_usage_documents_leave_usage_reconciliation_set_id_foreign')
                ->references('id')->on('leave_usage_reconciliation_sets')->restrictOnDelete();
            $table->foreign('uploaded_by', 'leave_usage_documents_uploaded_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->index('leave_usage_record_id', 'leave_usage_documents_usage_record_index');
            $table->index('leave_usage_reconciliation_set_id', 'leave_usage_documents_reconciliation_set_index');
            $table->unique(['disk', 'path'], 'leave_usage_documents_disk_path_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
ALTER TABLE leave_usage_documents
ADD CONSTRAINT leave_usage_document_target_check
CHECK ((leave_usage_record_id IS NOT NULL)::integer + (leave_usage_reconciliation_set_id IS NOT NULL)::integer = 1)
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_usage_documents');
    }
};
