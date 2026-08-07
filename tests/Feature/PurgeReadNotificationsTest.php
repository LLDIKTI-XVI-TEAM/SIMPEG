<?php

namespace Tests\Feature;

use App\Actions\Notifications\PurgeReadNotificationsAction;
use App\Models\Employee;
use App\Models\SimpegNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeReadNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_deletes_read_notifications_immediately(): void
    {
        $employee = Employee::factory()->create();

        // Buat notifikasi yang sudah dibaca
        $readNotification = SimpegNotification::create([
            'user_id' => $employee->id,
            'type' => 'test.read',
            'title' => 'Test Read',
            'body' => 'This notification has been read.',
            'is_read' => true,
            'read_at' => now(),
        ]);

        // Buat notifikasi yang belum dibaca
        $unreadNotification = SimpegNotification::create([
            'user_id' => $employee->id,
            'type' => 'test.unread',
            'title' => 'Test Unread',
            'body' => 'This notification is unread.',
            'is_read' => false,
            'read_at' => null,
        ]);

        $action = new PurgeReadNotificationsAction;
        $purged = $action->execute();

        // Notifikasi terbaca harus dihapus
        $this->assertSame(1, $purged);
        $this->assertDatabaseMissing('notifications', ['id' => $readNotification->id]);

        // Notifikasi belum dibaca harus tetap ada
        $this->assertDatabaseHas('notifications', ['id' => $unreadNotification->id]);
    }

    public function test_purge_deletes_all_read_notification_types(): void
    {
        $employee = Employee::factory()->create();

        // Buat berbagai jenis notifikasi terbaca
        $types = [
            'ews.kenaikan_pangkat',
            'ews.followup.kenaikan_pangkat',
            'cuti.disetujui',
            'import_pegawai',
        ];

        foreach ($types as $type) {
            SimpegNotification::create([
                'user_id' => $employee->id,
                'type' => $type,
                'title' => "Test {$type}",
                'body' => 'Test body',
                'is_read' => true,
                'read_at' => now(),
            ]);
        }

        $action = new PurgeReadNotificationsAction;
        $purged = $action->execute();

        // Semua notifikasi terbaca dari berbagai tipe harus dihapus
        $this->assertSame(4, $purged);
        $this->assertSame(0, SimpegNotification::query()->where('is_read', true)->count());
    }

    public function test_purge_preserves_unread_notifications_indefinitely(): void
    {
        $employee = Employee::factory()->create();

        // Buat notifikasi belum dibaca yang sudah lama
        $oldUnread = SimpegNotification::create([
            'user_id' => $employee->id,
            'type' => 'test.old_unread',
            'title' => 'Old Unread',
            'body' => 'This is very old but unread.',
            'is_read' => false,
            'read_at' => null,
            'created_at' => now()->subDays(365),
        ]);

        $action = new PurgeReadNotificationsAction;
        $purged = $action->execute();

        // Notifikasi belum dibaca tidak boleh dihapus meski sudah lama
        $this->assertSame(0, $purged);
        $this->assertDatabaseHas('notifications', ['id' => $oldUnread->id]);
    }

    public function test_command_executes_successfully(): void
    {
        $employee = Employee::factory()->create();

        SimpegNotification::create([
            'user_id' => $employee->id,
            'type' => 'test.command',
            'title' => 'Test Command',
            'body' => 'Test command execution.',
            'is_read' => true,
            'read_at' => now(),
        ]);

        $this->artisan('notifications:purge-read')
            ->expectsOutput('Notifikasi terbaca yang dihapus dari database: 1.')
            ->assertExitCode(0);

        $this->assertSame(0, SimpegNotification::query()->where('is_read', true)->count());
    }
}
