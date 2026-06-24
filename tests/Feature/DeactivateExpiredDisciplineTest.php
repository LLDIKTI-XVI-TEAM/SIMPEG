<?php

namespace Tests\Feature;

use App\Models\DisciplineRecord;
use App\Models\Employee;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class DeactivateExpiredDisciplineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        Carbon::setTestNow('2026-06-23 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_command_deactivates_only_expired_active_records_and_is_idempotent(): void
    {
        $employee = Employee::factory()->create();
        $expired = DisciplineRecord::create($this->recordPayload($employee, [
            'no_sk' => 'SK-EXPIRED',
            'tanggal_berakhir' => '2026-06-22',
            'is_active' => true,
        ]));
        $today = DisciplineRecord::create($this->recordPayload($employee, [
            'no_sk' => 'SK-TODAY',
            'tanggal_berakhir' => '2026-06-23',
            'is_active' => true,
        ]));
        $openEnded = DisciplineRecord::create($this->recordPayload($employee, [
            'no_sk' => 'SK-OPEN',
            'tanggal_berakhir' => null,
            'is_active' => true,
        ]));
        $alreadyInactive = DisciplineRecord::create($this->recordPayload($employee, [
            'no_sk' => 'SK-INACTIVE',
            'tanggal_berakhir' => '2026-06-01',
            'is_active' => false,
        ]));

        $this->artisan('discipline-records:deactivate-expired')
            ->expectsOutput('1 riwayat disiplin kedaluwarsa dinonaktifkan.')
            ->assertExitCode(0);

        $this->assertFalse($expired->fresh()->is_active);
        $this->assertTrue($today->fresh()->is_active);
        $this->assertTrue($openEnded->fresh()->is_active);
        $this->assertFalse($alreadyInactive->fresh()->is_active);

        $this->artisan('discipline-records:deactivate-expired')
            ->expectsOutput('0 riwayat disiplin kedaluwarsa dinonaktifkan.')
            ->assertExitCode(0);
    }

    public function test_command_is_scheduled_daily_at_0700(): void
    {
        $event = collect(Schedule::events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'discipline-records:deactivate-expired'));

        $this->assertNotNull($event);
        $this->assertSame('0 7 * * *', $event->expression);
        $this->assertSame(config('app.timezone'), $event->timezone);
    }

    private function recordPayload(Employee $employee, array $overrides = []): array
    {
        return array_merge([
            'employee_id' => $employee->id,
            'jenis_hukuman' => 'Ringan',
            'deskripsi' => 'Teguran tertulis.',
            'tanggal_mulai' => '2026-06-01',
            'tanggal_berakhir' => '2026-06-30',
            'no_sk' => 'SK-DIS-001',
            'tanggal_sk' => '2026-05-25',
            'is_active' => true,
        ], $overrides);
    }
}
