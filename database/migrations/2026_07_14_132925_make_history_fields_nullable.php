<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->date('tmt_pengangkatan')->nullable()->change();
            $table->string('no_sk', 100)->nullable()->change();
            $table->date('tanggal_sk')->nullable()->change();
        });

        Schema::table('rank_histories', function (Blueprint $table) {
            $table->uuid('golongan_id')->nullable()->change();
            $table->date('tmt_pangkat')->nullable()->change();
            $table->string('no_sk', 100)->nullable()->change();
            $table->date('tanggal_sk')->nullable()->change();
        });

        Schema::table('position_histories', function (Blueprint $table) {
            $table->string('nama_jabatan', 255)->nullable()->change();
            $table->uuid('jenis_jabatan_id')->nullable()->change();
            $table->uuid('unit_kerja_id')->nullable()->change();
            $table->date('tmt_jabatan')->nullable()->change();
            $table->string('no_sk', 100)->nullable()->change();
            $table->date('tanggal_sk')->nullable()->change();
        });

        Schema::table('salary_histories', function (Blueprint $table) {
            $table->date('tmt_kgb')->nullable()->change();
            $table->decimal('gaji_pokok', 15, 2)->nullable()->change();
            $table->string('no_sk', 100)->nullable()->change();
            $table->date('tanggal_sk')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverting all back to non-nullable might fail if there are nulls.
    }
};
