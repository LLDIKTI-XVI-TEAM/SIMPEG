<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Halaman audit mengurutkan dan menyusun daftar pilihan berdasarkan nama operator.
     * Tanpa indeks, kedua operasi memaksa pemindaian seluruh tabel yang terus bertambah
     * karena audit tidak memiliki mekanisme penghapusan.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index('user_name', 'audit_logs_user_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_user_name_index');
        });
    }
};
