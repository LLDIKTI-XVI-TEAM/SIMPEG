<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DisciplineRecord;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DisciplineRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
        Carbon::setTestNow('2026-06-23 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_list_discipline_records_ordered_newest_first(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        DisciplineRecord::create($this->recordPayload($employee, ['tanggal_mulai' => '2026-01-01', 'no_sk' => 'SK-OLD']));
        $sameStartOlder = DisciplineRecord::create($this->recordPayload($employee, ['tanggal_mulai' => '2026-05-01', 'no_sk' => 'SK-SAME-OLDER']));
        $sameStartOlder->forceFill(['created_at' => '2026-05-10 08:00:00'])->save();
        $sameStartNewer = DisciplineRecord::create($this->recordPayload($employee, ['tanggal_mulai' => '2026-05-01', 'no_sk' => 'SK-SAME-NEWER']));
        $sameStartNewer->forceFill(['created_at' => '2026-05-11 08:00:00'])->save();

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}/disiplin");

        $response->assertOk();
        $response->assertJsonPath('employee_id', $employee->id);
        $response->assertJsonCount(3, 'records');
        $response->assertJsonPath('records.0.no_sk', 'SK-SAME-NEWER');
        $response->assertJsonPath('records.1.no_sk', 'SK-SAME-OLDER');
        $response->assertJsonPath('records.2.no_sk', 'SK-OLD');
        $response->assertJsonMissingPath('records.0.employee');
        $response->assertJsonMissingPath('records.0.updated_at');
    }

    public function test_admin_can_create_active_discipline_record_without_end_date_and_write_audit_log(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
            'tanggal_berakhir' => null,
            'is_active' => false,
        ]));

        $response->assertCreated();
        $response->assertJsonPath('message', 'Riwayat disiplin berhasil ditambahkan.');
        $response->assertJsonPath('record.is_active', true);
        $this->assertDatabaseHas('discipline_records', [
            'employee_id' => $employee->id,
            'no_sk' => 'SK-DIS-001',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CREATE',
            'auditable_type' => 'DisciplineRecord',
        ]);
        $audit = AuditLog::where('auditable_type', 'DisciplineRecord')->firstOrFail();
        $newValues = $audit->new_values;

        $this->assertSame('Ringan', Arr::get($newValues, 'jenis_hukuman'));
        $this->assertSame($employee->id, Arr::get($newValues, 'employee_id'));
        $this->assertArrayNotHasKey('deskripsi', $newValues);
        $this->assertArrayNotHasKey('no_sk', $newValues);
        $this->assertArrayNotHasKey('file_sk', $newValues);
    }

    public function test_file_sk_must_be_controlled_relative_pdf_path(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);

        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
            'file_sk' => '../secret.txt',
        ]))->assertJsonValidationErrors(['file_sk']);

        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
            'file_sk' => 'sk/disiplin-aman.pdf',
        ]))->assertCreated();
    }

    public function test_admin_can_create_discipline_record_with_sk_upload(): void
    {
        Storage::fake('public');
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
            'file_sk' => UploadedFile::fake()->create('sk-valid.pdf', 512, 'application/pdf'),
        ]));

        $response->assertCreated();
        $skPath = $response->json('record.file_sk');
        $this->assertIsString($skPath);
        $this->assertStringStartsWith('sk/', $skPath);
        $this->assertStringEndsWith('.pdf', $skPath);
        Storage::disk('public')->assertExists($skPath);
        $this->assertDatabaseHas('discipline_records', [
            'employee_id' => $employee->id,
            'file_sk' => $skPath,
        ]);
    }

    public function test_sk_upload_rejects_disallowed_extension_even_when_content_is_pdf(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
            'file_sk' => UploadedFile::fake()->create('sk-invalid.txt', 512, 'application/pdf'),
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['file_sk']);
    }

    public function test_active_status_is_computed_for_future_today_and_past_end_dates(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $this->actingAs($user);

        $future = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
            'no_sk' => 'SK-FUTURE',
            'tanggal_berakhir' => '2026-06-24',
        ]));
        $today = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
            'no_sk' => 'SK-TODAY',
            'tanggal_berakhir' => '2026-06-23',
        ]));
        $past = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
            'no_sk' => 'SK-PAST',
            'tanggal_berakhir' => '2026-06-22',
        ]));

        $future->assertJsonPath('record.is_active', true);
        $today->assertJsonPath('record.is_active', true);
        $past->assertJsonPath('record.is_active', false);
    }

    public function test_pegawai_cannot_access_discipline_records(): void
    {
        $user = User::factory()->pegawai()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);

        $this->getJson("/api/v1/pegawai/{$employee->id}/disiplin")->assertForbidden();
        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload())->assertForbidden();
    }

    public function test_permission_removal_forbids_read_and_create(): void
    {
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $role->permissions()->detach(Permission::whereIn('name', [
            'discipline_records.read',
            'discipline_records.create',
        ])->pluck('id'));
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);

        $this->getJson("/api/v1/pegawai/{$employee->id}/disiplin")->assertForbidden();
        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload())->assertForbidden();
    }

    public function test_validation_rejects_invalid_discipline_payload(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", [
            'jenis_hukuman' => 'Sangat Berat',
            'deskripsi' => '',
            'tanggal_mulai' => '2026-06-23',
            'tanggal_berakhir' => '2026-06-22',
            'no_sk' => '',
            'tanggal_sk' => 'bukan-tanggal',
            'file_sk' => str_repeat('a', 256),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors([
            'jenis_hukuman',
            'deskripsi',
            'tanggal_berakhir',
            'no_sk',
            'tanggal_sk',
            'file_sk',
        ]);
    }

    private function postJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function postWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token', 'Accept' => 'application/json']);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'jenis_hukuman' => 'Ringan',
            'deskripsi' => 'Teguran tertulis karena pelanggaran kedisiplinan.',
            'tanggal_mulai' => '2026-06-01',
            'tanggal_berakhir' => '2026-06-30',
            'no_sk' => 'SK-DIS-001',
            'tanggal_sk' => '2026-05-25',
            'file_sk' => 'sk/disiplin-001.pdf',
        ], $overrides);
    }

    private function recordPayload(Employee $employee, array $overrides = []): array
    {
        return array_merge($this->validPayload(), [
            'employee_id' => $employee->id,
            'is_active' => true,
        ], $overrides);
    }
}
