<?php

namespace Tests\Feature;

use App\Models\NotificationEventChannel;
use App\Models\RefNotificationChannel;
use App\Models\User;
use App\Services\Notifications\NotificationEventCatalog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationChannelPageTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE_URI = '/data-master/channel-notifikasi';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbac();
    }

    public function test_super_admin_melihat_halaman_dan_seluruh_15_event_dari_katalog(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $catalog = app(NotificationEventCatalog::class);

        $response = $this->actingAs($admin)->get(self::PAGE_URI);

        $response->assertOk()
            ->assertSee('Konfigurasi Channel Notifikasi')
            ->assertSee('Channel Notifikasi');

        foreach ($catalog->events() as $eventKey => $event) {
            $response
                ->assertSee($event['label'])
                ->assertSee('data-event-key="'.$eventKey.'"', false);
        }

        $response->assertSee('data-event-key="cuti.ditangguhkan_tugas_dinas"', false);
        $response->assertSee('data-event-key="cuti.dikembalikan_karena_rollover"', false);
        $this->assertSame(15, substr_count($response->getContent(), 'data-event-key="'));
    }

    public function test_role_selain_super_admin_ditolak_dari_halaman(): void
    {
        foreach (['adminKepegawaian', 'pimpinan', 'kepalaBagian', 'pegawai'] as $factoryState) {
            $user = User::factory()->{$factoryState}()->create();

            $this->actingAs($user)
                ->get(self::PAGE_URI)
                ->assertForbidden();
        }
    }

    public function test_halaman_membedakan_policy_tersimpan_dan_status_efektif_saat_master_nonaktif(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $email = RefNotificationChannel::query()->where('code', 'email')->firstOrFail();
        $inApp = RefNotificationChannel::query()->where('code', 'in_app')->firstOrFail();
        $email->forceFill(['is_enabled' => false])->save();
        NotificationEventChannel::query()
            ->where('notification_channel_id', $email->id)
            ->where('event_key', 'cuti.disetujui')
            ->update(['is_enabled' => true]);
        NotificationEventChannel::query()
            ->where('notification_channel_id', $inApp->id)
            ->where('event_key', 'cuti.ditunda')
            ->update(['is_enabled' => false]);

        $response = $this->actingAs($admin)->get(self::PAGE_URI);

        $response->assertOk()
            ->assertSee('data-channel-code="email"', false)
            ->assertSee('data-channel-enabled="false"', false)
            ->assertSee('data-policy-event="cuti.disetujui" data-policy-channel="email" data-policy-raw="enabled" data-policy-effective="disabled"', false)
            ->assertSee('Dipilih, belum efektif')
            ->assertSee('Master channel nonaktif');

        $html = $response->getContent();
        $this->assertPolicyCellHasAccessibleStatus(
            $this->policyCellMarkup($html, 'cuti.disetujui', 'email'),
            'Policy tersimpan aktif. Status efektif nonaktif. Master channel nonaktif.',
        );
        $this->assertPolicyCellHasAccessibleStatus(
            $this->policyCellMarkup($html, 'cuti.disetujui', 'in_app'),
            'Policy tersimpan aktif. Status efektif aktif. Policy dan master aktif.',
        );
        $this->assertPolicyCellHasAccessibleStatus(
            $this->policyCellMarkup($html, 'cuti.ditunda', 'in_app'),
            'Policy tersimpan nonaktif. Status efektif nonaktif. Policy tidak dipilih.',
        );
    }

    public function test_channel_tanpa_adapter_dilabeli_belum_tersedia_dan_tidak_memiliki_kontrol_policy(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->get(self::PAGE_URI);

        $response->assertOk()
            ->assertSee('data-channel-code="whatsapp_business"', false)
            ->assertSee('data-adapter-available="false"', false)
            ->assertSee('Belum tersedia')
            ->assertDontSee('data-policy-control="whatsapp_business"', false)
            ->assertSee('data-policy-control="email"', false)
            ->assertSee('data-policy-unsupported="ews.scheduler_failed:email"', false);
    }

    public function test_halaman_tidak_mengekspos_config_credential_atau_data_dummy(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $email = RefNotificationChannel::query()->where('code', 'email')->firstOrFail();
        $email->forceFill([
            'config' => [
                'smtp_password' => 'SANGAT-RAHASIA-CHANNEL-123',
                'api_token' => 'TOKEN-PRIVAT-CHANNEL-456',
            ],
        ])->save();

        $response = $this->actingAs($admin)->get(self::PAGE_URI);

        $response->assertOk()
            ->assertDontSee('SANGAT-RAHASIA-CHANNEL-123')
            ->assertDontSee('TOKEN-PRIVAT-CHANNEL-456')
            ->assertDontSee('smtp_password')
            ->assertDontSee('api_token')
            ->assertDontSee('Editor JSON')
            ->assertDontSee('Channel Dummy')
            ->assertDontSee('Contoh channel');
    }

    public function test_peringatan_nonaktifkan_in_app_memakai_dialog_native_dan_copy_yang_disetujui(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $warning = 'Menonaktifkan kanal In-App akan menghentikan pembuatan notifikasi di dalam aplikasi. Pada alur saat ini, sebagian pengiriman email bergantung pada notifikasi In-App sehingga email terkait juga dapat tidak terkirim. Konfigurasi per event tetap disimpan dan akan berlaku kembali ketika kanal diaktifkan. Lanjutkan?';

        $response = $this->actingAs($admin)->get(self::PAGE_URI);

        $response->assertOk()
            ->assertSee('aria-controls="in-app-disable-dialog"', false);

        $matched = preg_match('/<dialog id="in-app-disable-dialog".*?<\/dialog>/s', $response->getContent(), $matches);
        $this->assertSame(1, $matched, 'Dialog peringatan In-App harus tersedia sebagai satu elemen utuh.');

        $dialogMarkup = $matches[0];
        $this->assertStringContainsString('aria-labelledby="in-app-disable-title"', $dialogMarkup);
        $this->assertStringContainsString('aria-describedby="in-app-disable-warning"', $dialogMarkup);
        $this->assertStringContainsString('id="in-app-disable-warning"', $dialogMarkup);
        $this->assertStringContainsString($warning, $dialogMarkup);
        $this->assertMatchesRegularExpression('/<button type="button"[^>]*>Batal<\/button>/', $dialogMarkup);
        $this->assertMatchesRegularExpression('/<button type="submit"[^>]*>Ya, nonaktifkan<\/button>/', $dialogMarkup);
        $this->assertStringContainsString('focus-visible:ring-2', $dialogMarkup);
    }

    public function test_channel_inti_diprioritaskan_pada_halaman_pertama(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->createCustomChannels(12);

        $response = $this->actingAs($admin)->get(self::PAGE_URI);

        $response->assertOk()
            ->assertSee('data-channel-code="in_app"', false)
            ->assertSee('data-channel-code="email"', false)
            ->assertSee('data-channel-code="whatsapp_business"', false);
        $this->assertSame(12, substr_count($response->getContent(), 'data-channel-code="'));
    }

    public function test_pagination_menjangkau_channel_ke_51_dengan_query_dan_payload_tetap_bounded(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channels = $this->createCustomChannels(51);
        $lastChannel = $channels[50];
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($admin)->get(self::PAGE_URI.'?page=5');

        $response->assertOk()
            ->assertSee('data-channel-code="custom_051"', false)
            ->assertSee(route('data-master.channel-notifikasi.update', $lastChannel->id), false)
            ->assertSee(route('data-master.channel-notifikasi.destroy', $lastChannel->id), false)
            ->assertSee('page=4', false)
            ->assertDontSee('data-channel-code="in_app"', false);
        $this->assertSame(6, substr_count($response->getContent(), 'data-channel-code="'));
        $this->assertLessThan(20, count($queries), 'Page lanjutan channel notifikasi melebihi budget 20 query.');
        $this->assertSame(
            1,
            collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'notification_event_channels'))->count(),
            'Policy page lanjutan harus tetap dimuat lewat satu eager-load query.',
        );
    }

    public function test_halaman_memakai_kurang_dari_20_query_dan_satu_eager_load_policy(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($admin)->get(self::PAGE_URI);

        $response->assertOk();
        $this->assertLessThan(20, count($queries), 'Halaman channel notifikasi melebihi budget 20 query.');
        $this->assertSame(
            1,
            collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'notification_event_channels'))->count(),
            'Policy event-channel harus dimuat sekali melalui eager loading, bukan dari Blade.',
        );
    }

    /** @return list<RefNotificationChannel> */
    private function createCustomChannels(int $count): array
    {
        $channels = [];

        for ($number = 1; $number <= $count; $number++) {
            $suffix = str_pad((string) $number, 3, '0', STR_PAD_LEFT);
            $channels[] = RefNotificationChannel::query()->create([
                'code' => 'custom_'.$suffix,
                'name' => 'Channel tambahan '.$suffix,
            ]);
        }

        return $channels;
    }

    private function policyCellMarkup(string $html, string $eventKey, string $channelCode): string
    {
        $pattern = '/<td(?=[^>]*data-policy-event="'.preg_quote($eventKey, '/').'")(?=[^>]*data-policy-channel="'.preg_quote($channelCode, '/').'")[^>]*>.*?<\/td>/s';
        $matched = preg_match($pattern, $html, $matches);

        $this->assertSame(1, $matched, "Cell policy {$eventKey}:{$channelCode} harus tersedia.");

        return $matches[0];
    }

    private function assertPolicyCellHasAccessibleStatus(string $cellMarkup, string $expectedStatus): void
    {
        $matched = preg_match('/<button[^>]*aria-describedby="([^"]+)"[^>]*>/s', $cellMarkup, $matches);

        $this->assertSame(1, $matched, 'Kontrol policy harus merujuk deskripsi status aksesibel.');
        $statusId = preg_quote($matches[1], '/');
        $this->assertMatchesRegularExpression(
            '/<span id="'.$statusId.'"[^>]*>\s*'.preg_quote($expectedStatus, '/').'\s*<\/span>/s',
            $cellMarkup,
        );
    }
}
