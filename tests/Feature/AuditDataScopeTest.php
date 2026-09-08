<?php

namespace Tests\Feature;

use App\Actions\Audit\ListAuditLogPageAction;
use App\Actions\Audit\ListAuditLogsAction;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\RefJenisCuti;
use App\Models\Role;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Permission audit tidak membuka record, detail, atau pilihan filter milik pegawai di luar scope. */
class AuditDataScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-08 09:00:00');
        $this->seedReferenceData();
        $this->seedRbac();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('scopedRoles')]
    public function test_http_daftar_detail_dan_filter_hanya_membuka_target_dalam_scope(string $role): void
    {
        $identity = Employee::factory()->create();
        $reader = User::factory()->create(['role' => $role, 'employee_id' => $identity->id]);
        $target = $role === 'pegawai' ? $identity : Employee::factory()->create();
        if ($role === 'kepala_bagian') {
            SupervisorAssignment::query()->create([
                'employee_id' => $target->id, 'supervisor_id' => $identity->id,
                'tanggal_mulai' => '2026-09-01',
            ]);
        }
        $permission = Permission::query()->where('name', 'audit_logs.read')->sole();
        $readerRole = Role::query()->where('name', $role)->sole();
        $readerRole->permissions()->syncWithoutDetaching([$permission->id]);
        $visible = $this->log('Employee', $target->id, 'Operator dalam scope');
        $hidden = $this->log('Employee', Employee::factory()->create()->id, 'Operator luar scope');
        $system = $this->log('Setting', null, 'Operator sistem');
        $missing = $this->log('Employee', (string) Str::uuid(), 'Operator target hilang');
        $unknown = $this->log('UnknownTarget', $target->id, 'Operator target asing');
        $rawBefore = $hidden->fresh()->getRawOriginal();

        $this->actingAs($reader)->getJson('/api/v1/audit-log')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $visible->id);
        $page = $this->get('/dashboard/audit')->assertOk()
            ->assertSee('Operator dalam scope')
            ->assertSee('href="'.route('audit-log').'"', false);
        foreach ([$hidden, $system, $missing, $unknown] as $log) {
            $page->assertDontSee($log->user_name);
            $this->get('/dashboard/audit/'.$log->id)->assertNotFound();
            $this->getJson('/api/v1/audit-log?user_id='.$log->user_id)
                ->assertOk()->assertJsonPath('total', 0);
        }
        $this->assertSame(['Employee'], $page->viewData('modulOptions'));
        $this->assertSame(['Operator dalam scope'], $page->viewData('operatorOptions'));
        $this->get('/dashboard/audit/'.$visible->id)->assertOk()->assertSee('Operator dalam scope');
        $this->get('/dashboard/audit/bukan-uuid')->assertNotFound();
        $this->assertSame($rawBefore, $hidden->fresh()->getRawOriginal());

        $readerRole->permissions()->detach($permission->id);
        foreach (['/dashboard/audit', '/dashboard/audit/'.$visible->id, '/api/v1/audit-log'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public static function scopedRoles(): array
    {
        return [['pegawai'], ['kepala_bagian']];
    }

    public function test_audit_record_anak_dan_bukti_mengikuti_pemilik_termasuk_histori_soft_deleted(): void
    {
        $employee = Employee::factory()->create();
        $reader = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        Role::query()->where('name', 'pegawai')->sole()->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'audit_logs.read')->sole()->id,
        ]);
        $visible = [];
        $hidden = [];
        foreach ([$employee, Employee::factory()->create()] as $index => $target) {
            $family = EmployeeFamily::query()->create([
                'employee_id' => $target->id, 'nama_anggota' => 'Anak QA', 'hubungan' => 'Anak',
                'tanggal_lahir' => '2010-01-01', 'jenis_kelamin' => 'L',
            ]);
            $family->delete();
            $this->assertSoftDeleted($family);
            $leave = LeaveRequest::query()->create([
                'employee_id' => $target->id, 'jenis_cuti_id' => RefJenisCuti::query()->firstOrFail()->id,
                'tanggal_mulai' => '2026-10-05', 'tanggal_selesai' => '2026-10-05',
                'jumlah_hari_kerja' => 1, 'alasan' => 'QA scope bukti.', 'status' => 'disetujui',
            ]);
            $proof = LeaveProof::query()->create(['leave_request_id' => $leave->id, 'token' => Str::random(64)]);
            $logs = [$this->log('EmployeeFamily', $family->id, 'Keluarga '.$index),
                $this->log('LeaveProof', $proof->id, 'Bukti '.$index)];
            if ($index === 0) {
                $visible = $logs;
            } else {
                $hidden = $logs;
            }
        }
        $response = $this->actingAs($reader)->getJson('/api/v1/audit-log')->assertOk()->assertJsonPath('total', 2);
        foreach ($visible as $log) {
            $response->assertJsonFragment(['id' => $log->id]);
            $this->get('/dashboard/audit/'.$log->id)->assertOk();
        }
        foreach ($hidden as $log) {
            $response->assertJsonMissing(['id' => $log->id]);
            $this->get('/dashboard/audit/'.$log->id)->assertNotFound();
        }
    }

    public function test_caller_action_tetap_scoped_dan_assignment_baru_belum_efektif_tidak_membuka_audit(): void
    {
        $identity = Employee::factory()->create();
        $reader = User::factory()->kepalaBagian()->create(['employee_id' => $identity->id]);
        Role::query()->where('name', 'kepala_bagian')->sole()->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'audit_logs.read')->sole()->id,
        ]);
        $target = Employee::factory()->create(['kepala_bagian_id' => $identity->id]);
        SupervisorAssignment::query()->create([
            'employee_id' => $target->id, 'supervisor_id' => $identity->id,
            'tanggal_mulai' => '2026-09-09',
        ]);
        $this->log('Employee', $target->id, 'Penugasan belum efektif');
        foreach ([$reader, null] as $actor) {
            $this->assertSame(0, app(ListAuditLogsAction::class)->execute([], $actor)->total());
            $page = app(ListAuditLogPageAction::class)->execute([], $actor);
            $this->assertSame(0, $page['auditLogs']->total());
            $this->assertSame([], $page['operatorOptions']);
        }
    }

    /** Aktor sengaja berbeda dari target: user_id audit bukan identitas pemilik data. */
    private function log(string $type, ?string $targetId, string $operator): AuditLog
    {
        return AuditLog::query()->create([
            'user_id' => User::factory()->create()->id, 'user_name' => $operator,
            'event' => 'UPDATE', 'auditable_type' => $type, 'auditable_id' => $targetId,
            'new_values' => ['nama_lengkap' => 'Data '.$operator],
        ]);
    }
}
