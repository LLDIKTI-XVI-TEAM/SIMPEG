<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabel referensi jenis pegawai (PNS, CPNS, PPPK). FK target dari employees.jenis_pegawai_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_jenis_pegawai', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nama', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_jenis_pegawai');
    }
};
