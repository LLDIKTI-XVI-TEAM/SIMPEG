<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\NotificationEventChannel;
use App\Models\Permission;
use App\Models\RefNotificationChannel;
use App\Models\Role;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/notifikasi';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_guest_cannot_read_notifications(): void
    {
        $response = $this->getJson(self::ENDPOINT);

        $response->assertRedirect('/login');
    }

    public function test_notification_service_creates_unread_notification_for_employee(): void
    {
        $employee = Employee::factory()->create();
        $channel = RefNotificationChannel::query()->where('code', 'in_app')->firstOrFail();
        NotificationEventChannel::query()->create([
            'event_key' => 'cuti.diajukan',
            'notification_channel_id' => $channel->id,
            'is_enabled' => true,
        ]);

        $notification = app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'cuti.diajukan',
            title: 'Pengajuan cuti diterima',
            body: 'Pengajuan cuti Anda telah diterima.',
            data: ['leave_request_id' => 'LR-001'],
        );

        $this->assertNotNull($notification);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'user_id' => $employee->id,
            'type' => 'cuti.diajukan',
            'title' => 'Pengajuan cuti diterima',
            'is_read' => false,
        ]);
        $this->assertSame(['leave_request_id' => 'LR-001'], $notification->data);
    }

    public function test_mark_read_route_requires_uuid_notification_id(): void
    {
        [$user] = $this->pegawaiWithEmployee();
        $route = Route::getRoutes()->getByName('api.v1.notifikasi.tandai-dibaca');

        $this->assertNotNull($route);
        $this->assertArrayHasKey('notificationId', $route->wheres);
        $this->assertSame('[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}', $route->wheres['notificationId']);

        $this->actingAs($user);
        $response = $this->patchJsonWithCsrf(self::ENDPOINT.'/not-a-uuid/tandai-dibaca');

        $response->assertNotFound();
    }

    public function test_user_without_employee_mapping_gets_empty_inbox_and_zero_unread_count(): void
    {
        $user = User::factory()->pegawai()->create(['employee_id' => null]);

        $this->actingAs($user);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk();
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('meta.unread_count', 0);

        $countResponse = $this->getJson(self::ENDPOINT.'/jumlah-belum-dibaca');

        $countResponse->assertOk();
        $countResponse->assertJsonPath('data.unread_count', 0);
    }

    public function test_user_only_sees_own_notifications_with_unread_count(): void
    {
        [$user, $employee] = $this->pegawaiWithEmployee();
        $otherEmployee = Employee::factory()->create();
        $this->notificationFor($employee, 'ews.pensiun', 'Peringatan pensiun', true, [
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        $ownUnread = $this->notificationFor($employee, 'cuti.disetujui', 'Cuti disetujui', false, [
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->notificationFor($otherEmployee, 'cuti.diajukan', 'Notifikasi orang lain', false);

        $this->actingAs($user);
        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $ownUnread->id);
        $response->assertJsonPath('data.0.type', 'cuti.disetujui');
        $response->assertJsonPath('data.0.title', 'Cuti disetujui');
        $response->assertJsonPath('data.0.is_read', false);
        $response->assertJsonPath('data.0.data.leave_request_id', 'LR-001');
        $response->assertJsonPath('meta.unread_count', 1);
    }

    public function test_inbox_order_is_deterministic_when_created_at_matches(): void
    {
        [$user, $employee] = $this->pegawaiWithEmployee();
        $sameTime = now()->startOfSecond();
        $lowerUuidTie = $this->notificationFor($employee, 'cuti.diajukan', 'Tie rendah', false, [
            'id' => '00000000-0000-4000-8000-000000000001',
            'created_at' => $sameTime,
            'updated_at' => $sameTime,
        ]);
        $higherUuidTie = $this->notificationFor($employee, 'cuti.disetujui', 'Tie tinggi', false, [
            'id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'created_at' => $sameTime,
            'updated_at' => $sameTime,
        ]);

        $this->actingAs($user);
        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $higherUuidTie->id);
        $response->assertJsonPath('data.1.id', $lowerUuidTie->id);
    }

    public function test_unread_count_is_scoped_to_own_unread_notifications(): void
    {
        [$user, $employee] = $this->pegawaiWithEmployee();
        $otherEmployee = Employee::factory()->create();
        $this->notificationFor($employee, 'cuti.diajukan', 'Cuti diajukan', false);
        $this->notificationFor($employee, 'cuti.disetujui', 'Cuti disetujui', false);
        $this->notificationFor($employee, 'ews.pensiun', 'Sudah dibaca', true);
        $this->notificationFor($otherEmployee, 'cuti.diajukan', 'Orang lain', false);

        $this->actingAs($user);
        $response = $this->getJson(self::ENDPOINT.'/jumlah-belum-dibaca');

        $response->assertOk();
        $response->assertJsonPath('data.unread_count', 2);
    }

    public function test_user_can_mark_own_notification_as_read(): void
    {
        [$user, $employee] = $this->pegawaiWithEmployee();
        $notification = $this->notificationFor($employee, 'cuti.disetujui', 'Cuti disetujui', false);

        $this->actingAs($user);
        $response = $this->patchJsonWithCsrf(self::ENDPOINT."/{$notification->id}/tandai-dibaca");

        $response->assertOk();
        $response->assertJsonPath('data.id', $notification->id);
        $response->assertJsonPath('data.is_read', true);
        $this->assertTrue($notification->refresh()->is_read);
        $this->assertNotNull($notification->read_at);
    }

    public function test_user_cannot_mark_other_employee_notification_as_read(): void
    {
        [$user] = $this->pegawaiWithEmployee();
        $otherNotification = $this->notificationFor(Employee::factory()->create(), 'cuti.diajukan', 'Orang lain', false);

        $this->actingAs($user);
        $response = $this->patchJsonWithCsrf(self::ENDPOINT."/{$otherNotification->id}/tandai-dibaca");

        $response->assertNotFound();
        $this->assertFalse($otherNotification->refresh()->is_read);
    }

    public function test_user_can_mark_all_own_unread_notifications_as_read(): void
    {
        [$user, $employee] = $this->pegawaiWithEmployee();
        $otherEmployee = Employee::factory()->create();
        $first = $this->notificationFor($employee, 'cuti.diajukan', 'Cuti diajukan', false);
        $second = $this->notificationFor($employee, 'cuti.disetujui', 'Cuti disetujui', false);
        $alreadyRead = $this->notificationFor($employee, 'ews.pensiun', 'Sudah dibaca', true);
        $other = $this->notificationFor($otherEmployee, 'cuti.diajukan', 'Orang lain', false);

        $this->actingAs($user);
        $response = $this->patchJsonWithCsrf(self::ENDPOINT.'/tandai-semua-dibaca');

        $response->assertOk();
        $response->assertJsonPath('data.updated', 2);
        $this->assertTrue($first->refresh()->is_read);
        $this->assertTrue($second->refresh()->is_read);
        $this->assertTrue($alreadyRead->refresh()->is_read);
        $this->assertFalse($other->refresh()->is_read);
    }

    public function test_permission_middleware_blocks_notification_update_without_permission(): void
    {
        $role = Role::where('name', 'pegawai')->firstOrFail();
        $permissionId = Permission::where('name', 'notifications.update')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);
        [$user, $employee] = $this->pegawaiWithEmployee();
        $notification = $this->notificationFor($employee, 'cuti.disetujui', 'Cuti disetujui', false);

        $this->actingAs($user);
        $response = $this->patchJsonWithCsrf(self::ENDPOINT."/{$notification->id}/tandai-dibaca");

        $response->assertForbidden();
        $this->assertFalse($notification->refresh()->is_read);
    }

    public function test_old_notification_endpoints_are_not_available(): void
    {
        [$user, $employee] = $this->pegawaiWithEmployee();
        $notification = $this->notificationFor($employee, 'cuti.disetujui', 'Cuti disetujui', false);

        $this->actingAs($user);

        $this->getJson('/api/v1/notifications')->assertNotFound();
        $this->getJson('/api/v1/notifications/unread-count')->assertNotFound();
        $this->patchJsonWithCsrf('/api/v1/notifications/read-all')->assertNotFound();
        $this->patchJsonWithCsrf("/api/v1/notifications/{$notification->id}/read")->assertNotFound();
    }

    /**
     * Membuat user pegawai yang sudah terhubung ke employee seperti hasil mapping SSO valid.
     *
     * @return array{0: User, 1: Employee}
     */
    private function pegawaiWithEmployee(): array
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        return [$user, $employee];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function notificationFor(Employee $employee, string $type, string $title, bool $isRead, array $overrides = []): SimpegNotification
    {
        return SimpegNotification::forceCreate(array_merge([
            'user_id' => $employee->id,
            'type' => $type,
            'title' => $title,
            'body' => 'Isi notifikasi '.$title,
            'data' => ['leave_request_id' => 'LR-001'],
            'is_read' => $isRead,
            'read_at' => $isRead ? now()->subMinute() : null,
        ], $overrides));
    }

    private function patchJsonWithCsrf(string $uri): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->patchJson($uri, [], ['X-CSRF-TOKEN' => 'test-token']);
    }
}
