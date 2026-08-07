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
        if (! Schema::hasColumn('employees', 'status_alasan')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('status_alasan')->nullable()->after('status_aktif');
            });
        }

        if (! Schema::hasColumn('employees', 'status_deskripsi')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->text('status_deskripsi')->nullable()->after('status_alasan');
            });
        }

        $this->restoreKeterangan('employees', 'status_keterangan', 'status_alasan', 'status_deskripsi');

        // Kolom ini dibuat migration skema pegawai sebelumnya, sehingga tidak boleh dihapus di sini.
        if (! Schema::hasColumn('employee_status_histories', 'alasan')) {
            Schema::table('employee_status_histories', function (Blueprint $table) {
                $table->string('alasan', 255)->nullable()->after('status_nama');
            });
        }

        if (! Schema::hasColumn('employee_status_histories', 'deskripsi')) {
            Schema::table('employee_status_histories', function (Blueprint $table) {
                $table->text('deskripsi')->nullable()->after('alasan');
            });
        }

        $this->restoreKeterangan('employee_status_histories', 'keterangan', 'alasan', 'deskripsi');

        if (Schema::hasColumn('employee_status_histories', 'keterangan')) {
            Schema::table('employee_status_histories', function (Blueprint $table) {
                $table->dropColumn('keterangan');
            });
        }
    }

    /**
     * Mengembalikan nilai gabungan tanpa SQL khusus PostgreSQL agar rollback dapat diuji di SQLite.
     */
    private function restoreKeterangan(string $table, string $source, string $alasan, string $deskripsi): void
    {
        if (! Schema::hasColumn($table, $source)) {
            return;
        }

        DB::table($table)
            ->whereNotNull($source)
            ->orderBy('id')
            ->chunk(100, function ($records) use ($table, $source, $alasan, $deskripsi) {
                foreach ($records as $record) {
                    [$nilaiAlasan, $nilaiDeskripsi] = $this->splitKeterangan($record->{$source});

                    DB::table($table)
                        ->where('id', $record->id)
                        ->update([
                            $alasan => $nilaiAlasan,
                            $deskripsi => $nilaiDeskripsi,
                        ]);
                }
            });
    }

    /**
     * Data lama memakai pemisah baris; pemisah " - " tetap didukung untuk rollback data historis.
     *
     * @return array{0: string, 1: string|null}
     */
    private function splitKeterangan(string $keterangan): array
    {
        $normalized = str_replace("\r\n", "\n", $keterangan);
        $separator = str_contains($normalized, "\n") ? "\n" : ' - ';
        [$alasan, $deskripsi] = array_pad(explode($separator, $normalized, 2), 2, null);

        return [$alasan, $deskripsi];
    }
};
