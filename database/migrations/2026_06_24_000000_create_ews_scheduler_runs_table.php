<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ews_scheduler_runs', function (Blueprint $table): void {
            $table->id();
            $table->enum('status', ['sedang_berjalan', 'berhasil', 'gagal'])->default('sedang_berjalan');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('alerts_created')->default(0);
            $table->integer('employees_checked')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('started_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ews_scheduler_runs');
    }
};
