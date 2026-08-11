<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menggabungkan field status_alasan dan status_deskripsi menjadi status_keterangan
     * di tabel employees, dan field alasan + deskripsi menjadi keterangan di
     * employee_status_histories.
     */
    public function up(): void
    {
        // 1. Update employees table
        // Check jika kolom belum ada, baru tambahkan
        if (! Schema::hasColumn('employees', 'status_keterangan')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->text('status_keterangan')->nullable()->after('status_aktif');
            });
        }

        // Gabungkan data alasan dan deskripsi ke keterangan (jika kolom masih ada)
        if (Schema::hasColumn('employees', 'status_alasan')) {
            // Gunakan Eloquent untuk database-agnostic migration
            DB::table('employees')
                ->whereNotNull('status_alasan')
                ->orderBy('id')
                ->chunk(100, function ($employees) {
                    foreach ($employees as $employee) {
                        $keterangan = $employee->status_alasan;
                        if (! empty($employee->status_deskripsi)) {
                            $keterangan .= "\n".$employee->status_deskripsi;
                        }

                        DB::table('employees')
                            ->where('id', $employee->id)
                            ->update(['status_keterangan' => $keterangan]);
                    }
                });

            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn(['status_alasan', 'status_deskripsi']);
            });
        }

        // 2. Update employee_status_histories table
        if (! Schema::hasColumn('employee_status_histories', 'keterangan')) {
            Schema::table('employee_status_histories', function (Blueprint $table) {
                $table->text('keterangan')->nullable()->after('status_nama');
            });
        }

        // Gabungkan data alasan dan deskripsi ke keterangan
        if (Schema::hasColumn('employee_status_histories', 'alasan')) {
            // Gunakan Eloquent untuk database-agnostic migration
            DB::table('employee_status_histories')
                ->whereNotNull('alasan')
                ->orderBy('id')
                ->chunk(100, function ($histories) {
                    foreach ($histories as $history) {
                        $keterangan = $history->alasan;
                        if (! empty($history->deskripsi)) {
                            $keterangan .= "\n".$history->deskripsi;
                        }

                        DB::table('employee_status_histories')
                            ->where('id', $history->id)
                            ->update(['keterangan' => $keterangan]);
                    }
                });

            Schema::table('employee_status_histories', function (Blueprint $table) {
                $table->dropColumn(['alasan', 'deskripsi']);
            });
        }
    }

    /**
     * Rollback: pisahkan kembali keterangan menjadi alasan dan deskripsi.
     */
    public function down(): void
    {
        // 1. Restore employees table
        Schema::table('employees', function (Blueprint $table) {
            $table->string('status_alasan')->nullable()->after('status_aktif');
            $table->text('status_deskripsi')->nullable()->after('status_alasan');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Pisahkan keterangan kembali (ambil sebelum " - " sebagai alasan, sisanya deskripsi)
            DB::statement("
                UPDATE employees
                SET status_alasan = CASE
                    WHEN status_keterangan LIKE '% - %'
                    THEN SUBSTRING(status_keterangan, 1, POSITION(' - ' IN status_keterangan) - 1)
                    ELSE status_keterangan
                END,
                status_deskripsi = CASE
                    WHEN status_keterangan LIKE '% - %'
                    THEN SUBSTRING(status_keterangan, POSITION(' - ' IN status_keterangan) + 3)
                    ELSE NULL
                END
                WHERE status_keterangan IS NOT NULL
            ");
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('status_keterangan');
        });

        // 2. Restore employee_status_histories table
        Schema::table('employee_status_histories', function (Blueprint $table) {
            $table->string('alasan', 255)->nullable()->after('status_nama');
            $table->text('deskripsi')->nullable()->after('alasan');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                UPDATE employee_status_histories
                SET alasan = CASE
                    WHEN keterangan LIKE '% - %'
                    THEN SUBSTRING(keterangan, 1, POSITION(' - ' IN keterangan) - 1)
                    ELSE keterangan
                END,
                deskripsi = CASE
                    WHEN keterangan LIKE '% - %'
                    THEN SUBSTRING(keterangan, POSITION(' - ' IN keterangan) + 3)
                    ELSE NULL
                END
                WHERE keterangan IS NOT NULL
            ");
        }

        Schema::table('employee_status_histories', function (Blueprint $table) {
            $table->dropColumn('keterangan');
        });
    }
};
