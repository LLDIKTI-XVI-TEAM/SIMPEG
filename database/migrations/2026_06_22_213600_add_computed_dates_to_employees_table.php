<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->date('tanggal_kenaikan_pangkat_berikutnya')->nullable()->after('tanggal_pensiun');
            $table->date('tanggal_kgb_berikutnya')->nullable()->after('tanggal_kenaikan_pangkat_berikutnya');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn([
                'tanggal_kenaikan_pangkat_berikutnya',
                'tanggal_kgb_berikutnya',
            ]);
        });
    }
};
