<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdoptedLeaveAttachment;
use Tests\TestCase;

class EffectiveRoleCutiAuthorizationTest extends TestCase
{
    use CreatesAdoptedLeaveAttachment;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-25 10:00:00');
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $this->setUpAdoptedLeaveAttachmentFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_simulasi_admin_menampilkan_kapabilitas_halaman_saldo_dan_rekap(): void
    {
        $actor = $this->simulatedSuperAdmin('admin_kepegawaian');
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Saldo Simulasi']);
        LeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);

        $this->actingAs($actor)
            ->get(route('cuti.saldo.administrasi', [
                'pegawai' => $employee->id,
                'status' => 'semua_pegawai',
                'tab' => 'manual',
            ]))
            ->assertOk()
            ->assertSee(route('cuti.manual.store', $employee), false);

        $this->actingAs($actor->refresh())
            ->get(route('cuti.rekap', ['periode' => '2026']))
            ->assertOk()
            ->assertViewHas('canAdministerBalance', true);

        $this->assertOriginalRoleUnchanged($actor, 'admin_kepegawaian');
    }

    public function test_simulasi_admin_dapat_mencatat_fakta_manual_dengan_audit_aktor_asli(): void
    {
        $actor = $this->simulatedSuperAdmin('admin_kepegawaian');
        $employee = Employee::factory()->create();
        $leaveType = $this->nonAnnualLeaveType();

        $this->actingAs($actor)
            ->get(route('cuti.saldo.administrasi'))
            ->assertOk();

        $this->actingAs($actor->refresh())
            ->post(route('cuti.manual.store', $employee), $this->validManualPayload($leaveType))
            ->assertRedirect();

        $record = LeaveUsageRecord::query()
            ->where('employee_id', $employee->id)
            ->where('source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)
            ->sole();
        $this->assertSame($actor->id, $record->recorded_by);
        $audit = AuditLog::query()
            ->where('user_id', $actor->id)
            ->where('event', 'CREATE')
            ->where('auditable_type', 'LeaveUsageRecord')
            ->where('auditable_id', $record->id)
            ->sole();
        $this->assertTrue($audit->new_values['_simulation'] ?? false);
        $this->assertSame('super_admin', $audit->new_values['_original_role'] ?? null);
        $this->assertSame('admin_kepegawaian', $audit->new_values['_effective_role'] ?? null);
        $this->assertOriginalRoleUnchanged($actor, 'admin_kepegawaian');
    }

    public function test_simulasi_admin_dapat_mencari_penyetuju_manual(): void
    {
        $actor = $this->simulatedSuperAdmin('admin_kepegawaian');
        $target = Employee::factory()->create([
            'nama_lengkap' => 'Penyetuju Simulasi Efektif',
            'nip' => '198765432100000099',
            'jabatan_terakhir' => 'Pejabat Penguji',
            'status_aktif' => 'Aktif',
        ]);

        $this->actingAs($actor)
            ->getJson(route('cuti.manual.approver-lookup', ['q' => 'Simulasi Efektif']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $target->id);

        $this->assertOriginalRoleUnchanged($actor, 'admin_kepegawaian');
    }

    public function test_simulasi_pimpinan_dapat_mengunduh_lampiran_privat(): void
    {
        $actor = $this->simulatedSuperAdmin('pimpinan');
        $leave = $this->leaveWithPrivateAttachment();

        $this->actingAs($actor)
            ->get(route('pimpinan.cuti.attachment.download', $leave))
            ->assertOk()
            ->assertDownload('Lampiran_Cuti_'.strtoupper(substr($leave->id, 0, 8)).'.pdf');

        $this->assertOriginalRoleUnchanged($actor, 'pimpinan');
    }

    public function test_simulasi_kepala_bagian_dapat_mencari_hanya_bawahan_langsung(): void
    {
        $kepalaBagian = Employee::factory()->create();
        $actor = $this->simulatedSuperAdmin('kepala_bagian', $kepalaBagian);
        $directReport = Employee::factory()->create([
            'nama_lengkap' => 'Bawahan Simulasi Efektif',
            'nip' => '199001012020121099',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Simulasi Luar Scope',
            'nip' => '199001012020121098',
        ]);

        $this->actingAs($actor)
            ->getJson(route('kepala-bagian.search', ['q' => 'Simulasi']))
            ->assertOk()
            ->assertJsonPath('Pegawai.0.title', $directReport->nama_lengkap)
            ->assertJsonMissing(['title' => 'Pegawai Simulasi Luar Scope']);

        $this->assertOriginalRoleUnchanged($actor, 'kepala_bagian');
    }

    public function test_super_admin_tanpa_simulasi_tetap_ditolak_dari_kapabilitas_role_tujuan(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();
        $leave = $this->leaveWithPrivateAttachment();

        $this->assertSame('super_admin', $actor->getEffectiveRole());
        $this->actingAs($actor)->get(route('cuti.saldo.administrasi'))->assertForbidden();
        $this->actingAs($actor)->post(
            route('cuti.manual.store', $employee),
            $this->validManualPayload($this->nonAnnualLeaveType()),
        )->assertForbidden();
        $this->actingAs($actor)
            ->getJson(route('cuti.manual.approver-lookup', ['q' => 'Pegawai']))
            ->assertForbidden();
        $this->actingAs($actor)
            ->get(route('pimpinan.cuti.attachment.download', $leave))
            ->assertForbidden();
        $this->actingAs($actor)
            ->getJson(route('kepala-bagian.search', ['q' => 'Pegawai']))
            ->assertForbidden();
        $this->assertDatabaseMissing('leave_usage_records', [
            'employee_id' => $employee->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
        ]);
    }

    private function simulatedSuperAdmin(string $effectiveRole, ?Employee $employee = null): User
    {
        $actor = User::factory()->superAdmin()->create([
            'employee_id' => $employee?->id,
        ]);
        $actor->forceFill([
            'temporary_role' => $effectiveRole,
            'temporary_role_started_at' => now(),
            'temporary_role_switched_by' => $actor->id,
        ])->save();

        return $actor->refresh();
    }

    private function assertOriginalRoleUnchanged(User $actor, string $effectiveRole): void
    {
        $fresh = $actor->fresh();

        $this->assertSame('super_admin', $fresh->role);
        $this->assertSame($effectiveRole, $fresh->temporary_role);
        $this->assertSame($effectiveRole, $fresh->getEffectiveRole());
    }

    /** @return array<string, mixed> */
    private function validManualPayload(RefJenisCuti $leaveType): array
    {
        return [
            'leave_type_id' => $leaveType->id,
            'leave_request_case_id' => null,
            'tanggal_mulai' => '2026-01-05',
            'tanggal_selesai' => '2026-01-07',
            'alasan' => 'Cuti yang disetujui di luar SIMPEG.',
            'approval_document_number' => 'SIMULASI/2026/001',
            'approval_steps' => $this->validManualApprovalPayload(),
        ];
    }

    private function nonAnnualLeaveType(): RefJenisCuti
    {
        return RefJenisCuti::query()->firstOrCreate(
            ['code' => 'sakit'],
            [
                'nama' => 'Cuti Sakit',
                'mengurangi_saldo_tahunan' => false,
                'khusus_pns' => false,
            ],
        );
    }

    private function leaveWithPrivateAttachment(): LeaveRequest
    {
        $employee = Employee::factory()->create();
        $leave = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $this->nonAnnualLeaveType()->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-06',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
        ]);
        $this->createAdoptedLeaveAttachment($leave, "%PDF-1.4\n% lampiran privat simulasi\n%%EOF\n");

        return $leave;
    }
}
