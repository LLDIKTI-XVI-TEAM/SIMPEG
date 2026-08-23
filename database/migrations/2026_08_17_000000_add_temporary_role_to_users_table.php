<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Kolom untuk menyimpan role simulasi sementara (switch role Super Admin ke role lebih rendah).
            $table->string('temporary_role')->nullable()->after('role');
            // Metadata opsional simulasi (daftar permission). Hanya untuk audit/backward-compatibility:
            // permission efektif selalu diturunkan dinamis dari role tujuan pada setiap request.
            $table->text('temporary_permission')->nullable()->after('temporary_role');
            // Timestamp kapan switch role dilakukan (untuk audit).
            $table->timestamp('temporary_role_started_at')->nullable()->after('temporary_permission');
            // User ID yang melakukan switch (untuk audit trail, biasanya sama dengan id user sendiri).
            $table->string('temporary_role_switched_by')->nullable()->after('temporary_role_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'temporary_role',
                'temporary_permission',
                'temporary_role_started_at',
                'temporary_role_switched_by',
            ]);
        });
    }
};
