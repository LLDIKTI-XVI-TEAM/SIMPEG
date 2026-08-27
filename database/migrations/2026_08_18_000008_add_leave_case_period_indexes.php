<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REQUEST_INDEX = 'leave_requests_case_status_period_index';

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // IF NOT EXISTS menjaga deployment ulang tetap aman ketika operator
            // telah membuat index ekuivalen dengan nama resmi secara manual.
            DB::statement('CREATE INDEX IF NOT EXISTS '.self::REQUEST_INDEX.' ON leave_requests (leave_request_case_id, status, tanggal_mulai, tanggal_selesai)');

            return;
        }

        if (! Schema::hasIndex('leave_requests', self::REQUEST_INDEX)) {
            Schema::table('leave_requests', function (Blueprint $table): void {
                $table->index(
                    ['leave_request_case_id', 'status', 'tanggal_mulai', 'tanggal_selesai'],
                    self::REQUEST_INDEX,
                );
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.self::REQUEST_INDEX);

            return;
        }

        if (Schema::hasIndex('leave_requests', self::REQUEST_INDEX)) {
            Schema::table('leave_requests', function (Blueprint $table): void {
                $table->dropIndex(self::REQUEST_INDEX);
            });
        }
    }
};
