<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 16.1 ref_golongan
        Schema::create('ref_golongan', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kode', 10)->unique();
            $table->string('nama', 100);
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();
        });

        // 16.2 ref_jenis_jabatan
        Schema::create('ref_jenis_jabatan', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nama', 100);
            $table->unsignedSmallInteger('maks_usia_pensiun');
            $table->string('catatan', 255)->nullable();
            $table->timestamps();
        });

        // 16.3 ref_eselon
        Schema::create('ref_eselon', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kode', 10)->unique();
            $table->string('nama', 50);
            $table->timestamps();
        });

        // 16.4 ref_jenis_cuti
        Schema::create('ref_jenis_cuti', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nama', 100);
            $table->boolean('khusus_pns')->default(false);
            $table->timestamps();
        });

        // 16.5 ref_agama
        Schema::create('ref_agama', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nama', 50);
            $table->timestamps();
        });

        // 16.6 ref_jenis_kelamin
        Schema::create('ref_jenis_kelamin', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kode', 2)->unique();
            $table->string('nama', 20);
            $table->timestamps();
        });

        // 16.7 ref_status_perkawinan
        Schema::create('ref_status_perkawinan', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nama', 50);
            $table->timestamps();
        });

        // 16.8 ref_jenjang_pendidikan
        Schema::create('ref_jenjang_pendidikan', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nama', 50);
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();
        });

        // 16.9 ref_jenis_pegawai
        Schema::create('ref_jenis_pegawai', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nama', 50)->unique();
            $table->timestamps();
        });

        // 16.10 ref_unit_kerja
        Schema::create('ref_unit_kerja', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nama', 100);
            $table->string('keterangan', 255)->nullable();
            $table->timestamps();
        });

        // 16.11 ref_hari_libur
        Schema::create('ref_hari_libur', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('tanggal');
            $table->string('nama', 100);
            $table->year('tahun');
            $table->boolean('is_cuti_bersama')->default(false);
            $table->timestamps();

            $table->unique(['tanggal']);
        });

        // 16.12 ref_bup (Batas Usia Pensiun)
        Schema::create('ref_bup', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('jenis_jabatan', 100);
            $table->unsignedSmallInteger('bup_tahun');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_bup');
        Schema::dropIfExists('ref_hari_libur');
        Schema::dropIfExists('ref_unit_kerja');
        Schema::dropIfExists('ref_jenis_pegawai');
        Schema::dropIfExists('ref_jenjang_pendidikan');
        Schema::dropIfExists('ref_status_perkawinan');
        Schema::dropIfExists('ref_jenis_kelamin');
        Schema::dropIfExists('ref_agama');
        Schema::dropIfExists('ref_jenis_cuti');
        Schema::dropIfExists('ref_eselon');
        Schema::dropIfExists('ref_jenis_jabatan');
        Schema::dropIfExists('ref_golongan');
    }
};
