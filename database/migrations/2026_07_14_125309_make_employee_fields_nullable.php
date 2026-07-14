<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('nama_lengkap', 255)->nullable()->change();
            $table->string('nip', 18)->nullable()->change();
            $table->date('tanggal_lahir')->nullable()->change();
            $table->uuid('jenis_pegawai_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Reverting back to non-nullable (might fail if there are nulls)
            $table->string('nama_lengkap', 255)->nullable(false)->change();
            $table->string('nip', 18)->nullable(false)->change();
            $table->date('tanggal_lahir')->nullable(false)->change();
            $table->uuid('jenis_pegawai_id')->nullable(false)->change();
        });
    }
};
