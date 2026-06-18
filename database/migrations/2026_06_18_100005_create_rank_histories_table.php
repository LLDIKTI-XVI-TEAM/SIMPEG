<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('golongan_id')->constrained('ref_golongan')->restrictOnDelete();
            $table->date('tmt_pangkat');
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
        Schema::dropIfExists('rank_histories');
    }
};
