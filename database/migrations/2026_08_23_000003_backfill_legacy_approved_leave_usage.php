<?php

use App\Support\Cuti\BackfillLegacyApprovedLeaveUsage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Cutover DB-only memastikan upgrade tidak meninggalkan request final tanpa fakta pemakaian.
        app(BackfillLegacyApprovedLeaveUsage::class)->execute();
    }

    public function down(): void
    {
        $requiredTables = ['leave_usage_records', 'leave_balance_ledger', 'audit_logs'];

        // State schema yang tidak lengkap tidak boleh dianggap aman untuk rollback lanjutan.
        if (collect($requiredTables)->contains(fn (string $table): bool => ! Schema::hasTable($table))) {
            throw new RuntimeException('Migration backfill fakta approved legacy tidak dapat di-rollback karena schema histori tidak lengkap.');
        }

        // Barrier berhenti sebelum migration lama menjatuhkan histori; database disposable kosong tetap dapat dibersihkan.
        if (DB::table('leave_usage_records')->exists()
            || DB::table('leave_balance_ledger')->exists()
            || DB::table('audit_logs')->exists()) {
            throw new RuntimeException(
                'Migration backfill fakta approved legacy tidak dapat di-rollback selama fakta, ledger, atau audit historis masih tersimpan.',
            );
        }
    }
};
