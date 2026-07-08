<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ews_alerts DROP CONSTRAINT IF EXISTS ews_alerts_type_check');
            DB::statement("ALTER TABLE ews_alerts ADD CONSTRAINT ews_alerts_type_check CHECK (type IN ('KENAIKAN_PANGKAT', 'KGB', 'PENSIUN', 'KONTRAK_PPPK', 'SATYALANCANA'))");
        }

        Schema::table('ews_alerts', function (Blueprint $table): void {
            $table->string('followup_status', 20)->default('aktif')->after('is_processed');
            $table->timestamp('handled_at')->nullable()->after('followup_status');
            $table->foreignUuid('handled_by')->nullable()->after('handled_at')->constrained('users')->nullOnDelete();
            $table->text('handled_note')->nullable()->after('handled_by');
            $table->index('followup_status');
        });

        DB::table('ews_alerts')
            ->where('is_processed', true)
            ->update(['followup_status' => 'kedaluwarsa']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ews_alerts ADD CONSTRAINT ews_alerts_followup_status_check CHECK (followup_status IN ('aktif', 'ditangani', 'tidak_perlu', 'kedaluwarsa'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ews_alerts DROP CONSTRAINT IF EXISTS ews_alerts_followup_status_check');
            DB::statement('ALTER TABLE ews_alerts DROP CONSTRAINT IF EXISTS ews_alerts_type_check');
            DB::statement("ALTER TABLE ews_alerts ADD CONSTRAINT ews_alerts_type_check CHECK (type IN ('KENAIKAN_PANGKAT', 'KGB', 'PENSIUN', 'KONTRAK_PPPK'))");
        }

        Schema::table('ews_alerts', function (Blueprint $table): void {
            $table->dropForeign(['handled_by']);
            $table->dropIndex(['followup_status']);
            $table->dropColumn(['followup_status', 'handled_at', 'handled_by', 'handled_note']);
        });
    }
};
