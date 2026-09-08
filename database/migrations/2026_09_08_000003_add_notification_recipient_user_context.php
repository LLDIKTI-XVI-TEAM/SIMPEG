<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            // user_id lama adalah FK ke employees. Kolom ini dipertahankan sebagai
            // konteks fakta domain; penerima inbox disimpan eksplisit sebagai User.
            $table->foreignUuid('recipient_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->index(['recipient_user_id', 'is_read'], 'notifications_recipient_read_index');
        });

        // Data lama hanya dipindahkan ketika satu pegawai memiliki tepat satu User.
        // Mapping ambigu sengaja dibiarkan null agar tidak memberikan inbox seseorang
        // kepada akun yang dipilih secara arbitrer.
        DB::statement(<<<'SQL'
            WITH unambiguous_users AS (
                SELECT employee_id, MIN(id) AS id
                FROM users
                WHERE employee_id IS NOT NULL
                GROUP BY employee_id
                HAVING COUNT(*) = 1
            )
            UPDATE notifications
            SET recipient_user_id = unambiguous_users.id
            FROM unambiguous_users
            WHERE notifications.user_id = unambiguous_users.employee_id
              AND notifications.recipient_user_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_recipient_read_index');
            $table->dropConstrainedForeignId('recipient_user_id');
        });
    }
};
