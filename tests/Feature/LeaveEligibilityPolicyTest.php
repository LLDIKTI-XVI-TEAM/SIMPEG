<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestCase;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\LeaveApprovalService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mengunci kebijakan Tahap 5: linkage eksplisit untuk potongan Melahirkan/CLTN
 * serta kelayakan Cuti Besar menurut masa kerja kalender.
 */
class LeaveEligibilityPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /** @return array{user: User, employee: Employee, supervisor: Employee, pybmc: Employee} */
    private function makePemohon(string $jenisPegawai = 'PNS', string $tmt = '2020-01-01'): array
    {
        $jenis = RefJenisPegawai::firstOrCreate(['nama' => $jenisPegawai]);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $jenis->id]);
        $supervisor = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => $jenisPegawai,
            'tmt_pengangkatan' => $tmt,
            'no_sk' => 'SK-ELIGIBILITY-001',
            'tanggal_sk' => $tmt,
        ]);
        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'supervisor_id' => $supervisor->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);

        $chain = LeaveApprovalChain::create([
            'employee_id' => $employee->id,
            'name' => 'Rantai uji kelayakan cuti',
            'effective_from' => '2026-01-01',
            'change_reason' => 'Setup test Tahap 5.',
        ]);
        $chain->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $supervisor->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $pybmc->id,
                'is_final' => true,
            ],
        ]);

        return compact('user', 'employee', 'supervisor', 'pybmc');
    }

    private function leaveType(string $code, string $nama, bool $khususPns = false): RefJenisCuti
    {
        return RefJenisCuti::create([
            'nama' => $nama,
            'code' => $code,
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => $khususPns,
        ]);
    }

    /** @return array<string, string> */
    private function payload(RefJenisCuti $leaveType, string $startDate, string $endDate, array $override = []): array
    {
        return array_merge([
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => $startDate,
            'tanggal_selesai' => $endDate,
            'alasan' => 'Keperluan cuti yang didokumentasikan.',
            'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 1, Manado',
            'nomor_telepon' => '+62 (431) 123-456',
        ], $override);
    }

    public function test_cuti_besar_membutuhkan_lima_tahun_kalender_sejak_tmt_pengangkatan(): void
    {
        $aktor = $this->makePemohon(tmt: '2021-07-10');
        $cutiBesar = $this->leaveType('besar', 'Cuti Besar', khususPns: true);

        $this->actingAs($aktor['user'])
            ->postJson(route('cuti.store'), $this->payload($cutiBesar, '2026-07-09', '2026-07-09'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tanggal_mulai']);

        $this->assertDatabaseCount('leave_requests', 0);

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($cutiBesar, '2026-07-10', '2026-07-10'))
            ->assertRedirect(route('cuti'));

        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $aktor['employee']->id,
            'jenis_cuti_id' => $cutiBesar->id,
        ]);
    }

    public function test_maternity_leave_creates_an_explicit_case_and_enforces_three_calendar_months(): void
    {
        $aktor = $this->makePemohon();
        $melahirkan = $this->leaveType('melahirkan', 'Cuti Melahirkan');

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($melahirkan, '2026-01-01', '2026-03-31'))
            ->assertRedirect(route('cuti'));

        $firstRequest = LeaveRequest::query()->firstOrFail();
        $this->assertNotNull($firstRequest->leave_request_case_id);
        $this->assertDatabaseHas('leave_request_cases', [
            'id' => $firstRequest->leave_request_case_id,
            'employee_id' => $aktor['employee']->id,
            'jenis_cuti_id' => $melahirkan->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CREATE',
            'auditable_type' => 'LeaveRequestCase',
            'auditable_id' => $firstRequest->leave_request_case_id,
        ]);

        $this->actingAs($aktor['user'])
            ->postJson(route('cuti.store'), $this->payload($melahirkan, '2026-04-01', '2026-04-01', [
                'leave_request_case_id' => $firstRequest->leave_request_case_id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tanggal_selesai']);

        $this->assertSame(1, LeaveRequest::query()->count());
        $this->assertSame(1, LeaveRequestCase::query()->count());
    }

    public function test_maternity_continuation_across_years_is_aggregated_by_the_selected_case(): void
    {
        $aktor = $this->makePemohon();
        $melahirkan = $this->leaveType('melahirkan', 'Cuti Melahirkan');

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($melahirkan, '2026-12-01', '2026-12-31'))
            ->assertRedirect(route('cuti'));

        $caseId = LeaveRequest::query()->sole()->leave_request_case_id;

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($melahirkan, '2027-01-01', '2027-02-28', [
                'leave_request_case_id' => $caseId,
            ]))
            ->assertRedirect(route('cuti'));

        $this->assertSame(2, LeaveRequest::query()->where('leave_request_case_id', $caseId)->count());

        $this->actingAs($aktor['user'])
            ->postJson(route('cuti.store'), $this->payload($melahirkan, '2027-03-01', '2027-03-01', [
                'leave_request_case_id' => $caseId,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tanggal_selesai']);
    }

    public function test_cltn_uses_a_three_calendar_year_limit_for_one_explicit_case(): void
    {
        $aktor = $this->makePemohon();
        $cltn = $this->leaveType('cltn', 'Cuti Luar Tanggungan Negara (CLTN)', khususPns: true);

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($cltn, '2026-01-01', '2026-01-31'))
            ->assertRedirect(route('cuti'));

        $caseId = LeaveRequest::query()->sole()->leave_request_case_id;

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($cltn, '2028-12-31', '2028-12-31', [
                'leave_request_case_id' => $caseId,
            ]))
            ->assertRedirect(route('cuti'));

        $this->actingAs($aktor['user'])
            ->postJson(route('cuti.store'), $this->payload($cltn, '2029-01-01', '2029-01-01', [
                'leave_request_case_id' => $caseId,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tanggal_selesai']);
    }

    public function test_case_must_belong_to_the_applicant_and_match_the_leave_type(): void
    {
        $owner = $this->makePemohon();
        $other = $this->makePemohon();
        $melahirkan = $this->leaveType('melahirkan', 'Cuti Melahirkan');
        $cltn = $this->leaveType('cltn', 'Cuti Luar Tanggungan Negara (CLTN)', khususPns: true);

        $this->actingAs($owner['user'])
            ->post(route('cuti.store'), $this->payload($melahirkan, '2026-01-01', '2026-01-31'))
            ->assertRedirect(route('cuti'));

        $caseId = LeaveRequest::query()->sole()->leave_request_case_id;

        $this->actingAs($other['user'])
            ->postJson(route('cuti.store'), $this->payload($melahirkan, '2026-02-01', '2026-02-01', [
                'leave_request_case_id' => $caseId,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['leave_request_case_id']);

        $this->actingAs($owner['user'])
            ->postJson(route('cuti.store'), $this->payload($cltn, '2026-02-01', '2026-02-01', [
                'leave_request_case_id' => $caseId,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['leave_request_case_id']);
    }

    public function test_form_only_exposes_the_applicants_own_explicit_continuation_cases(): void
    {
        $owner = $this->makePemohon();
        $other = $this->makePemohon();
        $melahirkan = $this->leaveType('melahirkan', 'Cuti Melahirkan');

        $this->actingAs($owner['user'])
            ->post(route('cuti.store'), $this->payload($melahirkan, '2026-01-01', '2026-01-31'))
            ->assertRedirect(route('cuti'));
        $ownerCaseId = LeaveRequest::query()->sole()->leave_request_case_id;

        $this->actingAs($other['user'])
            ->post(route('cuti.store'), $this->payload($melahirkan, '2026-02-01', '2026-02-28'))
            ->assertRedirect(route('cuti'));
        $otherCaseId = LeaveRequest::query()
            ->where('employee_id', $other['employee']->id)
            ->sole()
            ->leave_request_case_id;

        $this->actingAs($owner['user'])
            ->get(route('cuti.create'))
            ->assertOk()
            ->assertSee('name="leave_request_case_id"', false)
            ->assertSee($ownerCaseId)
            ->assertDontSee($otherCaseId);
    }

    public function test_resubmit_rechecks_the_existing_case_limit_without_rewriting_the_link(): void
    {
        $aktor = $this->makePemohon();
        $melahirkan = $this->leaveType('melahirkan', 'Cuti Melahirkan');

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($melahirkan, '2026-01-01', '2026-01-15'))
            ->assertRedirect(route('cuti'));

        $leaveRequest = LeaveRequest::query()->sole();
        $caseId = $leaveRequest->leave_request_case_id;
        app(LeaveApprovalService::class)->requestChanges($leaveRequest, $aktor['supervisor'], 'Tanggal perlu diperbaiki.');

        $this->actingAs($aktor['user'])
            ->patchJson(route('cuti.resubmit', $leaveRequest), [
                'tanggal_mulai' => '2026-01-01',
                'tanggal_selesai' => '2026-04-01',
                'alasan' => 'Periode diperbaiki.',
                'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 2, Manado',
                'nomor_telepon' => '+62 (431) 123-457',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tanggal_selesai']);

        $leaveRequest->refresh();
        $this->assertSame('2026-01-15', $leaveRequest->tanggal_selesai->toDateString());
        $this->assertSame($caseId, $leaveRequest->leave_request_case_id);
        $this->assertSame('perlu_perubahan', $leaveRequest->status);
    }

    public function test_pppk_and_non_case_types_cannot_bypass_server_side_rules(): void
    {
        $pppk = $this->makePemohon('PPPK');
        $pns = $this->makePemohon();
        $cltn = $this->leaveType('cltn', 'Cuti Luar Tanggungan Negara (CLTN)', khususPns: true);
        $melahirkan = $this->leaveType('melahirkan', 'Cuti Melahirkan');
        $sakit = $this->leaveType('sakit', 'Cuti Sakit');

        $this->actingAs($pppk['user'])
            ->postJson(route('cuti.store'), $this->payload($cltn, '2026-01-01', '2026-01-31'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['jenis_cuti_id']);

        $this->actingAs($pns['user'])
            ->post(route('cuti.store'), $this->payload($melahirkan, '2026-02-01', '2026-02-02'))
            ->assertRedirect(route('cuti'));

        $caseId = LeaveRequest::query()->sole()->leave_request_case_id;

        $this->actingAs($pns['user'])
            ->postJson(route('cuti.store'), $this->payload($sakit, '2026-02-03', '2026-02-03', [
                'leave_request_case_id' => $caseId,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['leave_request_case_id']);

        $this->assertSame(1, AuditLog::query()->where('auditable_type', 'LeaveRequestCase')->count());
    }
}
