<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Matriks "SK wajib per jenis pegawai" yang dapat dikonfigurasi super admin.
     *
     * Tersimpan satu baris per (jenis_pegawai, sk_key) lengkap dengan flag
     * is_wajib, sehingga super admin dapat menandai SK mana yang wajib (dan juga
     * mencabutnya) untuk tiap jenis pegawai. Jenis pegawai yang belum punya baris
     * sama sekali memakai fallback: semua SK wajib (kompatibel dengan perilaku
     * lama sebelum matriks ini ada).
     */
    public function up(): void
    {
        Schema::create('sk_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('jenis_pegawai_id');
            $table->string('sk_key', 32);
            $table->boolean('is_wajib')->default(false);
            $table->timestamps();

            $table->unique(['jenis_pegawai_id', 'sk_key']);

            $table->foreign('jenis_pegawai_id')
                ->references('id')
                ->on('ref_jenis_pegawai')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sk_requirements');
    }
};
