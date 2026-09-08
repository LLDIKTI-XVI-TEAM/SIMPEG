<?php

use Illuminate\Database\Migrations\Migration;
return new class extends Migration
{
    public function up(): void
    {
        // users.switch_role adalah capability RBAC yang dapat didelegasikan.
        // Target simulasi tetap dibatasi oleh hierarchy di model User, sehingga
        // migration tidak boleh mencabut keputusan grant operator berdasarkan
        // role historis. Tidak ada perubahan data yang diperlukan di sini.
    }

    public function down(): void
    {
        // Tidak ada perubahan data untuk diputar balik.
    }
};
