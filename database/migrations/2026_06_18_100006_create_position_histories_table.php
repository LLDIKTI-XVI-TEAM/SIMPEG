<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('nama_jabatan', 255);
            $table->foreignUuid('jenis_jabatan_id')->constrained('ref_jenis_jabatan')->restrictOnDelete();
            $table->foreignUuid('eselon_id')->nullable()->constrained('ref_eselon')->nullOnDelete();
            $table->foreignUuid('unit_kerja_id')->constrained('ref_unit_kerja')->restrictOnDelete();
            $table->date('tmt_jabatan');
            $table->string('no_sk', 100);
            $table->date('tanggal_sk');
            $table->string('file_sk', 255)->nullable();
            $table->boolean('is_latest')->default(false);
            $table->timestamps();

            $table->index('employee_id');
            $table->index('is_latest');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_histories');
    }
};
