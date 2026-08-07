<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menjaga jejak jabatan pada riwayat pegawai saat penghapusan dilakukan di
     * luar guard aplikasi. RESTRICT membuat database menolak, bukan mengosongkan
     * jabatan_id secara diam-diam seperti perilaku nullOnDelete sebelumnya.
     */
    public function up(): void
    {
        Schema::table('position_histories', function (Blueprint $table): void {
            $table->dropForeign(['jabatan_id']);
            $table->foreign('jabatan_id')
                ->references('id')
                ->on('ref_jabatan')
                ->restrictOnDelete();
        });
    }

    /**
     * Mengembalikan aturan lama secara eksplisit agar rollback skema tetap
     * dapat dilakukan pada lingkungan pengembangan.
     */
    public function down(): void
    {
        Schema::table('position_histories', function (Blueprint $table): void {
            $table->dropForeign(['jabatan_id']);
            $table->foreign('jabatan_id')
                ->references('id')
                ->on('ref_jabatan')
                ->nullOnDelete();
        });
    }
};
