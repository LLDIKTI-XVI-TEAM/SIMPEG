<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Riwayat yang mirror dokumennya dicari dari nomor SK.
     *
     * @var array<string, string>
     */
    private const HISTORY_MIRRORS = [
        'rank_histories' => 'sk_pangkat',
        'position_histories' => 'sk_jabatan',
        'salary_histories' => 'sk_kgb',
        'appointments' => 'sk_pengangkatan',
        'discipline_records' => 'sk_hukuman_disiplin',
    ];

    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            // Identitas riwayat pemilik mirror. no_sk dapat null/duplikat sehingga
            // tidak boleh menjadi identitas: dua riwayat dengan nomor sama pernah
            // berebut satu row dan upload kedua menimpa arsip riwayat pertama.
            $table->uuid('history_id')->nullable()->after('employee_id');
            $table->index(['employee_id', 'jenis_dokumen', 'history_id'], 'documents_history_lookup_index');
        });

        $this->splitSharedMirrors();
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('history_id');
        });
    }

    /**
     * Memecah mirror yang dipakai bersama beberapa riwayat menjadi satu row
     * per riwayat. Mirror yang file-nya cocok dengan riwayat diklaim; riwayat
     * lain mendapat duplikat row dengan file miliknya sendiri agar arsip tidak
     * lagi menimpa satu sama lain. Seluruhnya query-builder agar portabel
     * lintas PostgreSQL/MySQL/SQLite.
     */
    private function splitSharedMirrors(): void
    {
        foreach (self::HISTORY_MIRRORS as $historyTable => $jenisDokumen) {
            DB::table($historyTable)
                ->select(['id', 'employee_id', 'no_sk', 'tanggal_sk', 'file_sk'])
                ->whereNotNull('file_sk')
                ->orderBy('id')
                ->chunk(200, function ($histories) use ($jenisDokumen): void {
                    foreach ($histories as $history) {
                        $this->claimOrSplitMirror($history, $jenisDokumen);
                    }
                });
        }
    }

    private function claimOrSplitMirror(object $history, string $jenisDokumen): void
    {
        if (DB::table('documents')->where('history_id', $history->id)->exists()) {
            return;
        }

        $candidates = DB::table('documents')
            ->where('employee_id', $history->employee_id)
            ->where('jenis_dokumen', $jenisDokumen)
            ->whereNull('history_id')
            ->when(
                $history->no_sk === null,
                fn ($query) => $query->whereNull('nomor_dokumen'),
                fn ($query) => $query->where('nomor_dokumen', $history->no_sk),
            )
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $match = $candidates->firstWhere('file_path', $history->file_sk) ?? $candidates->first();

        if ($match === null) {
            DB::table('documents')->insert([
                'id' => (string) Str::uuid(),
                'employee_id' => $history->employee_id,
                'history_id' => $history->id,
                'jenis_dokumen' => $jenisDokumen,
                'nama_dokumen' => 'Mirror '.$jenisDokumen.' '.($history->no_sk ?? '(tanpa nomor)'),
                'nomor_dokumen' => $history->no_sk,
                'tanggal_dokumen' => $history->tanggal_sk,
                'file_path' => $history->file_sk,
                'keterangan' => 'Backfill mirror riwayat.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ($match->file_path === $history->file_sk) {
            DB::table('documents')
                ->where('id', $match->id)
                ->update(['history_id' => $history->id, 'updated_at' => now()]);

            return;
        }

        DB::table('documents')->insert([
            'id' => (string) Str::uuid(),
            'employee_id' => $match->employee_id,
            'history_id' => $history->id,
            'jenis_dokumen' => $match->jenis_dokumen,
            'nama_dokumen' => $match->nama_dokumen,
            'nomor_dokumen' => $match->nomor_dokumen,
            'tanggal_dokumen' => $match->tanggal_dokumen,
            'file_path' => $history->file_sk,
            'keterangan' => $match->keterangan,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
