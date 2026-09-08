<?php

namespace Tests\Feature;

use App\Actions\Cuti\CancelManualLeaveUsageAction;
use App\Actions\Cuti\CorrectManualLeaveUsageAction;
use App\Actions\Cuti\DownloadLeaveUsageDocumentAction;
use App\Actions\Cuti\StoreManualLeaveUsageAction;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveUsageRecord;
use App\Models\Permission;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\Role;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManualLeaveUsageDelegationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 7)->startOfDay());
        $this->seed(RbacSeeder::class);
    }

    public static function delegatedRoles(): array
    {
        return [['super_admin'], ['pimpinan'], ['kepala_bagian'], ['pegawai']];
    }

    #[DataProvider('delegatedRoles')]
    public function test_grant_memungkinkan_lifecycle_fakta_dan_revoke_menolak_akses(string $role): void
    {
        $employee = $this->employee();
        $actor = $this->actorFor($role, $employee);
        $this->grant($role);
        $payload = $this->payload();

        $this->actingAs($actor)->post(route('cuti.manual.store', $employee), $payload + [
            'dokumen' => UploadedFile::fake()->createWithContent('bukti.pdf', "%PDF-1.4\nBukti sintetis cuti.\n%%EOF"),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $record = LeaveUsageRecord::query()->sole();
        $document = $record->documents()->sole();
        $this->assertSame($actor->id, $record->recorded_by);
        $this->assertSame(2, $record->workdays);
        $this->assertSame(22, $this->balance($employee));
        $this->assertSame(2, $record->externalApprovalSteps()->count());
        $this->get(route('cuti.manual.download', [$record, $document]))->assertOk();

        $correction = array_replace($payload, [
            'tanggal_selesai' => '2026-08-03',
            'correction_reason' => 'Periode faktual satu hari.',
        ]);
        $this->post(route('cuti.manual.correct', $record), $correction)
            ->assertRedirect()->assertSessionHasNoErrors();
        $replacement = LeaveUsageRecord::query()->where('record_status', 'active')->sole();
        $this->assertSame('superseded', $record->fresh()->record_status);
        $this->assertSame(23, $this->balance($employee));
        $this->assertSame(2, $replacement->externalApprovalSteps()->count());
        $this->post(route('cuti.manual.cancel', $replacement), ['correction_reason' => 'Fakta dibatalkan.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $replacement->fresh()->record_status);
        $this->assertSame(24, $this->balance($employee));
        $this->assertDatabaseCount('leave_requests', 0);
        $this->assertDatabaseCount('leave_usage_records', 2);
        foreach ([
            'manual_usage_recorded' => $record->id,
            'manual_usage_corrected' => $replacement->id,
            'manual_usage_cancelled' => $replacement->id,
        ] as $operation => $recordId) {
            $this->assertDatabaseHas('audit_logs', [
                'auditable_type' => 'LeaveUsageRecord', 'auditable_id' => $recordId,
                'user_id' => $actor->id, 'new_values->operation' => $operation,
                'new_values->actor_role' => $role,
            ]);
        }

        $this->grant($role, false);
        $this->post(route('cuti.manual.store', $employee), $payload)->assertForbidden();
        $this->post(route('cuti.manual.correct', $record), $correction)->assertForbidden();
        $this->post(route('cuti.manual.cancel', $replacement), ['correction_reason' => 'Tidak berizin.'])->assertForbidden();
        $this->get(route('cuti.manual.download', [$record, $document]))->assertForbidden();
        try {
            app(CancelManualLeaveUsageAction::class)->execute($replacement->id, 'Tidak berizin.', $actor);
            $this->fail('Action langsung harus membaca pencabutan permission.');
        } catch (AuthorizationException) {
            $this->assertSame('cancelled', $replacement->fresh()->record_status);
        }
    }

    public static function scopedRoles(): array
    {
        return [['kepala_bagian'], ['pegawai']];
    }

    #[DataProvider('scopedRoles')]
    public function test_target_asing_ditolak_di_http_dan_action_tanpa_mutasi_atau_file(string $role): void
    {
        $own = $this->employee();
        $actor = $this->actorFor($role, $own);
        $this->grant($role);
        $foreign = $this->employee();
        $admin = User::factory()->adminKepegawaian()->create();
        $record = app(StoreManualLeaveUsageAction::class)->execute(
            $foreign->id, $this->payload(), UploadedFile::fake()->create('privat.pdf', 20, 'application/pdf'), $admin,
        );
        $document = $record->documents()->sole();
        $correction = $this->payload() + ['correction_reason' => 'Target asing.'];
        $before = $this->sideEffects();
        $this->actingAs($actor);
        $this->post(route('cuti.manual.store', $foreign), $this->payload())->assertNotFound();
        $this->post(route('cuti.manual.correct', $record), $correction)->assertNotFound();
        $this->post(route('cuti.manual.cancel', $record), ['correction_reason' => 'Target asing.'])->assertNotFound();
        $this->get(route('cuti.manual.download', [$record, $document]))->assertNotFound();

        foreach ([
            fn () => app(StoreManualLeaveUsageAction::class)->execute($foreign->id, $this->payload(), null, $actor),
            fn () => app(CorrectManualLeaveUsageAction::class)->execute($record->id, $correction, null, $actor),
            fn () => app(CancelManualLeaveUsageAction::class)->execute($record->id, 'Target asing.', $actor),
            fn () => app(DownloadLeaveUsageDocumentAction::class)->execute($record->id, $document->id, $actor),
        ] as $call) {
            try {
                $call();
                $this->fail('Action langsung tidak boleh melewati scope pemilik fakta.');
            } catch (ModelNotFoundException) {
                $this->assertSame('active', $record->fresh()->record_status);
            }
        }
        $this->assertSame($before, $this->sideEffects());
    }

    public function test_approver_di_luar_scope_dapat_dipilih_tanpa_membuka_fakta_miliknya(): void
    {
        $employee = $this->employee();
        $actor = $this->actorFor('pegawai', $employee);
        $this->grant('pegawai');
        $approver = $this->employee();
        $payload = $this->payload();
        $payload['approval_steps'][0] = [
            'step_type' => 'kepala_bagian', 'approver_source' => 'simpeg_employee',
            'approver_employee_id' => $approver->id, 'acted_on' => '2020-01-01',
        ];
        $this->actingAs($actor)->post(route('cuti.manual.store', $employee), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('leave_usage_external_approval_steps', ['approver_employee_id' => $approver->id]);
        $this->post(route('cuti.manual.store', $approver), $this->payload())->assertNotFound();
        $this->assertDatabaseCount('leave_usage_records', 1);
    }

    private function grant(string $role, bool $enabled = true): void
    {
        $permission = Permission::query()->where('name', 'cuti.manual.manage')->sole();
        $permissions = Role::query()->where('name', $role)->sole()->permissions();
        $enabled ? $permissions->syncWithoutDetaching([$permission->id]) : $permissions->detach($permission->id);
    }

    private function actorFor(string $role, Employee $target): User
    {
        $identity = $role === 'pegawai' ? $target : $this->employee();
        $actor = User::factory()->create(['role' => $role, 'employee_id' => $identity->id]);
        if ($role === 'kepala_bagian') {
            SupervisorAssignment::query()->create([
                'employee_id' => $target->id, 'supervisor_id' => $identity->id, 'tanggal_mulai' => '2026-01-01',
            ]);
        }

        return $actor;
    }

    private function employee(): Employee
    {
        $type = RefJenisPegawai::query()->firstOrCreate(['nama' => 'PNS']);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $type->id]);
        Appointment::query()->create(['employee_id' => $employee->id, 'jenis_pengangkatan' => 'PNS', 'tmt_pengangkatan' => '2020-01-01']);

        return $employee;
    }

    private function payload(): array
    {
        $type = RefJenisCuti::query()->firstOrCreate(['code' => 'tahunan'], [
            'nama' => 'Cuti Tahunan', 'mengurangi_saldo_tahunan' => true, 'khusus_pns' => false,
        ]);

        return [
            'leave_type_id' => $type->id, 'tanggal_mulai' => '2026-08-03', 'tanggal_selesai' => '2026-08-04',
            'alasan' => 'Fakta persetujuan di luar SIMPEG.', 'approval_steps' => $this->validManualApprovalPayload(),
        ];
    }

    private function balance(Employee $employee): int
    {
        return LeaveBalance::query()->where('employee_id', $employee->id)->where('tahun', 2026)->sole()->sisa;
    }

    private function sideEffects(): array
    {
        return [
            DB::table('leave_usage_records')->count(), DB::table('leave_balance_ledger')->count(),
            DB::table('audit_logs')->count(), DB::table('leave_usage_documents')->count(),
            Storage::disk('local')->allFiles(),
        ];
    }
}
