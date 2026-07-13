<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard menjaga migrasi aman saat schema lingkungan telah menerima kolom bukti lebih dulu.
        if (! Schema::hasColumn('leave_proofs', 'document_mime')) {
            Schema::table('leave_proofs', function (Blueprint $table): void {
                $table->string('document_mime', 100)->nullable()->after('document_path');
            });
        }

        if (! Schema::hasColumn('leave_proofs', 'metadata')) {
            Schema::table('leave_proofs', function (Blueprint $table): void {
                $table->json('metadata')->nullable()->after('generated_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('leave_proofs', 'metadata')) {
            Schema::table('leave_proofs', function (Blueprint $table): void {
                $table->dropColumn('metadata');
            });
        }

        if (Schema::hasColumn('leave_proofs', 'document_mime')) {
            Schema::table('leave_proofs', function (Blueprint $table): void {
                $table->dropColumn('document_mime');
            });
        }
    }
};
