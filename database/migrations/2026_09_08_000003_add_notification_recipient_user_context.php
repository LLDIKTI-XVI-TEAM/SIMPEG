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
        $this->backfillUnambiguousRecipients();
    }

    /**
     * Backfill recipient lintas driver database. UPDATE...FROM hanya valid di
     * PostgreSQL; MySQL memakai UPDATE...JOIN dan driver lain memakai loop
     * query-builder agar migrate tidak berhenti di tengah pada deployment
     * MySQL/MariaDB/SQLite yang didukung konfigurasi aplikasi.
     */
    private function backfillUnambiguousRecipients(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                WITH unambiguous_employees AS (
                    SELECT employee_id
                    FROM users
                    WHERE employee_id IS NOT NULL
                    GROUP BY employee_id
                    HAVING COUNT(*) = 1
                )
                UPDATE notifications AS notification
                SET recipient_user_id = user_account.id
                FROM users AS user_account
                INNER JOIN unambiguous_employees
                    ON unambiguous_employees.employee_id = user_account.employee_id
                WHERE notification.user_id = user_account.employee_id
                  AND notification.recipient_user_id IS NULL
            SQL);

            return;
        }

        if ($driver === 'mysql') {
            DB::statement(<<<'SQL'
                UPDATE notifications AS notification
                INNER JOIN users AS user_account
                    ON notification.user_id = user_account.employee_id
                INNER JOIN (
                    SELECT employee_id
                    FROM users
                    WHERE employee_id IS NOT NULL
                    GROUP BY employee_id
                    HAVING COUNT(*) = 1
                ) AS unambiguous_employees
                    ON unambiguous_employees.employee_id = user_account.employee_id
                SET notification.recipient_user_id = user_account.id
                WHERE notification.recipient_user_id IS NULL
            SQL);

            return;
        }

        DB::table('users')
            ->select('employee_id')
            ->whereNotNull('employee_id')
            ->groupBy('employee_id')
            ->havingRaw('COUNT(*) = 1')
            ->orderBy('employee_id')
            ->chunk(500, function ($rows): void {
                $userIds = DB::table('users')
                    ->whereIn('employee_id', $rows->pluck('employee_id')->all())
                    ->pluck('id', 'employee_id');

                foreach ($userIds as $employeeId => $userId) {
                    DB::table('notifications')
                        ->where('user_id', $employeeId)
                        ->whereNull('recipient_user_id')
                        ->update(['recipient_user_id' => $userId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_recipient_read_index');
            $table->dropConstrainedForeignId('recipient_user_id');
        });
    }
};
