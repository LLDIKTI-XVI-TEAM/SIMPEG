<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabel referensi golongan. Kode menampung dua skema: PNS Romawi (III/d) dan PPPK numerik (IX).
// FK target dari rank_histories.golongan_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_golongan', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kode', 20)->unique();
            $table->string('nama', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_golongan');
    }
};
