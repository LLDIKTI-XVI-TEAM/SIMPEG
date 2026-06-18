<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('jenjang_id')->constrained('ref_jenjang_pendidikan')->restrictOnDelete();
            $table->string('nama_institusi', 255);
            $table->string('jurusan', 255)->nullable();
            $table->year('tahun_lulus');
            $table->string('no_ijazah', 100);
            $table->string('file_ijazah', 255)->nullable();
            $table->timestamps();

            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_histories');
    }
};
