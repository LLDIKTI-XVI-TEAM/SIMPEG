<?php

use App\Models\LeaveRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    private array $allowedStatuses = [
        'menunggu_approval',
        'ditangguhkan',
        'ditangguhkan_tugas_dinas',
        'perlu_perubahan',
        'disetujui',
        'tidak_disetujui',
        'dikembalikan_karena_rollover',
    ];

    public function up(): void
    {
        $this->normalizeLegacyStatuses();
        $this->rejectUnknownStatuses();

        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->unsignedSmallInteger('rollover_source_year')->nullable()->after('status');
            $table->unsignedSmallInteger('rollover_target_year')->nullable()->after('rollover_source_year');
        });

        $this->applyPostgresqlConstraints();
        $this->addNotificationPolicies();
    }

    public function down(): void
    {
        $this->stopRollbackWhenRolloverEvidenceExists();
        $this->removeNotificationPolicies();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_rollover_target_year_check');
            DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_status_check');
        }

        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropColumn(['rollover_source_year', 'rollover_target_year']);
        });
    }

    /** Menormalkan hanya token enum historis yang kontraknya sudah diketahui sebelum CHECK dipasang. */
    private function normalizeLegacyStatuses(): void
    {
        foreach ([
            'Draft' => 'menunggu_approval',
            'Disetujui' => 'disetujui',
            'Tidak Disetujui' => 'tidak_disetujui',
        ] as $legacy => $canonical) {
            DB::table('leave_requests')->where('status', $legacy)->update([
                'status' => $canonical,
                'updated_at' => now(),
            ]);
        }
    }

    /** Upgrade tidak boleh menebak token lama yang tidak tercakup kontrak normalisasi. */
    private function rejectUnknownStatuses(): void
    {
        $unknown = DB::table('leave_requests')
            ->whereNotIn('status', $this->allowedStatuses)
            ->distinct()
            ->orderBy('status')
            ->limit(20)
            ->pluck('status')
            ->all();

        if ($unknown !== []) {
            throw new RuntimeException('Migrasi rollover return gagal karena status leave_requests tidak dikenal: '.implode(', ', $unknown).'. Normalisasi data tersebut sebelum menjalankan migrasi kembali.');
        }
    }

    /** Rollback tidak boleh menghapus status, metadata, event, notifikasi, atau audit rollover yang telah menjadi bukti. */
    private function stopRollbackWhenRolloverEvidenceExists(): void
    {
        if (DB::table('leave_requests')
            ->where('status', LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER)
            ->orWhereNotNull('rollover_source_year')
            ->orWhereNotNull('rollover_target_year')
            ->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena leave_requests berisi status atau metadata rollover. Bersihkan bukti rollover melalui proses koreksi yang diaudit.');
        }

        if (DB::table('leave_balance_reservation_events')
            ->where('metadata->release_context', 'rollover_return')
            ->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena event pelepasan reservasi rollover sudah tercatat.');
        }

        if (DB::table('notifications')->where('type', 'cuti.dikembalikan_karena_rollover')->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena notifikasi rollover sudah tercatat.');
        }

        if (DB::table('audit_logs')
            ->whereIn('auditable_type', ['LeaveRequest', LeaveRequest::class])
            ->where('new_values->status', LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER)
            ->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena audit pengembalian rollover sudah tercatat.');
        }

        if (DB::table('audit_logs')
            ->where('event', 'LEAVE_BALANCE_RESERVATION_RELEASED')
            ->where('new_values->release_context', 'rollover_return')
            ->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena audit pelepasan reservasi rollover sudah tercatat.');
        }
    }

    /** Menutup status runtime agar request lama tetap terbaca tetapi token lain ditolak oleh database. */
    private function applyPostgresqlConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $statuses = collect($this->allowedStatuses)
            ->map(fn (string $status): string => "'{$status}'")
            ->implode(', ');

        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_status_check');
        DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_status_check CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_rollover_target_year_check');

        // Metadata rollover wajib lengkap atau kosong sepenuhnya. Baris parsial tidak boleh ada
        // karena resubmit mewajibkan tahun target: pengajuan yang dikembalikan tanpa tahun target
        // akan mentok dan tidak dapat diajukan kembali oleh pegawai.
        DB::statement('ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_rollover_target_year_check CHECK ((rollover_source_year IS NULL AND rollover_target_year IS NULL) OR (rollover_source_year IS NOT NULL AND rollover_target_year IS NOT NULL AND rollover_target_year = rollover_source_year + 1))');
    }

    /** Migration menyediakan policy default tanpa mengandalkan ReferenceSeeder saat upgrade aplikasi. */
    private function addNotificationPolicies(): void
    {
        $channelIds = $this->requiredChannelIds();
        $now = now();

        foreach (['in_app', 'email'] as $channelCode) {
            DB::table('notification_event_channels')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'event_key' => 'cuti.dikembalikan_karena_rollover',
                'notification_channel_id' => $channelIds[$channelCode],
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** Rollback hanya mencabut pasangan policy yang dibuat migration ini. */
    private function removeNotificationPolicies(): void
    {
        $channelIds = $this->requiredChannelIds();

        DB::table('notification_event_channels')
            ->where('event_key', 'cuti.dikembalikan_karena_rollover')
            ->whereIn('notification_channel_id', $channelIds->values()->all())
            ->delete();
    }

    /** @return Collection<string, string> */
    private function requiredChannelIds(): Collection
    {
        $channelIds = DB::table('ref_notification_channels')
            ->whereIn('code', ['in_app', 'email'])
            ->pluck('id', 'code');

        foreach (['in_app', 'email'] as $channelCode) {
            if (! $channelIds->has($channelCode)) {
                throw new RuntimeException("Migrasi rollover return gagal: channel {$channelCode} tidak ditemukan.");
            }
        }

        return $channelIds;
    }
};
