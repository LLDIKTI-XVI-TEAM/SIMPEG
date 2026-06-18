<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabel referensi jenjang pendidikan. FK target dari education_histories.jenjang_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_jenjang_pendidikan', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nama', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_jenjang_pendidikan');
    }
};
