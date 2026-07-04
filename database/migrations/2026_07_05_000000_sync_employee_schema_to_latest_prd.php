<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->createReferenceTables();
        $this->seedMinimalReferences();
        $this->syncEmployeeColumns();
        $this->syncPositionHistoryColumns();
        $this->syncSupervisorAssignmentColumns();
        $this->backfillEmployeeContract();
        $this->backfillPositionContract();
        $this->backfillSupervisorContract();
    }

    public function down(): void
    {
        if (Schema::hasTable('supervisor_assignments') && Schema::hasColumn('supervisor_assignments', 'kepala_bagian_id')) {
            Schema::table('supervisor_assignments', function (Blueprint $table): void {
                $table->dropForeign(['kepala_bagian_id']);
                $table->dropColumn('kepala_bagian_id');
            });
        }

        if (Schema::hasTable('position_histories')) {
            Schema::table('position_histories', function (Blueprint $table): void {
                if (Schema::hasColumn('position_histories', 'jabatan_id')) {
                    $table->dropForeign(['jabatan_id']);
                    $table->dropColumn('jabatan_id');
                }

                if (Schema::hasColumn('position_histories', 'kelas_jabatan')) {
                    $table->dropColumn('kelas_jabatan');
                }
            });
        }

        if (Schema::hasTable('employees')) {
            Schema::table('employees', function (Blueprint $table): void {
                if (Schema::hasColumn('employees', 'status_pegawai_id')) {
                    $table->dropForeign(['status_pegawai_id']);
                    $table->dropColumn('status_pegawai_id');
                }

                if (Schema::hasColumn('employees', 'kepala_bagian_id')) {
                    $table->dropForeign(['kepala_bagian_id']);
                    $table->dropColumn('kepala_bagian_id');
                }

                foreach (['email_pribadi', 'status_keterangan', 'kelas_jabatan_terakhir'] as $column) {
                    if (Schema::hasColumn('employees', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('ref_jabatan');
        Schema::dropIfExists('ref_status_pegawai');
    }

    private function createReferenceTables(): void
    {
        if (! Schema::hasTable('ref_status_pegawai')) {
            Schema::create('ref_status_pegawai', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('nama', 50)->unique();
                $table->string('keterangan', 255)->nullable();
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ref_jabatan')) {
            Schema::create('ref_jabatan', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('nama', 255)->unique();
                $table->foreignUuid('jenis_jabatan_id')
                    ->nullable()
                    ->constrained('ref_jenis_jabatan')
                    ->nullOnDelete();
                $table->foreignUuid('eselon_id')
                    ->nullable()
                    ->constrained('ref_eselon')
                    ->nullOnDelete();
                $table->string('keterangan', 255)->nullable();
                $table->timestamps();
            });
        }
    }

    private function seedMinimalReferences(): void
    {
        $now = now();
        $statuses = [
            ['nama' => 'Aktif', 'keterangan' => 'Pegawai aktif', 'is_default' => true],
            ['nama' => 'Non-Aktif', 'keterangan' => 'Pegawai nonaktif sementara', 'is_default' => false],
            ['nama' => 'Pensiun', 'keterangan' => 'Pegawai pensiun', 'is_default' => false],
            ['nama' => 'Mutasi', 'keterangan' => 'Pegawai mutasi keluar', 'is_default' => false],
        ];

        foreach ($statuses as $status) {
            $exists = DB::table('ref_status_pegawai')->where('nama', $status['nama'])->exists();

            if (! $exists) {
                DB::table('ref_status_pegawai')->insert([
                    'id' => (string) Str::uuid(),
                    ...$status,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function syncEmployeeColumns(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'email_pribadi')) {
                $table->string('email_pribadi', 255)->nullable()->after('email');
            }

            if (! Schema::hasColumn('employees', 'status_pegawai_id')) {
                $table->foreignUuid('status_pegawai_id')
                    ->nullable()
                    ->after('status_aktif')
                    ->constrained('ref_status_pegawai')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('employees', 'status_keterangan')) {
                $table->text('status_keterangan')->nullable()->after('status_pegawai_id');
            }

            if (! Schema::hasColumn('employees', 'kepala_bagian_id')) {
                $table->foreignUuid('kepala_bagian_id')
                    ->nullable()
                    ->after('status_keterangan')
                    ->constrained('employees')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('employees', 'kelas_jabatan_terakhir')) {
                $table->string('kelas_jabatan_terakhir', 10)->nullable()->after('kelas_jabatan');
            }
        });
    }

    private function syncPositionHistoryColumns(): void
    {
        Schema::table('position_histories', function (Blueprint $table): void {
            if (! Schema::hasColumn('position_histories', 'jabatan_id')) {
                $table->foreignUuid('jabatan_id')
                    ->nullable()
                    ->after('employee_id')
                    ->constrained('ref_jabatan')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('position_histories', 'kelas_jabatan')) {
                $table->string('kelas_jabatan', 10)->nullable()->after('unit_kerja_id');
            }
        });
    }

    private function syncSupervisorAssignmentColumns(): void
    {
        Schema::table('supervisor_assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('supervisor_assignments', 'kepala_bagian_id')) {
                $table->foreignUuid('kepala_bagian_id')
                    ->nullable()
                    ->after('supervisor_id')
                    ->constrained('employees')
                    ->nullOnDelete();
            }
        });
    }

    private function backfillEmployeeContract(): void
    {
        if (Schema::hasColumn('employees', 'email') && Schema::hasColumn('employees', 'email_pribadi')) {
            DB::statement('UPDATE employees SET email_pribadi = email WHERE email_pribadi IS NULL AND email IS NOT NULL');
        }

        if (Schema::hasColumn('employees', 'kelas_jabatan') && Schema::hasColumn('employees', 'kelas_jabatan_terakhir')) {
            DB::statement('UPDATE employees SET kelas_jabatan_terakhir = kelas_jabatan WHERE kelas_jabatan_terakhir IS NULL AND kelas_jabatan IS NOT NULL');
        }

        if (Schema::hasColumn('employees', 'atasan_langsung_id') && Schema::hasColumn('employees', 'kepala_bagian_id')) {
            DB::statement('UPDATE employees SET kepala_bagian_id = atasan_langsung_id WHERE kepala_bagian_id IS NULL AND atasan_langsung_id IS NOT NULL');
        }

        if (Schema::hasColumn('employees', 'status_aktif') && Schema::hasColumn('employees', 'status_pegawai_id')) {
            foreach (DB::table('ref_status_pegawai')->pluck('id', 'nama') as $nama => $id) {
                DB::table('employees')
                    ->where('status_aktif', $nama)
                    ->whereNull('status_pegawai_id')
                    ->update(['status_pegawai_id' => $id]);
            }

            $aktifId = DB::table('ref_status_pegawai')->where('nama', 'Aktif')->value('id');
            if ($aktifId !== null) {
                DB::table('employees')->whereNull('status_pegawai_id')->update(['status_pegawai_id' => $aktifId]);
            }
        }
    }

    private function backfillPositionContract(): void
    {
        if (! Schema::hasColumn('position_histories', 'nama_jabatan') || ! Schema::hasColumn('position_histories', 'jabatan_id')) {
            return;
        }

        $positions = DB::table('position_histories')
            ->select('nama_jabatan', 'jenis_jabatan_id', 'eselon_id')
            ->whereNotNull('nama_jabatan')
            ->distinct()
            ->get();

        foreach ($positions as $position) {
            $jabatanId = DB::table('ref_jabatan')->where('nama', $position->nama_jabatan)->value('id');

            if ($jabatanId === null) {
                $jabatanId = (string) Str::uuid();
                DB::table('ref_jabatan')->insert([
                    'id' => $jabatanId,
                    'nama' => $position->nama_jabatan,
                    'jenis_jabatan_id' => $position->jenis_jabatan_id,
                    'eselon_id' => $position->eselon_id,
                    'keterangan' => 'Dibuat otomatis dari riwayat jabatan lama.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('position_histories')
                ->where('nama_jabatan', $position->nama_jabatan)
                ->whereNull('jabatan_id')
                ->update(['jabatan_id' => $jabatanId]);
        }

        if (Schema::hasColumn('position_histories', 'kelas_jabatan')) {
            DB::statement('UPDATE position_histories SET kelas_jabatan = (SELECT employees.kelas_jabatan_terakhir FROM employees WHERE employees.id = position_histories.employee_id) WHERE kelas_jabatan IS NULL');
        }
    }

    private function backfillSupervisorContract(): void
    {
        if (Schema::hasColumn('supervisor_assignments', 'supervisor_id') && Schema::hasColumn('supervisor_assignments', 'kepala_bagian_id')) {
            DB::statement('UPDATE supervisor_assignments SET kepala_bagian_id = supervisor_id WHERE kepala_bagian_id IS NULL AND supervisor_id IS NOT NULL');
        }
    }
};
