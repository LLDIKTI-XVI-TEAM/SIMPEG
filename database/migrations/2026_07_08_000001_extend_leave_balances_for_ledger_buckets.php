<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_balances', function (Blueprint $table): void {
            $table->unsignedSmallInteger('sisa_n2')->default(0)->after('sisa');
            $table->unsignedSmallInteger('sisa_n1')->default(0)->after('sisa_n2');
            $table->unsignedSmallInteger('sisa_tahun_berjalan')->default(0)->after('sisa_n1');
            $table->unsignedSmallInteger('terpakai_tahun_berjalan')->default(0)->after('sisa_tahun_berjalan');
            $table->unsignedSmallInteger('hangus')->default(0)->after('terpakai_tahun_berjalan');
        });
    }

    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table): void {
            $table->dropColumn([
                'sisa_n2',
                'sisa_n1',
                'sisa_tahun_berjalan',
                'terpakai_tahun_berjalan',
                'hangus',
            ]);
        });
    }
};
