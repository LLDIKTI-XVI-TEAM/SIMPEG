<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Kolom untuk menyimpan role simulasi sementara (US-1.6 Switch Role).
            // Digunakan oleh Super Admin untuk demo/testing dengan role yang lebih rendah.
            $table->string('temporary_role')->nullable()->after('role');
            // Kolom untuk permission tambahan sementara (opsional, jika diperlukan).
            // Memakai text agar mampu menampung daftar permission lengkap dari role target (US-1.6/K-MTG-03).
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
