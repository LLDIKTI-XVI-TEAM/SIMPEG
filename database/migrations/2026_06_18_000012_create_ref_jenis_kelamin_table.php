<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabel referensi jenis kelamin sesuai PRD v1.1 section 16.6. FK target dari employees.jenis_kelamin_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_jenis_kelamin', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kode', 1)->unique();
            $table->string('nama', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_jenis_kelamin');
    }
};
