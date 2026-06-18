<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('nama_pegawai');
            $table->string('email_pegawai')->nullable()->unique();
            $table->string('golongan', 50)->nullable();
            $table->string('jabatan')->nullable();
            $table->string('kelas_jabatan', 50)->nullable();
            $table->string('nip', 50)->nullable()->unique();
            $table->string('nomor_telepon', 30)->nullable();
            $table->string('pangkat', 100)->nullable();
            $table->string('pendidikan_terakhir', 100)->nullable();
            $table->date('pensiun')->nullable();
            $table->string('person')->nullable();
            $table->string('person_formula')->nullable();
            $table->string('prodi_pendidikan_terakhir')->nullable();
            $table->string('status_kepegawaian', 100)->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('golongan');
            $table->index('jabatan');
            $table->index('status_kepegawaian');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
