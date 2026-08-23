<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membekukan konteks role aktor saat batch impor diantrekan.
     *
     * Audit import ditulis oleh worker (async) yang tidak punya session; bila konteks simulasi
     * di-resolve dari record user saat worker berjalan, hasilnya bisa berbeda dari saat operasi
     * diotorisasi (mis. user beralih/revert role di antara enqueue dan eksekusi). Snapshot
     * original/effective role diambil dari aktor live saat baris batch dibuat dan diteruskan
     * ke payload audit agar jejak konsisten dengan waktu pengantrean.
     */
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->string('queued_original_role')->nullable()->after('user_id');
            $table->string('queued_effective_role')->nullable()->after('queued_original_role');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropColumn(['queued_original_role', 'queued_effective_role']);
        });
    }
};
