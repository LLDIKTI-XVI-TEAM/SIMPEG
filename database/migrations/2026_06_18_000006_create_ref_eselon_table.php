<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabel referensi eselon. FK target dari position_histories.eselon_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_eselon', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kode', 10)->unique();
            $table->string('nama', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_eselon');
    }
};
