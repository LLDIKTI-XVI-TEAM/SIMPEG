<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_admin_can_filter_audit_logs_by_event_period_user_and_auditable_type(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $actor = User::factory()->pegawai()->create();
        $otherActor = User::factory()->pegawai()->create();

        $this->createAuditLog([
            'user_id' => $actor->id,
            'event' => 'CREATE',
            'auditable_type' => 'Employee',
            'created_at' => '2026-06-10 09:00:00',
        ]);
        $matched = $this->createAuditLog([
            'user_id' => $actor->id,
            'event' => 'UPDATE',
            'auditable_type' => 'Employee',
            'auditable_id' => '11111111-1111-4111-8111-111111111111',
            'old_values' => ['nama_lengkap' => 'Nama Lama'],
            'new_values' => ['nama_lengkap' => 'Nama Baru'],
            'created_at' => '2026-06-20 10:30:00',
        ]);
        $this->createAuditLog([
            'user_id' => $otherActor->id,
            'event' => 'UPDATE',
            'auditable_type' => 'Employee',
            'created_at' => '2026-06-20 11:00:00',
        ]);
        $this->createAuditLog([
            'user_id' => $actor->id,
            'event' => 'UPDATE',
            'auditable_type' => 'User',
            'created_at' => '2026-06-20 12:00:00',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/audit-log?'.http_build_query([
            'event' => 'UPDATE',
            'from' => '2026-06-15',
            'to' => '2026-06-20',
            'user_id' => $actor->id,
            'auditable_type' => 'Employee',
            'per_page' => 5,
        ]));

        $response->assertOk();
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('per_page', 5);
        $response->assertJsonPath('data.0.id', $matched->id);
        $response->assertJsonPath('data.0.event', 'UPDATE');
        $response->assertJsonPath('data.0.auditable_type', 'Employee');
        $response->assertJsonPath('data.0.old_values.nama_lengkap', 'Nama Lama');
        $response->assertJsonPath('data.0.new_values.nama_lengkap', 'Nama Baru');
    }

    public function test_audit_log_index_orders_newest_first_and_caps_per_page_at_one_hundred(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $secondOldest = null;
        $latest = null;

        for ($index = 1; $index <= 101; $index++) {
            $auditLog = $this->createAuditLog([
                'event' => 'LOGIN',
                'auditable_type' => 'User',
                'created_at' => sprintf('2026-06-20 %02d:%02d:00', intdiv($index - 1, 60), ($index - 1) % 60),
            ]);

            if ($index === 2) {
                $secondOldest = $auditLog;
            }

            if ($index === 101) {
                $latest = $auditLog;
            }
        }

        $response = $this->actingAs($admin)->getJson('/api/v1/audit-log?per_page=999');

        $response->assertOk();
        $response->assertJsonPath('per_page', 100);
        $response->assertJsonPath('total', 101);
        $response->assertJsonCount(100, 'data');
        $response->assertJsonPath('data.0.id', $latest->id);
        $response->assertJsonPath('data.99.id', $secondOldest->id);
    }

    /**
     * Membuat audit log dengan timestamp eksplisit agar tes filter periode deterministik.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createAuditLog(array $attributes = []): AuditLog
    {
        $createdAt = $attributes['created_at'] ?? now();
        unset($attributes['created_at']);

        $auditLog = AuditLog::query()->create(array_merge([
            'user_id' => null,
            'user_name' => 'Tester',
            'event' => 'CREATE',
            'auditable_type' => 'Employee',
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ], $attributes));

        $auditLog->forceFill(['created_at' => $createdAt])->save();

        return $auditLog->refresh();
    }
}
