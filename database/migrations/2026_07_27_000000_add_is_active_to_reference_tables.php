<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom is_active untuk kebijakan hapus hybrid pada reference tables:
     * item yang sudah direferensikan data pegawai tidak dihapus permanen,
     * hanya dinonaktifkan agar data historis tetap utuh sementara item
     * nonaktif hilang dari dropdown input baru. Default true supaya seluruh
     * data referensi yang sudah ada tetap dianggap aktif.
     *
     * ref_jabatan, ref_unit_kerja, dan ref_notification_channels sudah punya
     * kolom penanda aktif dari migrasi sebelumnya sehingga tidak disentuh.
     */
    private const TABLES = [
        'ref_golongan',
        'ref_jenis_jabatan',
        'ref_status_pegawai',
        'ref_eselon',
        'ref_jenjang_pendidikan',
        'ref_bup',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->boolean('is_active')->default(true);

                $table->index('is_active', $tableName.'_is_active_index');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropIndex($tableName.'_is_active_index');
                $table->dropColumn('is_active');
            });
        }
    }
};
