<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabel referensi jenis jabatan. maks_usia_pensiun jadi sumber BUP (PRD v1.1, tidak di-hardcode).
// FK target dari position_histories.jenis_jabatan_id dan ref_bup.jenis_jabatan_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_jenis_jabatan', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nama', 150);
            $table->integer('maks_usia_pensiun');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_jenis_jabatan');
    }
};
