<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Lapis kedua penegakan sifat append-only audit log.
     *
     * Penjaga pada model hanya menutup jalur Eloquent. Trigger ini menutup jalur yang melewati
     * model, yaitu query builder, tinker, seeder, dan sesi basis data langsung, sehingga jejak
     * siapa mengubah data pegawai tidak dapat dirapikan tanpa meninggalkan bekas.
     *
     * Pengosongan tabel lewat TRUNCATE ikut ditolak karena kehilangan seluruh riwayat lebih berat
     * daripada mutasi satu baris. Pelepasan tabel lewat DROP tidak dapat dijaga trigger, dan
     * memang tetap diperlukan oleh pembatalan migrasi serta penyiapan basis data pengujian.
     * Perlindungan penuh terhadap pemilik tabel hanya mungkin bila peran aplikasi dipisahkan dari
     * peran pemilik skema, dan itu keputusan penggelaran, bukan keputusan migrasi ini.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            create or replace function tolak_mutasi_audit_logs() returns trigger as $$
            begin
                raise exception 'Audit log bersifat append-only sehingga operasi % ditolak.', tg_op
                    using errcode = 'restrict_violation';
            end;
            $$ language plpgsql;

            drop trigger if exists audit_logs_append_only on audit_logs;
            drop trigger if exists audit_logs_append_only_truncate on audit_logs;

            create trigger audit_logs_append_only
                before update or delete on audit_logs
                for each row execute function tolak_mutasi_audit_logs();

            -- TRUNCATE tidak mengenal pemicu per baris sehingga penjaganya dipasang per pernyataan.
            create trigger audit_logs_append_only_truncate
                before truncate on audit_logs
                for each statement execute function tolak_mutasi_audit_logs();
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Trigger dilepas lebih dahulu supaya fungsi tidak lagi dirujuk saat dihapus, dan supaya
        // migrasi lain yang membangun ulang tabel audit tetap dapat berjalan setelah rollback.
        DB::unprepared('drop trigger if exists audit_logs_append_only on audit_logs;');
        DB::unprepared('drop trigger if exists audit_logs_append_only_truncate on audit_logs;');
        DB::unprepared('drop function if exists tolak_mutasi_audit_logs();');
    }
};
