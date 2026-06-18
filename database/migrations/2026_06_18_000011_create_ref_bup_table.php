<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabel detail Batas Usia Pensiun per jenis jabatan, opsional pelengkap ref_jenis_jabatan.maks_usia_pensiun.
// Seed value menunggu daftar jabatan dan BUP final dari LLDIKTI (BLK-13).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_bup', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('jenis_jabatan_id')->constrained('ref_jenis_jabatan');
            $table->string('nama_jabatan_detail', 200)->nullable();
            $table->integer('usia_pensiun');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_bup');
    }
};
