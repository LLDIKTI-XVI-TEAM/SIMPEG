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
            $mock->shouldReceive('run')->once();
        });

        $this->artisan('app:run-ews')
            ->expectsOutput('EWS scheduler selesai dijalankan.')
            ->assertExitCode(0);
    }

    public function test_run_ews_command_is_scheduled_daily_from_default_config_time(): void
    {
        $event = collect(Schedule::events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'app:run-ews'));

        $this->assertNotNull($event);
        $this->assertSame('0 7 * * *', $event->expression);
        $this->assertSame('Asia/Makassar', $event->timezone);
    }
}
