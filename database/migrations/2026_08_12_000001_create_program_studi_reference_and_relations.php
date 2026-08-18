<?php

use App\Support\ProgramStudi\BackfillProgramStudiReferences;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_program_studi', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nama', 255)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignUuid('program_studi_id')->nullable()->constrained('ref_program_studi')->nullOnDelete();
        });
        Schema::table('education_histories', function (Blueprint $table): void {
            $table->foreignUuid('program_studi_id')->nullable()->constrained('ref_program_studi')->nullOnDelete();
        });

        // Data lama disalin ke referensi agar pilihan prodi pada pegawai dan
        // riwayat pendidikan tetap terhubung setelah migrasi dijalankan.
        app(BackfillProgramStudiReferences::class)->execute();
    }

    public function down(): void
    {
        Schema::table('education_histories', function (Blueprint $table): void {
            $table->dropForeign(['program_studi_id']);
            $table->dropColumn('program_studi_id');
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropForeign(['program_studi_id']);
            $table->dropColumn('program_studi_id');
        });
        Schema::dropIfExists('ref_program_studi');
    }
};
