<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabel referensi jenis cuti. khusus_pns menandai cuti yang hanya berlaku untuk PNS.
// FK target dari leave_requests.jenis_cuti_id. Seed policy lengkap menunggu aturan cuti final.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_jenis_cuti', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nama', 100);
            $table->boolean('khusus_pns')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_jenis_cuti');
    }
};
