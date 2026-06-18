<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabel referensi agama. FK target dari employees.agama_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_agama', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nama', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_agama');
    }
};
