<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DropSoftDeletesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_backfills_soft_deleted_records_to_nonaktif_before_dropping_column(): void
    {
        // Pasang kembali kolom deleted_at secara sementara untuk menyimulasikan state pra-migrasi
        if (! Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        $nonaktifId = DB::table('ref_status_pegawai')->where('kode', 'NONAKTIF')->value('id');
        $aktifId = DB::table('ref_status_pegawai')->where('kode', 'AKTIF')->value('id');

        $empActiveId = (string) Str::uuid();
        $empSoftDeletedId = (string) Str::uuid();

        DB::table('employees')->insert([
            'id' => $empActiveId,
            'nip' => '199001012020011001',
            'nama_lengkap' => 'Pegawai Masih Aktif',
            'status_pegawai_id' => $aktifId,
            'status_aktif' => 'Aktif',
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employees')->insert([
            'id' => $empSoftDeletedId,
            'nip' => '199001012020011002',
            'nama_lengkap' => 'Pegawai Dihapus Lama',
            'status_pegawai_id' => $aktifId,
            'status_aktif' => 'Aktif',
            'deleted_at' => now()->subMonths(2),
            'created_at' => now()->subYear(),
            'updated_at' => now()->subMonths(2),
        ]);

        $migration = require database_path('migrations/2026_08_20_235200_drop_soft_deletes_from_employees_table.php');
        $this->assertInstanceOf(Migration::class, $migration);

        // Jalankan up() migration
        call_user_func([$migration, 'up']);

        // Kolom deleted_at sudah terhapus
        $this->assertFalse(Schema::hasColumn('employees', 'deleted_at'));

        // Pegawai soft-deleted berubah menjadi Non-Aktif dengan status_pegawai_id NONAKTIF
        $softDeletedEmployee = DB::table('employees')->where('id', $empSoftDeletedId)->first();
        $this->assertNotNull($softDeletedEmployee);
        $this->assertSame('Non-Aktif', $softDeletedEmployee->status_aktif);
        $this->assertSame($nonaktifId, $softDeletedEmployee->status_pegawai_id);

        // Pegawai aktif tidak tersentuh
        $activeEmployee = DB::table('employees')->where('id', $empActiveId)->first();
        $this->assertNotNull($activeEmployee);
        $this->assertSame('Aktif', $activeEmployee->status_aktif);
        $this->assertSame($aktifId, $activeEmployee->status_pegawai_id);
    }

    public function test_migration_mempertahankan_status_nonaktif_spesifik_pada_pegawai_soft_deleted(): void
    {
        if (! Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        $pensiunId = DB::table('ref_status_pegawai')->where('kode', 'PENSIUN')->value('id');
        $employeeId = (string) Str::uuid();
        $historyId = (string) Str::uuid();
        $deletedAt = now()->subMonths(2)->toDateTimeString();

        DB::table('employees')->insert([
            'id' => $employeeId,
            'nip' => '199001012020011008',
            'nama_lengkap' => 'Pegawai Pensiun Legacy',
            'status_pegawai_id' => $pensiunId,
            'status_aktif' => 'Pensiun',
            'status_tanggal' => '2025-12-01',
            'status_keterangan' => 'Pensiun sebelum soft delete.',
            'deleted_at' => $deletedAt,
            'created_at' => now()->subYear(),
            'updated_at' => now()->subMonths(2),
        ]);
        DB::table('employee_status_histories')->insert([
            'id' => $historyId,
            'employee_id' => $employeeId,
            'status_pegawai_id' => $pensiunId,
            'status_nama' => 'Pensiun',
            'keterangan' => 'Pensiun sebelum soft delete.',
            'tanggal_efektif' => '2025-12-01',
            'is_latest' => true,
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);

        $migration = require database_path('migrations/2026_08_20_235200_drop_soft_deletes_from_employees_table.php');
        call_user_func([$migration, 'up']);

        $employee = DB::table('employees')->where('id', $employeeId)->first();
        $this->assertSame($pensiunId, $employee->status_pegawai_id);
        $this->assertSame('Pensiun', $employee->status_aktif);
        $this->assertSame('2025-12-01', $employee->status_tanggal);
        $this->assertSame('Pensiun sebelum soft delete.', $employee->status_keterangan);
        $this->assertDatabaseCount('employee_status_histories', 1);
        $this->assertTrue((bool) DB::table('employee_status_histories')->where('id', $historyId)->value('is_latest'));
        $this->assertNull(DB::table('employee_status_backfill')->where('employee_id', $employeeId)->value('backfill_history_id'));
        $this->assertFalse((bool) DB::table('employee_status_backfill')->where('employee_id', $employeeId)->value('status_was_backfilled'));

        call_user_func([$migration, 'down']);

        $restored = DB::table('employees')->where('id', $employeeId)->first();
        $this->assertSame($pensiunId, $restored->status_pegawai_id);
        $this->assertSame('Pensiun', $restored->status_aktif);
        $this->assertEquals($deletedAt, $restored->deleted_at);
        $this->assertDatabaseCount('employee_status_histories', 1);
        $this->assertTrue((bool) DB::table('employee_status_histories')->where('id', $historyId)->value('is_latest'));
    }

    public function test_up_down_tanpa_pegawai_soft_deleted_tidak_membutuhkan_backfill_history_id(): void
    {
        if (! Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        $aktifId = DB::table('ref_status_pegawai')->where('kode', 'AKTIF')->value('id');
        $employeeId = (string) Str::uuid();
        DB::table('employees')->insert([
            'id' => $employeeId,
            'nip' => '199001012020011009',
            'nama_lengkap' => 'Pegawai Aktif Tanpa Backfill',
            'status_pegawai_id' => $aktifId,
            'status_aktif' => 'Aktif',
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_20_235200_drop_soft_deletes_from_employees_table.php');
        call_user_func([$migration, 'up']);

        $this->assertDatabaseCount('employee_status_backfill', 0);
        call_user_func([$migration, 'down']);

        $this->assertTrue(Schema::hasColumn('employees', 'deleted_at'));
        $this->assertNull(DB::table('employees')->where('id', $employeeId)->value('deleted_at'));
        $this->assertFalse(Schema::hasTable('employee_status_backfill'));
    }

    public function test_up_down_round_trip_memulihkan_snapshot_is_latest_dan_deleted_at(): void
    {
        // Pasang kembali kolom deleted_at secara sementara (state pra-migrasi).
        if (! Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        $aktifId = DB::table('ref_status_pegawai')->where('kode', 'AKTIF')->value('id');
        $softDeletedAt = now()->subMonths(2)->toDateTimeString();

        $empId = (string) Str::uuid();
        $historyId = (string) Str::uuid();

        DB::table('employees')->insert([
            'id' => $empId,
            'nip' => '199001012020011010',
            'nama_lengkap' => 'Pegawai Round Trip',
            'status_pegawai_id' => $aktifId,
            'status_aktif' => 'Aktif',
            'status_tanggal' => '2026-01-15',
            'status_keterangan' => 'Keterangan lama',
            'deleted_at' => $softDeletedAt,
            'created_at' => now()->subYear(),
            'updated_at' => now()->subMonths(2),
        ]);

        // Riwayat is_latest sebelum migrasi (status Aktif).
        DB::table('employee_status_histories')->insert([
            'id' => $historyId,
            'employee_id' => $empId,
            'status_pegawai_id' => $aktifId,
            'status_nama' => 'Aktif',
            'keterangan' => 'Riwayat lama',
            'tanggal_efektif' => '2026-01-15',
            'is_latest' => true,
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);

        $migration = require database_path('migrations/2026_08_20_235200_drop_soft_deletes_from_employees_table.php');
        $this->assertInstanceOf(Migration::class, $migration);

        call_user_func([$migration, 'up']);

        // Setelah up(): snapshot NONAKTIF, riwayat baru is_latest, riwayat lama ditutup.
        $this->assertFalse(Schema::hasColumn('employees', 'deleted_at'));
        $afterUp = DB::table('employees')->where('id', $empId)->first();
        $this->assertSame('Non-Aktif', $afterUp->status_aktif);
        $this->assertSame(false, (bool) DB::table('employee_status_histories')
            ->where('employee_id', $empId)->where('id', $historyId)->value('is_latest'));
        $this->assertSame(1, DB::table('employee_status_histories')
            ->where('employee_id', $empId)->where('is_latest', true)->count());

        call_user_func([$migration, 'down']);

        // Setelah down(): deleted_at dipulihkan, snapshot lama kembali, riwayat lama
        // menjadi is_latest lagi, dan riwayat backfill NONAKTIF terhapus.
        $this->assertTrue(Schema::hasColumn('employees', 'deleted_at'));
        $restored = DB::table('employees')->where('id', $empId)->first();
        $this->assertSame($aktifId, $restored->status_pegawai_id);
        $this->assertSame('Aktif', $restored->status_aktif);
        $this->assertSame('2026-01-15', $restored->status_tanggal);
        $this->assertSame('Keterangan lama', $restored->status_keterangan);
        $this->assertNotNull($restored->deleted_at);
        $this->assertEquals($softDeletedAt, $restored->deleted_at);
        $this->assertSame(true, (bool) DB::table('employee_status_histories')
            ->where('employee_id', $empId)->where('id', $historyId)->value('is_latest'));
        $this->assertSame(0, DB::table('employee_status_histories')
            ->where('employee_id', $empId)->where('keterangan', 'Dinonaktifkan melalui mekanisme soft delete sebelum migrasi penghapusan deleted_at.')->count());
        $this->assertFalse(Schema::hasTable('employee_status_backfill'));
    }

    public function test_down_tidak_menimpa_perubahan_status_setelah_up(): void
    {
        // Rollback state-preserving yang aman: pegawai yang statusnya sudah diubah
        // oleh aplikasi setelah up() (mis. di-restore menjadi AKTIF) tidak boleh
        // dikembalikan ke snapshot pra-migrasi + deleted_at oleh down().
        if (! Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        $aktifId = DB::table('ref_status_pegawai')->where('kode', 'AKTIF')->value('id');
        $empId = (string) Str::uuid();
        $historyId = (string) Str::uuid();

        DB::table('employees')->insert([
            'id' => $empId,
            'nip' => '199001012020011020',
            'nama_lengkap' => 'Pegawai Diubah Pasca Migrasi',
            'status_pegawai_id' => $aktifId,
            'status_aktif' => 'Aktif',
            'deleted_at' => now()->subMonths(3),
            'created_at' => now()->subYear(),
            'updated_at' => now()->subMonths(3),
        ]);
        DB::table('employee_status_histories')->insert([
            'id' => $historyId,
            'employee_id' => $empId,
            'status_pegawai_id' => $aktifId,
            'status_nama' => 'Aktif',
            'keterangan' => 'Riwayat lama',
            'tanggal_efektif' => '2026-01-01',
            'is_latest' => true,
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);

        $migration = require database_path('migrations/2026_08_20_235200_drop_soft_deletes_from_employees_table.php');
        call_user_func([$migration, 'up']);

        // Simulasi perubahan aplikasi pasca-up(): pegawai diaktifkan kembali.
        $newAktifId = DB::table('ref_status_pegawai')->where('kode', 'AKTIF')->value('id');
        DB::table('employee_status_histories')->where('employee_id', $empId)
            ->where('is_latest', true)->update(['is_latest' => false]);
        DB::table('employee_status_histories')->insert([
            'id' => (string) Str::uuid(),
            'employee_id' => $empId,
            'status_pegawai_id' => $newAktifId,
            'status_nama' => 'Aktif',
            'keterangan' => 'Pemulihan aplikasi pasca migrasi',
            'tanggal_efektif' => now()->toDateString(),
            'is_latest' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->where('id', $empId)->update([
            'status_pegawai_id' => $newAktifId,
            'status_aktif' => 'Aktif',
        ]);

        call_user_func([$migration, 'down']);

        // Snapshot tidak dikembalikan ke pra-migrasi & deleted_at tidak diisi ulang.
        $afterDown = DB::table('employees')->where('id', $empId)->first();
        $this->assertSame('Aktif', $afterDown->status_aktif);
        $this->assertSame($newAktifId, $afterDown->status_pegawai_id);
        $this->assertNull($afterDown->deleted_at);

        // Riwayat is_latest tetap satu-satunya (pemulihan aplikasi); riwayat lama tidak
        // diaktifkan ulang sehingga tidak ada dua is_latest.
        $this->assertSame(1, DB::table('employee_status_histories')
            ->where('employee_id', $empId)->where('is_latest', true)->count());
        $this->assertSame(0, DB::table('employee_status_histories')
            ->where('employee_id', $empId)->where('keterangan', 'Dinonaktifkan melalui mekanisme soft delete sebelum migrasi penghapusan deleted_at.')->count());
        $this->assertFalse(Schema::hasTable('employee_status_backfill'));
    }

    public function test_down_mempertahankan_snapshot_pensiun_pasca_backfill_dan_memulihkan_deleted_at_dari_transisi_terkini(): void
    {
        // Perubahan produksi yang harus ditangkap test ini: rollback tidak boleh
        // mengosongkan deleted_at ketika status baru yang masih nonaktif sudah
        // menggantikan backfill, karena itu membuat snapshot Pensiun ambigu pada
        // schema legacy meski riwayat terbaru tetap benar.
        if (! Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        $aktifId = DB::table('ref_status_pegawai')->where('kode', 'AKTIF')->value('id');
        $pensiun = DB::table('ref_status_pegawai')->where('kode', 'PENSIUN')->first();
        $employeeId = (string) Str::uuid();
        $legacyHistoryId = (string) Str::uuid();

        DB::table('employees')->insert([
            'id' => $employeeId,
            'nip' => '199001012020011030',
            'nama_lengkap' => 'Pegawai Pensiun Pasca Backfill',
            'status_pegawai_id' => $aktifId,
            'status_aktif' => 'Aktif',
            'status_tanggal' => '2026-01-15',
            'deleted_at' => '2026-02-15 08:30:00',
            'created_at' => now()->subYear(),
            'updated_at' => now()->subMonths(6),
        ]);
        DB::table('employee_status_histories')->insert([
            'id' => $legacyHistoryId,
            'employee_id' => $employeeId,
            'status_pegawai_id' => $aktifId,
            'status_nama' => 'Aktif',
            'keterangan' => 'Riwayat aktif sebelum backfill',
            'tanggal_efektif' => '2026-01-15',
            'is_latest' => true,
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);

        $migration = require database_path('migrations/2026_08_20_235200_drop_soft_deletes_from_employees_table.php');
        call_user_func([$migration, 'up']);

        // Simulasi writer lifecycle setelah up(): PENSIUN menjadi snapshot dan
        // riwayat terbaru, sehingga rollback tidak boleh mengembalikan Aktif lama.
        DB::table('employee_status_histories')->where('employee_id', $employeeId)
            ->where('is_latest', true)->update(['is_latest' => false]);
        $pensiunHistoryId = (string) Str::uuid();
        DB::table('employee_status_histories')->insert([
            'id' => $pensiunHistoryId,
            'employee_id' => $employeeId,
            'status_pegawai_id' => $pensiun->id,
            'status_nama' => $pensiun->nama,
            'keterangan' => 'Pensiun resmi pasca backfill',
            'tanggal_efektif' => '2026-07-15',
            'is_latest' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->where('id', $employeeId)->update([
            'status_pegawai_id' => $pensiun->id,
            'status_aktif' => $pensiun->nama,
            'status_tanggal' => '2026-07-15',
        ]);

        call_user_func([$migration, 'down']);

        $afterDown = DB::table('employees')->where('id', $employeeId)->first();
        $this->assertSame($pensiun->id, $afterDown->status_pegawai_id);
        $this->assertSame($pensiun->nama, $afterDown->status_aktif);
        $this->assertSame('2026-07-15', (string) $afterDown->status_tanggal);
        $this->assertSame('2026-07-15 00:00:00', $afterDown->deleted_at);
        $this->assertSame($pensiunHistoryId, DB::table('employee_status_histories')
            ->where('employee_id', $employeeId)->where('is_latest', true)->value('id'));
        $this->assertSame(0, DB::table('employee_status_histories')
            ->where('employee_id', $employeeId)
            ->where('keterangan', 'Dinonaktifkan melalui mekanisme soft delete sebelum migrasi penghapusan deleted_at.')
            ->count());
    }

    /** Riwayat resmi dengan alasan sama tidak boleh dianggap sebagai row buatan migration. */
    public function test_down_hanya_menghapus_backfill_history_id_dan_mempertahankan_riwayat_resmi_dengan_alasan_sama(): void
    {
        if (! Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        $aktifId = DB::table('ref_status_pegawai')->where('kode', 'AKTIF')->value('id');
        $pensiun = DB::table('ref_status_pegawai')->where('kode', 'PENSIUN')->first();
        $employeeId = (string) Str::uuid();
        DB::table('employees')->insert([
            'id' => $employeeId,
            'nip' => '199001012020011045',
            'nama_lengkap' => 'Pegawai Alasan Backfill Sama',
            'status_pegawai_id' => $aktifId,
            'status_aktif' => 'Aktif',
            'status_tanggal' => '2026-01-15',
            'deleted_at' => '2026-02-15 08:30:00',
            'created_at' => now()->subYear(),
            'updated_at' => now()->subMonths(6),
        ]);

        $migration = require database_path('migrations/2026_08_20_235200_drop_soft_deletes_from_employees_table.php');
        call_user_func([$migration, 'up']);

        $backfillHistoryId = DB::table('employee_status_histories')
            ->where('employee_id', $employeeId)
            ->where('is_latest', true)
            ->value('id');
        $this->assertSame($backfillHistoryId, DB::table('employee_status_backfill')
            ->where('employee_id', $employeeId)
            ->value('backfill_history_id'));
        DB::table('employee_status_histories')->where('id', $backfillHistoryId)->update(['is_latest' => false]);

        $officialHistoryId = (string) Str::uuid();
        DB::table('employee_status_histories')->insert([
            'id' => $officialHistoryId,
            'employee_id' => $employeeId,
            'status_pegawai_id' => $pensiun->id,
            'status_nama' => $pensiun->nama,
            'keterangan' => 'Dinonaktifkan melalui mekanisme soft delete sebelum migrasi penghapusan deleted_at.',
            'tanggal_efektif' => '2026-07-25',
            'is_latest' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->where('id', $employeeId)->update([
            'status_pegawai_id' => $pensiun->id,
            'status_aktif' => $pensiun->nama,
            'status_tanggal' => '2026-07-25',
            'status_keterangan' => 'Perubahan resmi pasca migration.',
        ]);

        call_user_func([$migration, 'down']);

        $employee = DB::table('employees')->where('id', $employeeId)->first();
        $this->assertSame($pensiun->id, $employee->status_pegawai_id);
        $this->assertSame($pensiun->nama, $employee->status_aktif);
        $this->assertSame('2026-07-25', (string) $employee->status_tanggal);
        $this->assertSame('2026-07-25 00:00:00', $employee->deleted_at);
        $this->assertDatabaseMissing('employee_status_histories', ['id' => $backfillHistoryId]);
        $this->assertDatabaseHas('employee_status_histories', [
            'id' => $officialHistoryId,
            'employee_id' => $employeeId,
            'is_latest' => true,
        ]);
    }

    #[DataProvider('invalidBackfillIdentityProvider')]
    public function test_down_gagal_tertutup_sebelum_mutasi_bila_backfill_history_id_tidak_sah(string $mode): void
    {
        if (! Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        $aktifId = DB::table('ref_status_pegawai')->where('kode', 'AKTIF')->value('id');
        $employeeId = (string) Str::uuid();
        DB::table('employees')->insert([
            'id' => $employeeId,
            'nip' => $mode === 'missing' ? '199001012020011046' : '199001012020011047',
            'nama_lengkap' => 'Pegawai Identitas Backfill Rusak',
            'status_pegawai_id' => $aktifId,
            'status_aktif' => 'Aktif',
            'status_tanggal' => '2026-01-15',
            'deleted_at' => '2026-02-15 08:30:00',
            'created_at' => now()->subYear(),
            'updated_at' => now()->subMonths(6),
        ]);

        $migration = require database_path('migrations/2026_08_20_235200_drop_soft_deletes_from_employees_table.php');
        call_user_func([$migration, 'up']);
        $backfillHistoryId = DB::table('employee_status_histories')
            ->where('employee_id', $employeeId)
            ->where('is_latest', true)
            ->value('id');

        $invalidId = null;
        if ($mode === 'mismatched') {
            $otherEmployeeId = (string) Str::uuid();
            DB::table('employees')->insert([
                'id' => $otherEmployeeId,
                'nip' => '199001012020011048',
                'nama_lengkap' => 'Pegawai Pemilik Riwayat Lain',
                'status_pegawai_id' => $aktifId,
                'status_aktif' => 'Aktif',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $invalidId = (string) Str::uuid();
            DB::table('employee_status_histories')->insert([
                'id' => $invalidId,
                'employee_id' => $otherEmployeeId,
                'status_pegawai_id' => $aktifId,
                'status_nama' => 'Aktif',
                'keterangan' => 'Riwayat milik pegawai lain',
                'tanggal_efektif' => '2026-01-15',
                'is_latest' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('employee_status_backfill')->where('employee_id', $employeeId)->update([
            'backfill_history_id' => $invalidId,
        ]);

        $exception = null;
        try {
            call_user_func([$migration, 'down']);
        } catch (\RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertFalse(Schema::hasColumn('employees', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('employees', 'status_note'));
        $this->assertDatabaseHas('employee_status_histories', ['id' => $backfillHistoryId]);
        $this->assertDatabaseHas('employee_status_backfill', ['employee_id' => $employeeId]);
    }

    /** @return array<string, array{string}> */
    public static function invalidBackfillIdentityProvider(): array
    {
        return [
            'ID kosong' => ['missing'],
            'ID milik pegawai lain' => ['mismatched'],
        ];
    }

    public function test_down_gagal_tertutup_tanpa_mutasi_parsial_bila_status_terkini_tidak_dapat_diklasifikasikan(): void
    {
        // Perubahan produksi yang harus ditangkap test ini: menghapus riwayat atau
        // backup sebelum semua rencana rollback tervalidasi akan kehilangan jejak
        // backfill ketika kelompok status terkini tidak dapat diklasifikasikan.
        if (! Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        $aktifId = DB::table('ref_status_pegawai')->where('kode', 'AKTIF')->value('id');
        $pensiun = DB::table('ref_status_pegawai')->where('kode', 'PENSIUN')->first();
        $employeeId = (string) Str::uuid();

        DB::table('employees')->insert([
            'id' => $employeeId,
            'nip' => '199001012020011040',
            'nama_lengkap' => 'Pegawai Status Tidak Terklarifikasi',
            'status_pegawai_id' => $aktifId,
            'status_aktif' => 'Aktif',
            'status_tanggal' => '2026-01-15',
            'deleted_at' => '2026-02-15 08:30:00',
            'created_at' => now()->subYear(),
            'updated_at' => now()->subMonths(6),
        ]);

        $migration = require database_path('migrations/2026_08_20_235200_drop_soft_deletes_from_employees_table.php');
        call_user_func([$migration, 'up']);

        $backfillHistoryId = DB::table('employee_status_histories')
            ->where('employee_id', $employeeId)
            ->where('keterangan', 'Dinonaktifkan melalui mekanisme soft delete sebelum migrasi penghapusan deleted_at.')
            ->value('id');
        DB::table('employee_status_histories')->where('id', $backfillHistoryId)->update(['is_latest' => false]);
        DB::table('ref_status_pegawai')->where('id', $pensiun->id)->update(['kelompok' => '   ']);
        $unclassifiableHistoryId = (string) Str::uuid();
        DB::table('employee_status_histories')->insert([
            'id' => $unclassifiableHistoryId,
            'employee_id' => $employeeId,
            'status_pegawai_id' => $pensiun->id,
            'status_nama' => $pensiun->nama,
            'keterangan' => 'Status baru tanpa kelompok',
            'tanggal_efektif' => '2026-07-20',
            'is_latest' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->where('id', $employeeId)->update([
            'status_pegawai_id' => $pensiun->id,
            'status_aktif' => $pensiun->nama,
            'status_tanggal' => '2026-07-20',
        ]);

        $exception = null;

        try {
            call_user_func([$migration, 'down']);
        } catch (\RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame($pensiun->id, DB::table('employees')->where('id', $employeeId)->value('status_pegawai_id'));
        $this->assertSame($unclassifiableHistoryId, DB::table('employee_status_histories')
            ->where('employee_id', $employeeId)->where('is_latest', true)->value('id'));
        $this->assertDatabaseHas('employee_status_histories', ['id' => $backfillHistoryId]);
        $this->assertDatabaseHas('employee_status_backfill', ['employee_id' => $employeeId]);
    }

    public function test_down_memvalidasi_semua_pegawai_sebelum_mutasi_bila_kelompok_null(): void
    {
        // Perubahan yang harus ditangkap: preflight tidak cukup lolos untuk satu
        // pegawai. Ketika pegawai kedua memiliki kelompok NULL, jejak backfill dan
        // deleted_at pegawai pertama harus tetap utuh karena belum ada mutasi data.
        if (! Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        $aktifId = DB::table('ref_status_pegawai')->where('kode', 'AKTIF')->value('id');
        $nonaktif = DB::table('ref_status_pegawai')->where('kode', 'NONAKTIF')->first();
        $pensiun = DB::table('ref_status_pegawai')->where('kode', 'PENSIUN')->first();
        $validEmployeeId = (string) Str::uuid();
        $invalidEmployeeId = (string) Str::uuid();

        foreach ([
            [$validEmployeeId, '199001012020011041', 'Pegawai Valid Sebelum Gagal'],
            [$invalidEmployeeId, '199001012020011042', 'Pegawai Kelompok Null'],
        ] as [$employeeId, $nip, $nama]) {
            DB::table('employees')->insert([
                'id' => $employeeId,
                'nip' => $nip,
                'nama_lengkap' => $nama,
                'status_pegawai_id' => $aktifId,
                'status_aktif' => 'Aktif',
                'status_tanggal' => '2026-01-15',
                'deleted_at' => '2026-02-15 08:30:00',
                'created_at' => now()->subYear(),
                'updated_at' => now()->subMonths(6),
            ]);
        }

        $migration = require database_path('migrations/2026_08_20_235200_drop_soft_deletes_from_employees_table.php');
        call_user_func([$migration, 'up']);

        $validBackfillHistoryId = DB::table('employee_status_histories')
            ->where('employee_id', $validEmployeeId)->where('keterangan', 'Dinonaktifkan melalui mekanisme soft delete sebelum migrasi penghapusan deleted_at.')
            ->value('id');
        $invalidBackfillHistoryId = DB::table('employee_status_histories')
            ->where('employee_id', $invalidEmployeeId)->where('keterangan', 'Dinonaktifkan melalui mekanisme soft delete sebelum migrasi penghapusan deleted_at.')
            ->value('id');
        DB::table('employee_status_histories')->whereIn('id', [$validBackfillHistoryId, $invalidBackfillHistoryId])
            ->update(['is_latest' => false]);

        $validHistoryId = (string) Str::uuid();
        DB::table('employee_status_histories')->insert([
            'id' => $validHistoryId,
            'employee_id' => $validEmployeeId,
            'status_pegawai_id' => $nonaktif->id,
            'status_nama' => $nonaktif->nama,
            'keterangan' => 'Status nonaktif baru yang valid',
            'tanggal_efektif' => '2026-07-19',
            'is_latest' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->where('id', $validEmployeeId)->update([
            'status_pegawai_id' => $nonaktif->id,
            'status_aktif' => $nonaktif->nama,
            'status_tanggal' => '2026-07-19',
        ]);

        Schema::table('ref_status_pegawai', function (Blueprint $table): void {
            $table->string('kelompok', 50)->nullable()->change();
        });
        DB::table('ref_status_pegawai')->where('id', $pensiun->id)->update(['kelompok' => null]);
        $invalidHistoryId = (string) Str::uuid();
        DB::table('employee_status_histories')->insert([
            'id' => $invalidHistoryId,
            'employee_id' => $invalidEmployeeId,
            'status_pegawai_id' => $pensiun->id,
            'status_nama' => $pensiun->nama,
            'keterangan' => 'Status baru dengan kelompok NULL',
            'tanggal_efektif' => '2026-07-20',
            'is_latest' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->where('id', $invalidEmployeeId)->update([
            'status_pegawai_id' => $pensiun->id,
            'status_aktif' => $pensiun->nama,
            'status_tanggal' => '2026-07-20',
        ]);

        $exception = null;

        try {
            call_user_func([$migration, 'down']);
        } catch (\RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertFalse(Schema::hasColumn('employees', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('employees', 'status_note'));
        $this->assertSame($validHistoryId, DB::table('employee_status_histories')
            ->where('employee_id', $validEmployeeId)->where('is_latest', true)->value('id'));
        $this->assertSame($invalidHistoryId, DB::table('employee_status_histories')
            ->where('employee_id', $invalidEmployeeId)->where('is_latest', true)->value('id'));
        $this->assertDatabaseHas('employee_status_histories', ['id' => $validBackfillHistoryId]);
        $this->assertDatabaseHas('employee_status_histories', ['id' => $invalidBackfillHistoryId]);
        $this->assertDatabaseHas('employee_status_backfill', ['employee_id' => $validEmployeeId]);
        $this->assertDatabaseHas('employee_status_backfill', ['employee_id' => $invalidEmployeeId]);
    }

    public function test_down_gagal_tertutup_bila_status_nonaktif_tidak_memiliki_tanggal_deterministik(): void
    {
        if (! Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        $aktifId = DB::table('ref_status_pegawai')->where('kode', 'AKTIF')->value('id');
        $pensiun = DB::table('ref_status_pegawai')->where('kode', 'PENSIUN')->first();
        $employeeId = (string) Str::uuid();
        DB::table('employees')->insert([
            'id' => $employeeId,
            'nip' => '199001012020011043',
            'nama_lengkap' => 'Pegawai Tanpa Tanggal Rollback',
            'status_pegawai_id' => $aktifId,
            'status_aktif' => 'Aktif',
            'deleted_at' => '2026-02-15 08:30:00',
            'created_at' => now()->subYear(),
            'updated_at' => now()->subMonths(6),
        ]);

        $migration = require database_path('migrations/2026_08_20_235200_drop_soft_deletes_from_employees_table.php');
        call_user_func([$migration, 'up']);

        $backfillHistoryId = DB::table('employee_status_histories')
            ->where('employee_id', $employeeId)->where('keterangan', 'Dinonaktifkan melalui mekanisme soft delete sebelum migrasi penghapusan deleted_at.')
            ->value('id');
        DB::table('employee_status_histories')->where('id', $backfillHistoryId)->update(['is_latest' => false]);
        DB::table('employees')->where('id', $employeeId)->update([
            'status_pegawai_id' => $pensiun->id,
            'status_aktif' => $pensiun->nama,
            'status_tanggal' => null,
        ]);

        $exception = null;

        try {
            call_user_func([$migration, 'down']);
        } catch (\RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertDatabaseHas('employee_status_histories', ['id' => $backfillHistoryId]);
        $this->assertDatabaseHas('employee_status_backfill', ['employee_id' => $employeeId]);
    }

    public function test_down_gagal_tertutup_bila_riwayat_terbaru_status_nonaktif_duplikat(): void
    {
        if (! Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        $aktifId = DB::table('ref_status_pegawai')->where('kode', 'AKTIF')->value('id');
        $pensiun = DB::table('ref_status_pegawai')->where('kode', 'PENSIUN')->first();
        $employeeId = (string) Str::uuid();
        DB::table('employees')->insert([
            'id' => $employeeId,
            'nip' => '199001012020011044',
            'nama_lengkap' => 'Pegawai Riwayat Terbaru Duplikat',
            'status_pegawai_id' => $aktifId,
            'status_aktif' => 'Aktif',
            'deleted_at' => '2026-02-15 08:30:00',
            'created_at' => now()->subYear(),
            'updated_at' => now()->subMonths(6),
        ]);

        $migration = require database_path('migrations/2026_08_20_235200_drop_soft_deletes_from_employees_table.php');
        call_user_func([$migration, 'up']);

        $backfillHistoryId = DB::table('employee_status_histories')
            ->where('employee_id', $employeeId)->where('keterangan', 'Dinonaktifkan melalui mekanisme soft delete sebelum migrasi penghapusan deleted_at.')
            ->value('id');
        DB::table('employee_status_histories')->where('id', $backfillHistoryId)->update(['is_latest' => false]);
        foreach (['2026-07-21', '2026-07-22'] as $tanggalEfektif) {
            DB::table('employee_status_histories')->insert([
                'id' => (string) Str::uuid(),
                'employee_id' => $employeeId,
                'status_pegawai_id' => $pensiun->id,
                'status_nama' => $pensiun->nama,
                'keterangan' => 'Riwayat Pensiun duplikat',
                'tanggal_efektif' => $tanggalEfektif,
                'is_latest' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('employees')->where('id', $employeeId)->update([
            'status_pegawai_id' => $pensiun->id,
            'status_aktif' => $pensiun->nama,
            'status_tanggal' => '2026-07-22',
        ]);

        $exception = null;

        try {
            call_user_func([$migration, 'down']);
        } catch (\RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertDatabaseHas('employee_status_histories', ['id' => $backfillHistoryId]);
        $this->assertDatabaseHas('employee_status_backfill', ['employee_id' => $employeeId]);
    }

    public function test_up_melempar_error_bila_ref_nonaktif_hilang_namun_ada_soft_deleted(): void
    {
        // Fail-closed: jika masih ada pegawai soft-deleted namun referensi NONAKTIF tidak
        // tersedia, up() harus menghentikan migrasi alih-alih menghapus deleted_at
        // diam-diam tanpa menyalin penanda nonaktif ke status yang sah.
        if (! Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        DB::table('employees')->insert([
            'id' => (string) Str::uuid(),
            'nip' => '199001012020011011',
            'nama_lengkap' => 'Pegawai Legacy Soft Deleted',
            'status_aktif' => 'Aktif',
            'deleted_at' => now()->subMonth(),
            'created_at' => now()->subYear(),
            'updated_at' => now()->subMonth(),
        ]);

        // Hapus referensi NONAKTIF agar migrasi tidak dapat melakukan backfill.
        DB::table('ref_status_pegawai')->where('kode', 'NONAKTIF')->delete();

        $migration = require database_path('migrations/2026_08_20_235200_drop_soft_deletes_from_employees_table.php');

        $this->expectException(\RuntimeException::class);
        call_user_func([$migration, 'up']);
    }
}
