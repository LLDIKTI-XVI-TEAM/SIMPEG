<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menjaga jejak status pada riwayat pegawai saat penghapusan dilakukan di
     * luar guard aplikasi. RESTRICT membuat database menolak, bukan mengosongkan
     * status_pegawai_id secara diam-diam seperti perilaku nullOnDelete
     * sebelumnya. Riwayat status bersifat append-only sehingga relasinya tidak
     * boleh hilang tanpa jejak.
     */
    public function up(): void
    {
        Schema::table('employee_status_histories', function (Blueprint $table): void {
            $table->dropForeign(['status_pegawai_id']);
            $table->foreign('status_pegawai_id')
                ->references('id')
                ->on('ref_status_pegawai')
                ->restrictOnDelete();
        });
    }

    /**
     * Mengembalikan aturan lama secara eksplisit agar rollback skema tetap
     * dapat dilakukan pada lingkungan pengembangan.
     */
    public function down(): void
    {
        Schema::table('employee_status_histories', function (Blueprint $table): void {
            $table->dropForeign(['status_pegawai_id']);
            $table->foreign('status_pegawai_id')
                ->references('id')
                ->on('ref_status_pegawai')
                ->nullOnDelete();
        });
    }
};
