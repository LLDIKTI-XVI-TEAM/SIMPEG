<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            // Snapshot kontak pemohon selama cuti. Nullable agar data cuti legacy
            // (dibuat sebelum kolom ini ada) tetap valid tanpa mengisi kontak.
            $table->text('alamat_selama_cuti')->nullable()->after('alasan');
            $table->string('nomor_telepon', 20)->nullable()->after('alamat_selama_cuti');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropColumn(['alamat_selama_cuti', 'nomor_telepon']);
        });
    }
};
