<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabel referensi hari libur nasional dan cuti bersama, dipakai kalkulasi hari kerja cuti.
// is_cuti_bersama membedakan cuti bersama dari libur nasional biasa.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_hari_libur', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('tanggal');
            $table->string('nama', 150);
            $table->integer('tahun');
            $table->boolean('is_cuti_bersama')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_hari_libur');
    }
};
