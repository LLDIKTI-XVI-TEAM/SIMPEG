<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('jenis_cuti_id')->constrained('ref_jenis_cuti')->restrictOnDelete();
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->unsignedSmallInteger('jumlah_hari_kerja');
            $table->text('alasan');
            $table->string('lampiran_path', 255)->nullable();
            $table->enum('status', [
                'Draft',
                'Menunggu Atasan Langsung',
                'Menunggu Verifikator',
                'Menunggu Pimpinan',
                'Disetujui',
                'Ditunda',
            ])->default('Draft');
            $table->timestamps();

            $table->index('employee_id');
            $table->index('status');
        });

        Schema::create('leave_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->foreignUuid('approver_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedTinyInteger('stage');
            $table->enum('action', ['APPROVE', 'POSTPONE']);
            $table->text('komentar')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            $table->index('leave_request_id');
        });

        Schema::create('leave_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->year('tahun');
            $table->unsignedSmallInteger('jatah_awal')->default(12);
            $table->unsignedSmallInteger('carry_over')->default(0);
            $table->unsignedSmallInteger('terpakai')->default(0);
            $table->unsignedSmallInteger('sisa')->default(12);
            $table->timestamps();

            $table->unique(['employee_id', 'tahun']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_approvals');
        Schema::dropIfExists('leave_requests');
    }
};
