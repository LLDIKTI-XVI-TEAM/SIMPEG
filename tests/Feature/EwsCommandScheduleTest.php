<?php

namespace Tests\Feature;

use App\Services\EwsEngineService;
use Illuminate\Support\Facades\Schedule;
use Mockery\MockInterface;
use Tests\TestCase;

class EwsCommandScheduleTest extends TestCase
{
    public function test_run_ews_command_is_registered_and_calls_service(): void
    {
        $this->mock(EwsEngineService::class, function (MockInterface $mock): void {
            $mock->expects('run');
        });

        $this->artisan('app:run-ews')
            ->expectsOutput('EWS scheduler selesai dijalankan.')
            ->assertExitCode(0);
    }

    public function test_run_ews_command_is_checked_every_five_minutes_after_configured_time(): void
    {
        $events = collect(Schedule::events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'app:run-ews'));

        $this->assertCount(1, $events);

        $event = $events->first();
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertSame('Asia/Makassar', $event->timezone);
    }

    public function test_run_ews_command_schedule_has_overlap_protection(): void
    {
        $events = collect(Schedule::events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'app:run-ews'));

        // Proteksi overlap wajib agar siklus EWS yang lambat tidak dieksekusi ganda
        // oleh jadwal lima menit berikutnya. TTL mutex dibatasi agar proses yang
        // mati paksa tidak memblokir run berikutnya selama 24 jam default.
        $event = $events->sole();
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(30, $event->expiresAt);
    }
}
