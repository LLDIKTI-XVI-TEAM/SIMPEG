<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Menyimpan identitas dan generasi delivery agar callback terminal hanya menutup pemilik saat ini. */
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->string('processing_delivery_id', 64)->nullable()->after('processing_token');
            $table->unsignedInteger('processing_attempt')->nullable()->after('processing_delivery_id');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropColumn(['processing_delivery_id', 'processing_attempt']);
        });
    }
};
