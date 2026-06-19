<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->enum('jenis_pengangkatan', ['CPNS', 'PNS', 'PPPK']);
            $table->date('tmt_pengangkatan');
            $table->string('no_sk', 100);
            $table->date('tanggal_sk');
            $table->string('file_sk', 255)->nullable();
            $table->timestamps();

            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
