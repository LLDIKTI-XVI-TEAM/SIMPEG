<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Penanda riwayat yang dibuat oleh backfill up(); dipakai ulang oleh down()
     * agar hanya baris hasil backfill yang mendapat perlakuan rollback.
     */
    private const BACKFILL_NOTE = 'Dinonaktifkan melalui mekanisme soft delete sebelum migrasi penghapusan deleted_at.';

    /**
     * Hapus mekanisme soft delete dari pegawai.
     *
     * Keputusan produk: nonaktif pegawai ditentukan oleh status kepegawaian
     * (status_pegawai_id / status_aktif), bukan oleh kolom deleted_at. Kolom
     * deleted_at dihapus sehingga tidak ada lagi dua sumber "nonaktif" yang
     * saling bertentangan.
     */
    public function up(): void
    {
        // Sebelum kolom deleted_at dibuang, migrasikan pegawai yang sebelumnya
        // dinonaktifkan lewat soft delete menjadi status kepegawaian NONAKTIF.
        // Soft delete lama hanya mengisi deleted_at tanpa mengubah status_aktif,
        // sehingga tanpa backfill mayoritas pegawai yang sudah dinonaktifkan akan
        // kembali terbaca "Aktif" setelah kolom ini dihapus — akun SSO mereka pun
        // lolos dari middleware pemblokiran.
        if (Schema::hasColumn('employees', 'deleted_at')) {
            $statusNonaktif = DB::table('ref_status_pegawai')
                ->where('kode', 'NONAKTIF')
                ->first();

            // Fail-closed: jika masih ada pegawai soft-deleted namun referensi NONAKTIF
            // tidak tersedia, jangan diam-diam menghapus deleted_at (satu-satunya penanda
            // nonaktif) tanpa backfill ke status yang sah — hentikan migrasi agar admin
            // memperbaiki referensi terlebih dahulu.
            $hasSoftDeleted = DB::table('employees')->whereNotNull('deleted_at')->exists();

            if ($hasSoftDeleted && $statusNonaktif === null) {
                throw new RuntimeException(
                    'Ref status NONAKTIF tidak ditemukan; hentikan migrasi soft delete agar data legacy nonaktif tidak kehilangan penanda statusnya.',
                );
            }

            if ($statusNonaktif !== null) {
                // Snapshot pra-migrasi disalin ke tabel backup agar down() dapat
                // memulihkan representasi lama secara deterministik (Issue #22):
                // deleted_at, snapshot status_* , dan is_latest riwayat lama.
                if (! Schema::hasTable('employee_status_backfill')) {
                    Schema::create('employee_status_backfill', function (Blueprint $table): void {
                        $table->uuid('employee_id')->primary();
                        $table->uuid('prev_status_pegawai_id')->nullable();
                        $table->string('prev_status_aktif', 100)->nullable();
                        $table->date('prev_status_tanggal')->nullable();
                        $table->text('prev_status_keterangan')->nullable();
                        $table->timestamp('prev_deleted_at')->nullable();
                        $table->uuid('prev_history_id')->nullable();
                        $table->uuid('backfill_history_id')->nullable();
                        $table->boolean('status_was_backfilled')->default(true);
                        $table->timestamps();
                    });
                } elseif (! Schema::hasColumn('employee_status_backfill', 'backfill_history_id')) {
                    Schema::table('employee_status_backfill', function (Blueprint $table): void {
                        $table->uuid('backfill_history_id')->nullable();
                    });
                }
                if (! Schema::hasColumn('employee_status_backfill', 'status_was_backfilled')) {
                    Schema::table('employee_status_backfill', function (Blueprint $table): void {
                        // Backup dari revisi lama selalu membuat histori sintetis.
                        $table->boolean('status_was_backfilled')->default(true);
                    });
                }

                // Tanggal deaktivasi asli (deleted_at) wajib dipertahankan: snapshot
                // status_tanggal dan riwayat append-only memakai tanggal tersebut agar
                // EmployeeTrendQuery tidak menempatkan tanggal keluar pada status lama
                // atau menggeser tren historis secara retroaktif.
                DB::table('employees')
                    ->leftJoin('ref_status_pegawai as current_status', 'current_status.id', '=', 'employees.status_pegawai_id')
                    ->whereNotNull('employees.deleted_at')
                    ->select('employees.*', DB::raw('LOWER(BTRIM(current_status.kelompok)) as current_status_group'))
                    ->orderBy('employees.id')
                    ->chunk(100, function ($employees) use ($statusNonaktif): void {
                        foreach ($employees as $employee) {
                            $tanggalEfektif = date('Y-m-d', strtotime((string) $employee->deleted_at));
                            $currentStatusGroup = trim((string) ($employee->current_status_group ?? ''));
                            $requiresStatusBackfill = $currentStatusGroup === ''
                                || in_array($currentStatusGroup, ['aktif', 'aktif/khusus'], true);
                            $backfillHistoryId = $requiresStatusBackfill ? (string) Str::uuid() : null;

                            // Simpan state pra-migrasi sebelum snapshot ditimpa.
                            $prevHistoryId = DB::table('employee_status_histories')
                                ->where('employee_id', $employee->id)
                                ->where('is_latest', true)
                                ->value('id');

                            DB::table('employee_status_backfill')->updateOrInsert(
                                ['employee_id' => $employee->id],
                                [
                                    'prev_status_pegawai_id' => $employee->status_pegawai_id,
                                    'prev_status_aktif' => $employee->status_aktif,
                                    'prev_status_tanggal' => $employee->status_tanggal,
                                    'prev_status_keterangan' => $employee->status_keterangan,
                                    'prev_deleted_at' => $employee->deleted_at,
                                    'prev_history_id' => $prevHistoryId,
                                    'backfill_history_id' => $backfillHistoryId,
                                    'status_was_backfilled' => $requiresStatusBackfill,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ],
                            );

                            // Soft-delete dapat terjadi sesudah status resmi Pensiun, Mutasi,
                            // atau nonaktif lain. Status spesifik itu sudah menjadi source of
                            // truth sehingga hanya metadata rollback yang perlu disimpan.
                            if (! $requiresStatusBackfill) {
                                continue;
                            }

                            // Tutup riwayat is_latest lama sebelum menambah baris NONAKTIF,
                            // mengikuti pola append-only DeactivateEmployeeAction.
                            DB::table('employee_status_histories')
                                ->where('employee_id', $employee->id)
                                ->where('is_latest', true)
                                ->update(['is_latest' => false]);

                            DB::table('employee_status_histories')->insert([
                                'id' => $backfillHistoryId,
                                'employee_id' => $employee->id,
                                'status_pegawai_id' => $statusNonaktif->id,
                                'status_nama' => $statusNonaktif->nama,
                                'keterangan' => self::BACKFILL_NOTE,
                                'tanggal_efektif' => $tanggalEfektif,
                                'changed_by_user_id' => null,
                                'is_latest' => true,
                            ]);

                            DB::table('employees')
                                ->where('id', $employee->id)
                                ->update([
                                    'status_pegawai_id' => $statusNonaktif->id,
                                    'status_aktif' => 'Non-Aktif',
                                    'status_tanggal' => $tanggalEfektif,
                                ]);
                        }
                    });
            }
        }

        Schema::table('employees', function (Blueprint $table): void {
            if (Schema::hasColumn('employees', 'deleted_at')) {
                $table->dropColumn('deleted_at');
            }

            // Pesan opsional saat pegawai dinonaktifkan, dikirim ke pegawai lewat
            // notifikasi in-app dan ditampilkan di halaman status pegawai nonaktif.
            if (! Schema::hasColumn('employees', 'status_note')) {
                $table->text('status_note')
                    ->nullable()
                    ->after('status_keterangan')
                    ->comment('Pesan opsional yang dikirim ke pegawai saat dinonaktifkan (in-app).');
            }
        });
    }

    public function down(): void
    {
        // Rollback state-preserving (Issue #22): hapus riwayat NONAKTIF buatan up(),
        // aktifkan kembali is_latest riwayat lama, dan pulihkan snapshot status_*
        // beserta deleted_at persis seperti sebelum migrasi — TAPI hanya untuk pegawai
        // yang statusnya masih merupakan hasil backfill. Pegawai yang sudah diubah
        // statusnya kembali (restore/status baru) setelah up() tidak boleh ditimpa oleh
        // snapshot lama, dan deleted_at tidak diisi ulang agar pemulihan tersebut tidak
        // terbalik hanya karena deployment di-rollback.
        if (Schema::hasTable('employee_status_backfill')) {
            // Backup versi yang tidak membawa identitas history tidak aman untuk
            // diinterpretasi dari teks alasan yang dapat dipakai transaksi resmi.
            if (! Schema::hasColumn('employee_status_backfill', 'backfill_history_id')) {
                throw new RuntimeException('Rollback soft delete ditolak: backup tidak memiliki identitas riwayat backfill yang durable.');
            }
            if (! Schema::hasColumn('employee_status_backfill', 'status_was_backfilled')) {
                throw new RuntimeException('Rollback soft delete ditolak: backup tidak memiliki penanda keputusan backfill status.');
            }

            DB::transaction(function (): void {
                // Seluruh rencana harus valid sebelum riwayat backfill pertama dihapus.
                // Dengan begitu status referensi yang rusak tidak meninggalkan rollback
                // parsial yang menghilangkan snapshot atau jejak histori pegawai lain.
                $batchSize = 100;
                $employeeIds = static fn ($query) => $query->select('employee_id')->from('employee_status_backfill');

                // Kunci dikumpulkan per jenis tabel secara global: seluruh employee,
                // lalu referensi status, lalu histori. Urutan ini sama dengan writer
                // lifecycle dan mencegah status terkunci sambil menunggu employee lain.
                $lastEmployeeId = null;
                do {
                    $employees = DB::table('employees')->whereIn('id', $employeeIds)
                        ->when($lastEmployeeId !== null, fn ($query) => $query->where('id', '>', $lastEmployeeId))
                        ->orderBy('id')->limit($batchSize)->lockForUpdate()->get(['id']);
                    $lastEmployeeId = $employees->last()?->id;
                } while ($employees->count() === $batchSize);

                if (DB::table('employee_status_backfill as backfill')
                    ->leftJoin('employees', 'employees.id', '=', 'backfill.employee_id')
                    ->whereNull('employees.id')->exists()) {
                    throw new RuntimeException('Pegawai pada backup rollback soft delete tidak ditemukan.');
                }

                $lastStatusId = null;
                do {
                    $statuses = DB::table('ref_status_pegawai')
                        ->whereIn('id', DB::table('employees')->select('status_pegawai_id')->whereIn('id', $employeeIds))
                        ->when($lastStatusId !== null, fn ($query) => $query->where('id', '>', $lastStatusId))
                        ->orderBy('id')->limit($batchSize)->lockForUpdate()->get(['id']);
                    $lastStatusId = $statuses->last()?->id;
                } while ($statuses->count() === $batchSize);

                $lastHistoryId = null;
                do {
                    $histories = DB::table('employee_status_histories')
                        ->whereIn('employee_id', $employeeIds)
                        ->where(fn ($query) => $query
                            ->whereIn('id', DB::table('employee_status_backfill')->select('backfill_history_id'))
                            ->orWhere('is_latest', true))
                        ->when($lastHistoryId !== null, fn ($query) => $query->where('id', '>', $lastHistoryId))
                        ->orderBy('id')->limit($batchSize)->lockForUpdate()->get(['id']);
                    $lastHistoryId = $histories->last()?->id;
                } while ($histories->count() === $batchSize);

                // Preflight keyset pertama tidak memutasi data dan tidak menyimpan plan
                // seluruh korpus. Setiap baris wajib lolos sebelum fase mutasi dimulai.
                $lastBackfillId = null;
                do {
                    $backfills = DB::table('employee_status_backfill')
                        ->when($lastBackfillId !== null, fn ($query) => $query->where('employee_id', '>', $lastBackfillId))
                        ->orderBy('employee_id')->limit($batchSize)->get();

                    foreach ($backfills as $backfill) {
                        $employee = DB::table('employees')
                            ->where('id', $backfill->employee_id)
                            ->first();

                        // Baris preservation tidak memiliki histori sintetis. Penanda eksplisit
                        // mencegah NULL akibat korupsi disalahartikan sebagai keputusan sah.
                        if (! (bool) $backfill->status_was_backfilled) {
                            if ($backfill->backfill_history_id !== null) {
                                throw new RuntimeException('Backup preservation tidak boleh memiliki identitas riwayat backfill.');
                            }
                            $this->resolveRollbackDeletedAt($employee, $backfill);

                            continue;
                        }

                        $riwayatBackfill = DB::table('employee_status_histories')
                            ->where('id', $backfill->backfill_history_id)
                            ->first();
                        if ($riwayatBackfill === null || $riwayatBackfill->employee_id !== $backfill->employee_id) {
                            throw new RuntimeException('Identitas riwayat backfill tidak ditemukan atau tidak cocok dengan pegawai backup.');
                        }
                        $masihBackfillAwal = (bool) $riwayatBackfill->is_latest;

                        // Jalur ini wajib mengembalikan snapshot persis pra-up(); tidak perlu
                        // mengklasifikasikan referensi yang mungkin telah berubah sesudahnya.
                        if ($masihBackfillAwal) {
                            continue;
                        }

                        $this->resolveRollbackDeletedAt($employee, $backfill);

                    }
                    $lastBackfillId = $backfills->last()?->employee_id;
                } while ($backfills->count() === $batchSize);

                // Fase kedua hanya berjalan setelah seluruh preflight valid.
                $this->restoreLegacyEmployeeSchema();
                $lastBackfillId = null;
                do {
                    $backfills = DB::table('employee_status_backfill')
                        ->when($lastBackfillId !== null, fn ($query) => $query->where('employee_id', '>', $lastBackfillId))
                        ->orderBy('employee_id')->limit($batchSize)->get();
                    foreach ($backfills as $backfill) {
                        if (! (bool) $backfill->status_was_backfilled) {
                            $employee = DB::table('employees')->where('id', $backfill->employee_id)->first();
                            DB::table('employees')->where('id', $backfill->employee_id)->update([
                                'deleted_at' => $this->resolveRollbackDeletedAt($employee, $backfill),
                            ]);

                            continue;
                        }

                        $riwayatBackfill = DB::table('employee_status_histories')
                            ->where('id', $backfill->backfill_history_id)->firstOrFail();
                        $masihBackfillAwal = (bool) $riwayatBackfill->is_latest;
                        // Hanya jejak yang dihasilkan up() yang dihapus. Snapshot dan riwayat
                        // terbaru setelah up() tidak disentuh selain representasi deleted_at.
                        DB::table('employee_status_histories')
                            ->where('id', $riwayatBackfill->id)
                            ->delete();

                        if ($masihBackfillAwal) {
                            if ($backfill->prev_history_id !== null) {
                                DB::table('employee_status_histories')
                                    ->where('id', $backfill->prev_history_id)
                                    ->update(['is_latest' => true]);
                            }

                            DB::table('employees')
                                ->where('id', $backfill->employee_id)
                                ->update([
                                    'status_pegawai_id' => $backfill->prev_status_pegawai_id,
                                    'status_aktif' => $backfill->prev_status_aktif,
                                    'status_tanggal' => $backfill->prev_status_tanggal,
                                    'status_keterangan' => $backfill->prev_status_keterangan,
                                    'deleted_at' => $backfill->prev_deleted_at,
                                ]);

                            continue;
                        }

                        $employee = DB::table('employees')->where('id', $backfill->employee_id)->first();
                        DB::table('employees')->where('id', $backfill->employee_id)->update([
                            'deleted_at' => $this->resolveRollbackDeletedAt($employee, $backfill),
                        ]);
                    }
                    $lastBackfillId = $backfills->last()?->employee_id;
                } while ($backfills->count() === $batchSize);

                DB::table('employee_status_backfill')->delete();
                Schema::dropIfExists('employee_status_backfill');
            });

            return;
        }

        // Tanpa backup exact, teks alasan tidak cukup untuk membedakan riwayat
        // migration dari perubahan resmi. Tolak rollback ambigu sebelum DDL.
        if (DB::table('employee_status_histories')->where('keterangan', self::BACKFILL_NOTE)->exists()) {
            throw new RuntimeException('Rollback soft delete ditolak: riwayat legacy tidak memiliki identitas backfill yang durable.');
        }

        $this->restoreLegacyEmployeeSchema();
    }

    /** Pulihkan kolom schema legacy hanya setelah seluruh preflight data lolos. */
    private function restoreLegacyEmployeeSchema(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (Schema::hasColumn('employees', 'status_note')) {
                $table->dropColumn('status_note');
            }

            if (! Schema::hasColumn('employees', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    /** Tentukan representasi deleted_at rollback tanpa mengubah snapshot lifecycle terkini. */
    private function resolveRollbackDeletedAt(object $employee, object $backfill): ?string
    {
        $status = DB::table('ref_status_pegawai')
            ->select(DB::raw('LOWER(BTRIM(kelompok)) as kelompok_normalisasi'))
            ->where('id', $employee->status_pegawai_id)
            ->first();

        if ($status === null || $status->kelompok_normalisasi === null || $status->kelompok_normalisasi === '') {
            throw new RuntimeException('Status pegawai terkini tidak dapat diklasifikasikan untuk rollback soft delete.');
        }

        $latestHistoryId = DB::table('employee_status_histories')
            ->where('employee_id', $backfill->employee_id)
            ->where('is_latest', true)
            ->value('id');
        $snapshotBelumBerubah = (string) $employee->status_pegawai_id === (string) $backfill->prev_status_pegawai_id
            && (string) $employee->status_aktif === (string) $backfill->prev_status_aktif
            && (string) $employee->status_tanggal === (string) $backfill->prev_status_tanggal
            && (string) $employee->status_keterangan === (string) $backfill->prev_status_keterangan
            && (string) $latestHistoryId === (string) $backfill->prev_history_id;

        if (! (bool) $backfill->status_was_backfilled && $snapshotBelumBerubah) {
            return $backfill->prev_deleted_at;
        }

        if (in_array($status->kelompok_normalisasi, ['aktif', 'aktif/khusus'], true)) {
            return null;
        }

        // Riwayat hanya dipakai bila cocok dengan snapshot terkini; bila tidak ada,
        // status_tanggal menjadi fallback eksplisit untuk status nonaktif terbaru.
        $latest = DB::table('employee_status_histories')
            ->where('employee_id', $backfill->employee_id)
            ->where('status_pegawai_id', $employee->status_pegawai_id)
            ->where('is_latest', true)
            ->get();

        if ($latest->count() > 1) {
            throw new RuntimeException('Riwayat status terkini ganda tidak dapat dipakai untuk rollback soft delete.');
        }

        $deletedAt = $latest->first()?->tanggal_efektif ?? $employee->status_tanggal;
        if ($deletedAt === null) {
            throw new RuntimeException('Tanggal efektif status nonaktif tidak tersedia untuk rollback soft delete.');
        }

        return (string) $deletedAt;
    }
};
