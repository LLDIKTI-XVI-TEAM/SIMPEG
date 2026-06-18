<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabel referensi unit kerja. FK target dari position_histories.unit_kerja_id.
// Seed value menunggu konfirmasi struktur organisasi LLDIKTI (BLK-03).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_unit_kerja', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nama', 150);
            $table->text('keterangan')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_unit_kerja');
    }
};
