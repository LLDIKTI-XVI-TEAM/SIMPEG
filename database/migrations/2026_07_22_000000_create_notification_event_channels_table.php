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
        Schema::create('notification_event_channels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_key', 100);
            // Cegah channel global terhapus saat masih dirujuk agar konfigurasi operator tidak rusak diam-diam.
            $table->foreignUuid('notification_channel_id')
                ->constrained('ref_notification_channels')
                ->restrictOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();

            $table->unique(['event_key', 'notification_channel_id']);
        });

        // Deployment produksi dapat menjalankan migrate tanpa seeder, sehingga kebijakan aktif wajib tersedia sejak migrasi.
        $channelIds = DB::table('ref_notification_channels')
            ->whereIn('code', ['in_app', 'email'])
            ->pluck('id', 'code');

        foreach (['in_app', 'email'] as $requiredChannel) {
            if (! $channelIds->has($requiredChannel)) {
                throw new RuntimeException("Migrasi kebijakan notifikasi gagal: channel {$requiredChannel} tidak ditemukan.");
            }
        }

        $normalEvents = [
            'cuti.pengajuan_baru',
            'cuti.menunggu_persetujuan',
            'cuti.disetujui',
            'cuti.ditunda',
            'cuti.perlu_perubahan',
            'cuti.tidak_disetujui',
            'ews.kenaikan_pangkat',
            'ews.kgb',
            'ews.pensiun',
            'ews.kontrak_pppk',
            'ews.satyalancana',
        ];
        $now = now();
        $policies = [];

        // Daftar historis ini sengaja lokal agar migrasi tetap immutable saat dukungan event runtime berkembang.
        foreach ($normalEvents as $eventKey) {
            foreach (['in_app', 'email'] as $channelCode) {
                $policies[] = [
                    'id' => (string) Str::uuid(),
                    'event_key' => $eventKey,
                    'notification_channel_id' => $channelIds[$channelCode],
                    'is_enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Kegagalan scheduler memakai kebijakan operasional in-app terpisah dan tidak mengaktifkan email.
        $policies[] = [
            'id' => (string) Str::uuid(),
            'event_key' => 'ews.scheduler_failed',
            'notification_channel_id' => $channelIds['in_app'],
            'is_enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('notification_event_channels')->insert($policies);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_event_channels');
    }
};
