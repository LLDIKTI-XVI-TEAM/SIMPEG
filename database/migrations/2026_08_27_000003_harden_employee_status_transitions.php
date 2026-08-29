<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_UNIQUE = 'employee_status_transitions_employee_id_status_pegawai_id_tanggal_efektif_unique';

    private const PENDING_UNIQUE = 'employee_status_transitions_employee_date_pending_unique';

    /**
     * Memperketat jadwal existing tanpa memilih atau menghapus konflik secara otomatis.
     * Database dengan lebih dari satu jadwal pending per pegawai/tanggal harus diperbaiki
     * secara administratif sebelum migration dapat dilanjutkan.
     */
    public function up(): void
    {
        $duplicate = DB::table('employee_status_transitions')
            ->select('employee_id', 'tanggal_efektif')
            ->selectRaw('COUNT(*) AS aggregate')
            ->where('is_applied', false)
            ->groupBy('employee_id', 'tanggal_efektif')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('employee_id')
            ->orderBy('tanggal_efektif')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                'Hardening employee_status_transitions dihentikan: ditemukan jadwal pending duplikat untuk employee_id '
                .$duplicate->employee_id.' pada '.$duplicate->tanggal_efektif.'.',
            );
        }

        Schema::table('employee_status_transitions', function (Blueprint $table): void {
            $table->foreignUuid('document_id')
                ->nullable()
                ->after('status_note')
                ->constrained('documents')
                ->restrictOnDelete();

            $table->dropUnique(self::OLD_UNIQUE);
        });

        // Partial unique hanya membatasi jadwal pending; histori jadwal applied tetap utuh.
        DB::statement(
            'CREATE UNIQUE INDEX '.self::PENDING_UNIQUE
            .' ON employee_status_transitions (employee_id, tanggal_efektif)'
            .' WHERE is_applied = false',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::PENDING_UNIQUE);

        Schema::table('employee_status_transitions', function (Blueprint $table): void {
            $table->unique(
                ['employee_id', 'status_pegawai_id', 'tanggal_efektif'],
                self::OLD_UNIQUE,
            );
            $table->dropConstrainedForeignId('document_id');
        });
    }
};
