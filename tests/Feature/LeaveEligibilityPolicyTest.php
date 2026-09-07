<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestCase;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\Cuti\LeaveEligibilityService;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            'mengurangi_saldo_tahunan' => $code === RefJenisCuti::CODE_TAHUNAN,
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

    public function test_assert_single_calendar_year_menerima_carbon_immutable_untuk_reuse_lintas_action(): void
    {
        app(LeaveEligibilityService::class)->assertSingleCalendarYear(
            CarbonImmutable::parse('2026-08-03'),
            CarbonImmutable::parse('2026-11-02'),
        );

        $this->assertTrue(true);
    }

    public function test_submit_cuti_besar_menolak_durasi_diatas_tiga_bulan_kalender(): void
    {
        $aktor = $this->makePemohon(tmt: '2018-01-01');
        $cutiBesar = $this->leaveType('besar', 'Cuti Besar', khususPns: true);

        $this->actingAs($aktor['user'])
            ->postJson(route('cuti.store'), $this->payload($cutiBesar, '2026-08-03', '2026-11-03'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tanggal_selesai'])
            ->assertJsonPath('errors.tanggal_selesai.0', 'Cuti Besar paling lama 3 bulan kalender. Batas akhir pengajuan ini adalah 02-11-2026.');

        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_resubmit_cuti_besar_menolak_durasi_diatas_tiga_bulan_kalender(): void
    {
        $aktor = $this->makePemohon(tmt: '2018-01-01');
        $cutiBesar = $this->leaveType('besar', 'Cuti Besar', khususPns: true);

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($cutiBesar, '2026-08-03', '2026-08-07'))
            ->assertRedirect(route('cuti'));

        $leaveRequest = LeaveRequest::query()->sole();

        $this->actingAs($aktor['user'])
            ->patchJson(route('cuti.resubmit', $leaveRequest), [
                'revision_version' => $leaveRequest->fresh()->revision_version,
                'tanggal_mulai' => '2026-08-03',
                'tanggal_selesai' => '2026-11-03',
                'alasan' => 'Periode diperbaiki.',
                'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 2, Manado',
                'nomor_telepon' => '+62 (431) 123-457',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tanggal_selesai'])
            ->assertJsonPath('errors.tanggal_selesai.0', 'Cuti Besar paling lama 3 bulan kalender. Batas akhir pengajuan ini adalah 02-11-2026.');

        $leaveRequest->refresh();
        $this->assertSame('2026-08-07', $leaveRequest->tanggal_selesai->toDateString());
        $this->assertSame('menunggu_approval', $leaveRequest->status);
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
        LeaveRequest::query()->where('status', 'menunggu_approval')->update(['status' => 'disetujui']);

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($melahirkan, '2027-01-01', '2027-02-28', [
                'leave_request_case_id' => $caseId,
            ]))
            ->assertRedirect(route('cuti'));

        $this->assertSame(2, LeaveRequest::query()->where('leave_request_case_id', $caseId)->count());
        LeaveRequest::query()->where('status', 'menunggu_approval')->update(['status' => 'disetujui']);

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
        LeaveRequest::query()->where('status', 'menunggu_approval')->update(['status' => 'disetujui']);

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($cltn, '2028-12-29', '2028-12-31', [
                'leave_request_case_id' => $caseId,
            ]))
            ->assertRedirect(route('cuti'));
        LeaveRequest::query()->where('status', 'menunggu_approval')->update(['status' => 'disetujui']);

        $this->actingAs($aktor['user'])
            ->postJson(route('cuti.store'), $this->payload($cltn, '2029-01-01', '2029-01-02', [
                'leave_request_case_id' => $caseId,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tanggal_selesai'])
            ->assertJsonPath(
                'errors.tanggal_selesai.0',
                'CLTN dalam satu rangkaian paling lama 3 tahun kalender. Batas akhir rangkaian ini adalah 31-12-2028.',
            );
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

    public function test_form_membatasi_opsi_rangkaian_dan_tidak_memuat_seluruh_relasi_histori(): void
    {
        $aktor = $this->makePemohon();
        $melahirkan = $this->leaveType('melahirkan', 'Cuti Melahirkan');
        $now = now();
        $cases = [];
        $requests = [];
        $selectedCaseId = '';

        foreach (range(1, 125) as $index) {
            $caseId = (string) Str::uuid();
            $requestId = (string) Str::uuid();
            $createdAt = $now->copy()->subMinutes(126 - $index);

            if ($index === 1) {
                $selectedCaseId = $caseId;
            }

            $cases[] = [
                'id' => $caseId,
                'employee_id' => $aktor['employee']->id,
                'jenis_cuti_id' => $melahirkan->id,
                'created_by' => $aktor['user']->id,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
            $requests[] = [
                'id' => $requestId,
                'employee_id' => $aktor['employee']->id,
                'jenis_cuti_id' => $melahirkan->id,
                'leave_request_case_id' => $caseId,
                'tanggal_mulai' => '2026-01-01',
                'tanggal_selesai' => '2026-01-02',
                'jumlah_hari_kerja' => 2,
                'alasan' => "Histori rangkaian {$index}",
                'status' => 'menunggu_approval',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        DB::table('leave_request_cases')->insert($cases);
        DB::table('leave_requests')->insert($requests);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this->actingAs($aktor['user'])
            ->withSession(['_old_input' => [
                'jenis_cuti_id' => $melahirkan->id,
                'leave_request_case_id' => $selectedCaseId,
            ]])
            ->get(route('cuti.create'))
            ->assertOk()
            ->assertViewHas('continuationLeaveCases', function (array $options) use ($selectedCaseId): bool {
                return count($options) === 100
                    && collect($options)->contains(fn (array $option): bool => $option['id'] === $selectedCaseId)
                    && collect($options)->every(fn (array $option): bool => array_keys($option) === ['id', 'jenis_cuti_code', 'label']);
            });

        $caseQueries = collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'from "leave_request_cases"'));

        $this->assertTrue(
            $caseQueries->contains(fn (string $sql): bool => str_contains($sql, 'limit')),
            'Opsi rangkaian harus dibatasi langsung oleh database.',
        );
        $this->assertFalse(
            collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'from "leave_requests"')
                && str_contains($sql, '"leave_request_case_id" in (')),
            'Form tidak boleh eager-load seluruh histori leave_requests untuk setiap rangkaian.',
        );
        $this->assertFalse(
            collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'from "leave_usage_records"')
                && str_contains($sql, '"leave_request_case_id" in (')),
            'Form tidak boleh eager-load seluruh histori manual untuk setiap rangkaian.',
        );
        $this->assertLessThan(120 * 1024, strlen($response->getContent()));
    }

    public function test_continuation_cases_menempatkan_pilihan_lama_lebih_dulu_dengan_urutan_id_stabil_pada_timestamp_sama(): void
    {
        $aktor = $this->makePemohon();
        $other = $this->makePemohon();
        $melahirkan = $this->leaveType('melahirkan', 'Cuti Melahirkan');
        $timestamp = '2026-08-19 09:00:00';
        $caseRows = [];
        $usageRows = [];
        $caseIds = [];

        foreach (range(1, 101) as $index) {
            $caseId = sprintf('00000000-0000-4000-8000-%012d', $index);
            $date = CarbonImmutable::parse('2026-01-01')->addDays($index - 1)->toDateString();
            $caseIds[] = $caseId;
            $caseRows[] = [
                'id' => $caseId,
                'employee_id' => $aktor['employee']->id,
                'jenis_cuti_id' => $melahirkan->id,
                'created_by' => $aktor['user']->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            $usageRows[] = [
                'id' => sprintf('10000000-0000-4000-8000-%012d', $index),
                'employee_id' => $aktor['employee']->id,
                'leave_type_id' => $melahirkan->id,
                'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
                'reconciliation_set_id' => null,
                'leave_request_id' => null,
                'leave_request_case_id' => $caseId,
                'usage_year' => 2026,
                'effective_date' => $date,
                'start_date' => $date,
                'end_date' => $date,
                'workdays' => 1,
                'administrative_note' => "Fakta aktif rangkaian stabil {$index}.",
                'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
                'replaces_id' => null,
                'correction_reason' => null,
                'recorded_by' => $aktor['user']->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        $otherCaseId = '00000000-0000-4000-8000-000000000999';
        $caseRows[] = [
            'id' => $otherCaseId,
            'employee_id' => $other['employee']->id,
            'jenis_cuti_id' => $melahirkan->id,
            'created_by' => $other['user']->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
        $usageRows[] = [
            'id' => '10000000-0000-4000-8000-000000000999',
            'employee_id' => $other['employee']->id,
            'leave_type_id' => $melahirkan->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'reconciliation_set_id' => null,
            'leave_request_id' => null,
            'leave_request_case_id' => $otherCaseId,
            'usage_year' => 2026,
            'effective_date' => '2026-12-31',
            'start_date' => '2026-12-31',
            'end_date' => '2026-12-31',
            'workdays' => 1,
            'administrative_note' => 'Fakta milik pegawai lain tidak boleh muncul.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'replaces_id' => null,
            'correction_reason' => null,
            'recorded_by' => $other['user']->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
        DB::table('leave_request_cases')->insert($caseRows);
        DB::table('leave_usage_records')->insert($usageRows);
        foreach ($usageRows as $usageRow) {
            $this->attachValidManualApprovalSnapshot(
                LeaveUsageRecord::query()->findOrFail($usageRow['id']),
            );
        }

        $selectedCaseId = $caseIds[0];
        $naturalCapWithoutSelected = array_slice(array_reverse($caseIds), 0, LeaveEligibilityService::FORM_OPTION_LIMIT);
        $expectedCaseIds = [
            $selectedCaseId,
            ...array_slice(array_reverse(array_slice($caseIds, 1)), 0, LeaveEligibilityService::FORM_OPTION_LIMIT - 1),
        ];
        $service = app(LeaveEligibilityService::class);
        $firstResult = $service->continuationCasesFor($aktor['employee'], $selectedCaseId);
        $secondResult = $service->continuationCasesFor($aktor['employee'], $selectedCaseId);
        $firstCaseIds = $firstResult->pluck('id')->all();

        $this->assertNotContains($selectedCaseId, $naturalCapWithoutSelected);
        $this->assertCount(LeaveEligibilityService::FORM_OPTION_LIMIT, $firstResult);
        $this->assertSame($selectedCaseId, $firstCaseIds[0]);
        $this->assertSame($expectedCaseIds, $firstCaseIds);
        $this->assertSame($firstCaseIds, $secondResult->pluck('id')->all());
        $this->assertTrue($firstResult->every(fn (LeaveRequestCase $case): bool => $case->employee_id === $aktor['employee']->id));
        $this->assertNotContains($otherCaseId, $firstCaseIds);
    }

    public function test_form_membatasi_jenis_cuti_tanpa_menghilangkan_jenis_resmi_dan_pilihan_lama(): void
    {
        $aktor = $this->makePemohon();
        $jenisResmi = $this->leaveType('tahunan', 'ZZZ Cuti Tahunan');
        $jenisTerpilih = $this->leaveType('custom_selected', 'ZZZ Jenis Terpilih');

        foreach (range(1, 105) as $index) {
            $this->leaveType("custom_{$index}", sprintf('AAA Jenis %03d', $index));
        }

        $this->actingAs($aktor['user'])
            ->withSession(['_old_input' => ['jenis_cuti_id' => $jenisTerpilih->id]])
            ->get(route('cuti.create'))
            ->assertOk()
            ->assertViewHas('jenisCuti', function ($types) use ($jenisResmi, $jenisTerpilih): bool {
                return $types->count() === 100
                    && $types->contains('id', $jenisResmi->id)
                    && $types->contains('id', $jenisTerpilih->id);
            });
    }

    public function test_form_memakai_map_jenis_terbatas_sebagai_sumber_reaktif_rangkaian_dan_input_lama(): void
    {
        $aktor = $this->makePemohon();
        $tahunan = $this->leaveType('tahunan', 'Cuti Tahunan Reaktif');
        $melahirkan = $this->leaveType('melahirkan', 'Cuti Melahirkan Reaktif');
        $cltn = $this->leaveType('cltn', 'CLTN Reaktif');

        $response = $this->actingAs($aktor['user'])
            ->withSession(['_old_input' => ['jenis_cuti_id' => $melahirkan->id]])
            ->get(route('cuti.create'))
            ->assertOk()
            ->assertViewHas('leaveTypeCodes', function (array $codes) use ($tahunan, $melahirkan, $cltn): bool {
                return count($codes) <= LeaveEligibilityService::FORM_OPTION_LIMIT
                    && ($codes[$tahunan->id] ?? null) === 'tahunan'
                    && ($codes[$melahirkan->id] ?? null) === 'melahirkan'
                    && ($codes[$cltn->id] ?? null) === 'cltn';
            });

        $this->assertStringContainsString($melahirkan->id, $response->getContent());

        $source = file_get_contents(resource_path('views/admin/cuti/form-pengajuan.blade.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString('@js($leaveTypeCodes)', $source);
        $this->assertStringContainsString('leaveTypeCodes,', $source);
        $this->assertStringContainsString("return this.leaveTypeCodes[this.selectedJenisCuti] ?? '';", $source);
        $this->assertStringNotContainsString(
            "const select = document.getElementById('jenis_cuti_id');\n\n                    return select?.options[select.selectedIndex]?.getAttribute('data-code') ?? '';",
            $source,
        );
    }

    public function test_file_produksi_ui_cuti_yang_disentuh_tidak_memuat_token_planning(): void
    {
        foreach ([
            'app/Actions/Cuti/ListKepalaBagianLeavesAction.php',
            'app/Actions/Cuti/PrepareLeaveRequestFormAction.php',
            'resources/views/admin/cuti/form-pengajuan.blade.php',
            'resources/views/admin/cuti/show.blade.php',
            'resources/views/components/ui/modal.blade.php',
            'resources/views/kabag/cuti/show.blade.php',
            'resources/views/pimpinan/cuti/show.blade.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));
            $this->assertIsString($source, $path);
            $this->assertStringNotContainsString('K-CUT-02', $source, $path);
        }
    }

    public function test_form_label_rangkaian_manual_only_memakai_periode_minimum_dan_maksimum_fakta_aktif(): void
    {
        $aktor = $this->makePemohon();
        $melahirkan = $this->leaveType('melahirkan', 'Cuti Melahirkan');
        $case = LeaveRequestCase::query()->create([
            'employee_id' => $aktor['employee']->id,
            'jenis_cuti_id' => $melahirkan->id,
            'created_by' => $aktor['user']->id,
        ]);

        foreach ([
            ['year' => 2025, 'start' => '2025-12-01', 'end' => '2025-12-31'],
            ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-01-15'],
        ] as $period) {
            $record = LeaveUsageRecord::query()->create([
                'employee_id' => $aktor['employee']->id,
                'leave_type_id' => $melahirkan->id,
                'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
                'leave_request_case_id' => $case->id,
                'usage_year' => $period['year'],
                'effective_date' => $period['start'],
                'start_date' => $period['start'],
                'end_date' => $period['end'],
                'workdays' => 1,
                'administrative_note' => 'Fakta manual untuk label rangkaian.',
                'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
                'recorded_by' => $aktor['user']->id,
            ]);
            $this->attachValidManualApprovalSnapshot($record);
        }

        $this->actingAs($aktor['user'])
            ->get(route('cuti.create'))
            ->assertOk()
            ->assertViewHas('continuationLeaveCases', function (array $options) use ($case): bool {
                $option = collect($options)->firstWhere('id', $case->id);

                return $option !== null
                    && $option['label'] === 'Cuti Melahirkan — periode tercatat 01-12-2025 s.d. 15-01-2026';
            });
    }

    public function test_form_memulihkan_tanggal_alasan_dan_rangkaian_setelah_validasi_gagal(): void
    {
        $aktor = $this->makePemohon();
        $melahirkan = $this->leaveType('melahirkan', 'Cuti Melahirkan');
        $case = LeaveRequestCase::query()->create([
            'employee_id' => $aktor['employee']->id,
            'jenis_cuti_id' => $melahirkan->id,
            'created_by' => $aktor['user']->id,
        ]);
        LeaveRequest::query()->create([
            'employee_id' => $aktor['employee']->id,
            'jenis_cuti_id' => $melahirkan->id,
            'leave_request_case_id' => $case->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-01-02',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Fakta awal rangkaian.',
            'status' => 'menunggu_approval',
        ]);

        $this->actingAs($aktor['user'])
            ->from(route('cuti.create'))
            ->post(route('cuti.store'), $this->payload($melahirkan, '2026-03-05', '2026-03-04', [
                'leave_request_case_id' => $case->id,
                'alasan' => 'Alasan lama setelah validasi.',
            ]))
            ->assertRedirect(route('cuti.create'))
            ->assertSessionHasErrors(['tanggal_selesai']);

        $content = $this->actingAs($aktor['user'])
            ->get(route('cuti.create'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/id="tanggal_mulai"[^>]*value="2026-03-05"/', $content);
        $this->assertMatchesRegularExpression('/id="tanggal_selesai"[^>]*value="2026-03-04"/', $content);
        $this->assertStringContainsString('>Alasan lama setelah validasi.</textarea>', $content);
        $this->assertTrue(
            str_contains($content, "selectedLeaveRequestCase: '{$case->id}'")
                || str_contains($content, 'selectedLeaveRequestCase: "'.$case->id.'"'),
        );
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

        $this->actingAs($aktor['user'])
            ->patchJson(route('cuti.resubmit', $leaveRequest), [
                'revision_version' => $leaveRequest->fresh()->revision_version,
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
        $this->assertSame('menunggu_approval', $leaveRequest->status);
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
