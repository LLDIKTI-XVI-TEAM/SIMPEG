<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // --- Data Pribadi (PRD §7.3) ---
            $table->string('nama_lengkap', 255);
            $table->string('nip', 18)->unique();
            $table->text('nik')->nullable()->comment('Encrypted NIK KTP');
            $table->text('no_kk')->nullable()->comment('Encrypted No. KK');
            $table->string('tempat_lahir', 100)->nullable();
            $table->date('tanggal_lahir');
            $table->enum('jenis_kelamin', ['L', 'P'])->nullable();
            $table->foreignUuid('agama_id')->nullable()->constrained('ref_agama')->nullOnDelete();
            $table->foreignUuid('status_kawin_id')->nullable()->constrained('ref_status_perkawinan')->nullOnDelete();
            $table->enum('golongan_darah', ['A', 'B', 'AB', 'O'])->nullable();
            $table->string('foto', 255)->nullable();
            $table->foreignUuid('jenis_pegawai_id')->constrained('ref_jenis_pegawai')->restrictOnDelete();
            $table->enum('status_aktif', ['Aktif', 'Non-Aktif', 'Pensiun', 'Mutasi'])->default('Aktif');

            // --- Snapshot fields (dari import Excel) ---
            $table->string('golongan_terakhir', 20)->nullable();
            $table->string('pangkat_terakhir', 100)->nullable();
            $table->string('jabatan_terakhir', 255)->nullable();
            $table->string('kelas_jabatan', 10)->nullable();

            // --- Pendidikan snapshot ---
            $table->string('pendidikan_terakhir', 20)->nullable();
            $table->string('prodi_pendidikan_terakhir', 255)->nullable();

            // --- Pensiun ---
            $table->date('tanggal_pensiun')->nullable();

            // --- Profil status ---
            $table->enum('profil_status', ['belum_lengkap', 'lengkap'])->default('belum_lengkap');

            // --- Data Kontak (PRD §7.3) ---
            $table->text('alamat')->nullable();
            $table->string('no_hp', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('no_telepon_rumah', 20)->nullable();

            // --- Flags ---
            $table->boolean('is_kinerja_baik')->default(true);

            // --- SSO & RBAC ---
            $table->string('keycloak_id', 255)->nullable()->unique();
            $table->string('role', 50)->default('pegawai');

            // --- Soft delete & timestamps ---
            $table->softDeletes();
            $table->timestamps();

            // --- Indexes ---
            $table->index('status_aktif');
            $table->index('golongan_terakhir');
            $table->index('tanggal_lahir');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
