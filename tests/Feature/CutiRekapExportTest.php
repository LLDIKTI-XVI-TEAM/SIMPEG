<?php

namespace Tests\Feature;

use App\Actions\Cuti\ShowCutiRekapAction;
use App\Http\Requests\Cuti\ListCutiRekapRequest;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\LeaveUsageReconciliationMembership;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\LeaveUsageRecord;
use App\Models\Permission;
use App\Models\PositionHistory;
use App\Models\RefJenisCuti;
use App\Models\RefUnitKerja;
use App\Models\Role;
use App\Models\User;
use App\Queries\Cuti\CutiRekapQuery;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class CutiRekapExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_request_menerima_filter_kanonis_yang_valid(): void
    {
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Rekap Valid']);
        $unit = $this->createUnit('Bagian Kepegawaian');
        $pegawai = Employee::factory()->create();
        $request = new ListCutiRekapRequest;

        $validator = Validator::make([
            'periode' => 'Juni 2026',
            'unit' => $unit->id,
            'pegawai' => $pegawai->id,
            'jenis' => $jenis->id,
        ], $request->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_request_menolak_filter_di_luar_kontrak(): void
    {
        $request = new ListCutiRekapRequest;
        $validator = Validator::make([
            'periode' => str_repeat('2', 21),
            'unit' => '00000000-0000-4000-8000-000000000098',
            'pegawai' => 'bukan-uuid',
            'jenis' => '00000000-0000-4000-8000-000000000099',
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertSame(['periode', 'unit', 'pegawai', 'jenis'], array_keys($validator->errors()->toArray()));
    }

    #[DataProvider('invalidUuidInputProvider')]
    public function test_request_mengembalikan_404_sebelum_validasi_untuk_uuid_tidak_aman(
        string $field,
        mixed $value,
    ): void {
        $request = ListCutiRekapRequest::create('/cuti/rekap', 'GET', [$field => $value]);
        $method = new \ReflectionMethod($request, 'prepareForValidation');

        $this->expectException(NotFoundHttpException::class);

        $method->invoke($request);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidUuidInputProvider(): array
    {
        return [
            'unit malformed' => ['unit', 'bukan-uuid'],
            'unit array' => ['unit', ['uuid']],
            'pegawai malformed' => ['pegawai', 'bukan-uuid'],
            'pegawai array' => ['pegawai', ['uuid']],
            'jenis malformed' => ['jenis', 'bukan-uuid'],
            'jenis array' => ['jenis', ['uuid']],
        ];
    }

    #[DataProvider('reportRoleProvider')]
    public function test_seluruh_permukaan_laporan_menerapkan_role_gate_yang_sama(string $role, int $status): void
    {
        $user = User::factory()->create(['role' => $role]);

        foreach (['cuti.rekap', 'cuti.laporan', 'cuti.laporan.pdf', 'cuti.laporan.excel'] as $routeName) {
            $this->actingAs($user)->get(route($routeName))->assertStatus($status);
        }
    }

    /** @return array<string, array{string, int}> */
    public static function reportRoleProvider(): array
    {
        return [
            'super admin' => ['super_admin', 200],
            'admin kepegawaian' => ['admin_kepegawaian', 200],
            'pimpinan' => ['pimpinan', 200],
            'pegawai' => ['pegawai', 403],
            'kepala bagian' => ['kepala_bagian', 403],
        ];
    }

    public function test_pimpinan_hanya_dapat_membaca_rekap_tanpa_akses_konfigurasi_cuti(): void
    {
        $pimpinan = User::factory()->create(['role' => 'pimpinan']);

        $this->actingAs($pimpinan)->get(route('cuti.rekap'))
            ->assertOk()
            ->assertSee('id="rekap-pegawai"', false)
            ->assertSee('role="combobox"', false);
        $this->actingAs($pimpinan)->get(route('cuti.config'))->assertForbidden();
    }

    #[DataProvider('deniedRekapRoleProvider')]
    public function test_role_operasional_tanpa_hak_rekap_tetap_ditolak(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('cuti.rekap'))->assertForbidden();
    }

    /** @return array<string, array{string}> */
    public static function deniedRekapRoleProvider(): array
    {
        return [
            'pegawai' => ['pegawai'],
            'kepala bagian' => ['kepala_bagian'],
        ];
    }

    #[DataProvider('unsafeReportFilterProvider')]
    public function test_seluruh_permukaan_rekap_menolak_uuid_tidak_aman(string $path, string $field, mixed $value): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)->get($path.'?'.http_build_query([$field => $value]))->assertNotFound();
    }

    /** @return array<string, array{string, string, mixed}> */
    public static function unsafeReportFilterProvider(): array
    {
        $cases = [];
        foreach (['/cuti/rekap', '/cuti/laporan', '/cuti/laporan/pdf', '/cuti/laporan/excel'] as $path) {
            foreach (['unit', 'pegawai', 'jenis'] as $field) {
                $cases[$path.' '.$field.' malformed'] = [$path, $field, 'bukan-uuid'];
                $cases[$path.' '.$field.' array'] = [$path, $field, ['uuid']];
            }
        }

        return $cases;
    }

    public function test_action_rekap_hanya_mengembalikan_data_yang_dirender_halaman(): void
    {
        $pegawai = Employee::factory()->create();
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Kontrak Rekap']);
        foreach (range(1, 11) as $day) {
            $this->createLeaveRequest($pegawai, $jenis, '2026-06-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT));
            LeaveBalance::create([
                'employee_id' => Employee::factory()->create()->id,
                'tahun' => 2010 + $day,
            ]);
        }
        LeaveBalance::create(['employee_id' => $pegawai->id, 'tahun' => 2026]);
        $data = app(ShowCutiRekapAction::class)->execute(['pegawai' => $pegawai->id, 'periode' => '2026']);

        $this->assertSame([
            'summary', 'leaveBalances', 'usageRows', 'periode', 'unit', 'pegawaiId', 'jenisId',
            'selectedEmployee', 'unitOptions', 'jenisOptions', 'canAdministerBalance',
        ], array_keys($data));
        $this->assertSame(10, $data['leaveBalances']->perPage());
        $this->assertSame(10, $data['usageRows']->perPage());
        $this->assertFalse($data['canAdministerBalance']);
    }

    public function test_aksi_administrasi_saldo_mempertahankan_gate_role_dan_permission(): void
    {
        $employee = Employee::factory()->create();
        LeaveBalance::create(['employee_id' => $employee->id, 'tahun' => 2026]);
        $url = route('cuti.saldo.administrasi', ['pegawai' => $employee->id, 'periode' => 2026]);

        $admin = User::factory()->adminKepegawaian()->create();
        $this->actingAs($admin)->get(route('cuti.rekap'))
            ->assertOk()
            ->assertSee($url);

        $permissions = Permission::query()
            ->whereIn('name', ['cuti.balance.reconcile', 'cuti.manual.manage'])
            ->pluck('id');
        Role::query()->where('name', 'admin_kepegawaian')->firstOrFail()->permissions()->detach($permissions);

        $this->actingAs(User::factory()->adminKepegawaian()->create())->get(route('cuti.rekap'))
            ->assertOk()
            ->assertDontSee($url);

        Role::query()->where('name', 'super_admin')->firstOrFail()->permissions()->syncWithoutDetaching($permissions);
        $this->actingAs(User::factory()->superAdmin()->create())->get(route('cuti.rekap'))
            ->assertOk()
            ->assertDontSee($url);
    }

    public function test_tautan_laporan_rekap_mempertahankan_filter_kanonis_tanpa_url_legacy(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create(['jabatan_terakhir' => 'Bagian Tautan']);
        $unit = $this->createUnit('Bagian Tautan');
        $this->assignUnit($pegawai, $unit);
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Tautan']);
        $filters = [
            'periode' => '2026-06',
            'unit' => $unit->id,
            'pegawai' => $pegawai->id,
            'jenis' => $jenis->id,
        ];

        $response = $this->actingAs($user)->get(route('cuti.rekap', $filters));

        $response->assertOk();
        $response->assertSee(route('cuti.laporan', $filters));
        $response->assertDontSee('/laporan/export-cuti', false);
    }

    public function test_preview_memakai_filter_dan_urutan_query_bersama_dengan_paginasi_15_baris(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create(['jabatan_terakhir' => 'Bagian Laporan']);
        $pegawaiLain = Employee::factory()->create(['jabatan_terakhir' => 'Bagian Lain']);
        $unit = $this->createUnit('Bagian Laporan');
        $unitLain = $this->createUnit('Bagian Lain');
        $this->assignUnit($pegawai, $unit);
        $this->assignUnit($pegawaiLain, $unitLain);
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Preview']);

        foreach (range(1, 16) as $day) {
            $this->createLeaveRequest($pegawai, $jenis, '2026-06-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT));
        }
        $this->createLeaveRequest($pegawaiLain, $jenis, '2026-06-20');

        $filters = ['periode' => '2026-06', 'unit' => $unit->id, 'pegawai' => $pegawai->id, 'jenis' => $jenis->id];
        $expectedIds = app(CutiRekapQuery::class)->detailRows($filters)->limit(15)->pluck('id')->all();

        $response = $this->actingAs($user)->get(route('cuti.laporan', $filters));

        $response->assertOk()->assertViewIs('admin.cuti.laporan');
        $response->assertViewHas('filters', $filters);
        $response->assertViewHas('rows', function ($rows) use ($expectedIds): bool {
            return $rows->perPage() === 15
                && $rows->total() === 16
                && $rows->getCollection()->pluck('id')->all() === $expectedIds;
        });
        $response->assertSee(route('cuti.laporan.pdf', $filters));
        $response->assertSee(route('cuti.laporan.excel', $filters));
    }

    #[DataProvider('officialStatusProvider')]
    public function test_preview_menampilkan_status_resmi_atau_tahap_aktif(string $status, ?string $stepStatus, string $expected): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Status '.$expected]);
        $leaveRequest = $this->createLeaveRequest($pegawai, $jenis, '2026-06-15', $status);

        if ($stepStatus !== null) {
            LeaveRequestStep::create([
                'leave_request_id' => $leaveRequest->id,
                'step_order' => 1,
                'step_type' => 'verifikator',
                'role_label' => 'Verifikator',
                'status' => $stepStatus,
                'is_final' => false,
            ]);
        }

        $this->actingAs($user)->get(route('cuti.laporan', ['pegawai' => $pegawai->id]))
            ->assertOk()
            ->assertSee($expected);
    }

    public function test_rekap_menampilkan_label_status_resmi_bukan_token_internal(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Label Rekap']);
        $this->createLeaveRequest($pegawai, $jenis, '2026-06-15', 'perlu_perubahan');

        $this->actingAs($user)->get(route('cuti.rekap', ['pegawai' => $pegawai->id]))
            ->assertOk()
            ->assertSee('Perubahan')
            ->assertDontSee('perlu_perubahan', false);
    }

    public function test_filter_periode_tidak_dikenal_ditolak_dan_filter_kosong_tetap_memakai_default(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->getJson(route('cuti.rekap', ['periode' => 'Semester Ganjil']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('periode');

        $this->actingAs($user)->get(route('cuti.rekap'))
            ->assertOk()
            ->assertViewHas('periode', null)
            ->assertViewHas('unit', null)
            ->assertViewHas('pegawaiId', null)
            ->assertViewHas('jenisId', null);
    }

    public function test_combobox_pegawai_menandai_fetch_sebagai_ajax_agar_redirect_validasi_tetap_di_halaman(): void
    {
        $user = User::factory()->superAdmin()->create();

        $response = $this->actingAs($user)
            ->get(route('cuti.rekap'))
            ->assertOk();

        $this->assertMatchesRegularExpression(
            "/fetch\\(url,\\s*\\{\\s*headers:\\s*\\{\\s*Accept:\\s*'application\\/json',\\s*'X-Requested-With':\\s*'XMLHttpRequest'/s",
            $response->getContent(),
        );
    }

    public function test_detail_rekap_memuat_semua_status_request_dan_manual_aktif_tanpa_duplikasi_fakta_request(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create(['jabatan_terakhir' => 'Unit Rekap Aman']);
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Rekap Sumber']);
        $statuses = [
            'menunggu_approval' => 'Menunggu Verifikator SDM',
            'disetujui' => 'Disetujui melalui SIMPEG',
            'perlu_perubahan' => 'Perubahan',
            'ditangguhkan' => 'Ditangguhkan',
            LeaveRequest::STATUS_DUTY_POSTPONED => 'Ditangguhkan karena Tugas Dinas',
            LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER => 'Dikembalikan karena Rollover',
            'tidak_disetujui' => 'Tidak Disetujui',
        ];
        $requests = [];

        foreach (array_keys($statuses) as $index => $status) {
            $request = $this->createLeaveRequest(
                $pegawai,
                $jenis,
                sprintf('2026-06-%02d', $index + 1),
                $status,
            );
            $requests[$status] = $request;
        }
        LeaveRequestStep::create([
            'leave_request_id' => $requests['menunggu_approval']->id,
            'step_order' => 1,
            'step_type' => 'verifikator',
            'role_label' => 'Verifikator SDM',
            'status' => 'active',
            'is_final' => false,
        ]);

        $this->createUsage($pegawai, $jenis, [
            'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
            'leave_request_id' => $requests['disetujui']->id,
            'start_date' => '2026-06-02',
            'end_date' => '2026-06-02',
            'effective_date' => '2026-06-02',
            'administrative_note' => 'RAHASIA_APPROVED_REQUEST',
        ]);
        $manual = $this->createUsage($pegawai, $jenis, [
            'start_date' => '2026-06-20',
            'end_date' => '2026-06-20',
            'effective_date' => '2026-06-20',
            'administrative_note' => 'RAHASIA_CATATAN_MANUAL',
            'correction_reason' => null,
        ]);
        $this->createUsage($pegawai, $jenis, [
            'start_date' => '2026-06-21',
            'end_date' => '2026-06-21',
            'effective_date' => '2026-06-21',
            'record_status' => LeaveUsageRecord::STATUS_SUPERSEDED,
            'correction_reason' => 'Versi lama tidak boleh tampil.',
        ]);
        $this->createUsage($pegawai, $jenis, [
            'start_date' => '2026-06-22',
            'end_date' => '2026-06-22',
            'effective_date' => '2026-06-22',
            'record_status' => LeaveUsageRecord::STATUS_CANCELLED,
            'correction_reason' => 'Pembatalan tidak boleh tampil.',
        ]);
        $set = LeaveUsageReconciliationSet::query()->forceCreate([
            'employee_id' => $pegawai->id,
            'balance_year' => 2026,
            'reconciled_at' => '2026-06-30',
            'status' => LeaveUsageReconciliationSet::STATUS_ACTIVE,
            'administrative_note' => 'Rekonsiliasi bukan detail cuti.',
            'recorded_by' => $user->id,
        ]);
        $this->createUsage($pegawai, $jenis, [
            'source_type' => LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION,
            'reconciliation_set_id' => $set->id,
            'start_date' => null,
            'end_date' => null,
            'effective_date' => '2026-06-30',
            'workdays' => 0,
        ]);

        $response = $this->actingAs($user)->get(route('cuti.rekap', [
            'pegawai' => $pegawai->id,
            'periode' => '2026-06',
        ]))->assertOk();
        $rows = collect($response->viewData('usageRows')->items());

        $this->assertCount(8, $rows);
        $this->assertSame(1, $rows->where('id', $requests['disetujui']->id)->count());
        $this->assertSame(1, $rows->where('id', $manual->id)->count());
        $this->assertSame(7, $rows->where('sourceType', 'leave_request')->count());
        $this->assertSame(1, $rows->where('sourceType', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)->count());
        $requestRows = $rows->where('sourceType', 'leave_request');
        foreach ($statuses as $status => $label) {
            $this->assertSame($label, data_get($requestRows->firstWhere('status', $status), 'statusLabel'));
        }
        $this->assertSame('Di luar SIMPEG', data_get($rows->firstWhere('id', $manual->id), 'sourceLabel'));
        $this->assertSame('Disetujui di luar SIMPEG', data_get($rows->firstWhere('id', $manual->id), 'statusLabel'));
        $response->assertSee('Melalui SIMPEG')
            ->assertSee('Di luar SIMPEG')
            ->assertDontSee('RAHASIA_CATATAN_MANUAL')
            ->assertDontSee('RAHASIA_APPROVED_REQUEST');
    }

    public function test_filter_periode_unit_pegawai_dan_jenis_berlaku_lintas_sumber(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create(['jabatan_terakhir' => 'Unit Filter Bersama']);
        $pegawaiLain = Employee::factory()->create(['jabatan_terakhir' => 'Unit Lain']);
        $unit = $this->createUnit('Unit Filter Bersama');
        $unitLain = $this->createUnit('Unit Lain');
        $this->assignUnit($pegawai, $unit);
        $this->assignUnit($pegawaiLain, $unitLain);
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Filter Bersama']);
        $jenisLain = RefJenisCuti::create(['nama' => 'Cuti Filter Lain']);
        $request = $this->createLeaveRequest($pegawai, $jenis, '2026-06-10');
        $manual = $this->createUsage($pegawai, $jenis, [
            'start_date' => '2026-06-11',
            'end_date' => '2026-06-11',
            'effective_date' => '2026-06-11',
        ]);
        $this->createLeaveRequest($pegawaiLain, $jenis, '2026-06-12');
        $this->createLeaveRequest($pegawai, $jenisLain, '2026-06-13');
        $this->createUsage($pegawai, $jenis, [
            'usage_year' => 2025,
            'start_date' => '2025-06-11',
            'end_date' => '2025-06-11',
            'effective_date' => '2025-06-11',
        ]);

        $response = $this->actingAs($user)->get(route('cuti.rekap', [
            'periode' => '2026-06',
            'unit' => $unit->id,
            'pegawai' => $pegawai->id,
            'jenis' => $jenis->id,
        ]))->assertOk();
        $rows = collect($response->viewData('usageRows')->items());

        $this->assertSame([$manual->id, $request->id], $rows->pluck('id')->all());
    }

    public function test_filter_unit_memakai_riwayat_jabatan_terkini_untuk_detail_saldo_dan_ringkasan(): void
    {
        $unitTarget = $this->createUnit('Unit Kanonis Target');
        $unitLain = $this->createUnit('Unit Kanonis Lain');
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Unit Kanonis']);
        $teksSamaTarget = Employee::factory()->create(['jabatan_terakhir' => 'Teks Jabatan Sama']);
        $teksSamaLain = Employee::factory()->create(['jabatan_terakhir' => 'Teks Jabatan Sama']);
        $teksBerbedaTarget = Employee::factory()->create(['jabatan_terakhir' => 'Teks Jabatan Berbeda']);
        $this->assignUnit($teksSamaTarget, $unitTarget);
        $this->assignUnit($teksSamaLain, $unitLain);
        $this->assignUnit($teksBerbedaTarget, $unitTarget);
        PositionHistory::create([
            'employee_id' => $teksSamaTarget->id,
            'nama_jabatan' => 'Riwayat Bukan Terkini',
            'unit_kerja_id' => $unitLain->id,
            'tmt_jabatan' => '2030-01-01',
            'is_latest' => false,
        ]);

        $requestTarget = $this->createLeaveRequest($teksSamaTarget, $jenis, '2026-06-10');
        $requestLain = $this->createLeaveRequest($teksSamaLain, $jenis, '2026-06-11');
        $manualTarget = $this->createUsage($teksBerbedaTarget, $jenis, [
            'start_date' => '2026-06-12',
            'end_date' => '2026-06-12',
            'effective_date' => '2026-06-12',
        ]);
        $this->createUsage($teksSamaLain, $jenis, [
            'start_date' => '2026-06-13',
            'end_date' => '2026-06-13',
            'effective_date' => '2026-06-13',
        ]);
        $this->createUsage($teksSamaTarget, $jenis, [
            'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
            'leave_request_id' => $requestTarget->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-10',
            'effective_date' => '2026-06-10',
        ]);
        $this->createUsage($teksSamaLain, $jenis, [
            'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
            'leave_request_id' => $requestLain->id,
            'start_date' => '2026-06-11',
            'end_date' => '2026-06-11',
            'effective_date' => '2026-06-11',
        ]);
        foreach ([$teksSamaTarget, $teksSamaLain, $teksBerbedaTarget] as $employee) {
            LeaveBalance::create(['employee_id' => $employee->id, 'tahun' => 2026]);
        }

        $filters = ['unit' => $unitTarget->id, 'periode' => '2026-06'];
        $detail = app(CutiRekapQuery::class)->allDetailRows($filters);
        $balances = app(CutiRekapQuery::class)->balanceRows($filters)->get();
        $summary = app(CutiRekapQuery::class)->summaryRows($filters);
        $expectedEmployeeIds = [$teksSamaTarget->id, $teksBerbedaTarget->id];
        sort($expectedEmployeeIds);

        $this->assertSame([$manualTarget->id, $requestTarget->id], $detail->pluck('id')->all());
        $this->assertSame([$unitTarget->nama], $detail->pluck('unit')->unique()->values()->all());
        $this->assertSame($expectedEmployeeIds, $balances->pluck('employee_id')->sort()->values()->all());
        $this->assertSame($expectedEmployeeIds, $summary->pluck('employee_id')->sort()->values()->all());
    }

    public function test_ringkasan_tahunan_memakai_total_pemakaian_efektif_dari_saldo_material(): void
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();
        $annual = RefJenisCuti::query()->create([
            'code' => 'tahunan',
            'nama' => 'Cuti Tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $covered = $this->createUsage($employee, $annual, [
            'usage_year' => 2024,
            'effective_date' => '2024-06-03',
            'start_date' => '2024-06-03',
            'end_date' => '2024-06-05',
            'workdays' => 3,
        ]);
        $set = LeaveUsageReconciliationSet::query()->create([
            'employee_id' => $employee->id,
            'balance_year' => 2026,
            'reconciled_at' => '2026-08-20',
            'status' => LeaveUsageReconciliationSet::STATUS_ACTIVE,
            'administrative_note' => 'Snapshot agregat delapan hari.',
            'recorded_by' => $actor->id,
        ]);
        $declaration = $this->createUsage($employee, $annual, [
            'source_type' => LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION,
            'reconciliation_set_id' => $set->id,
            'usage_year' => 2024,
            'effective_date' => '2024-12-31',
            'start_date' => null,
            'end_date' => null,
            'workdays' => 8,
            'recorded_by' => $actor->id,
        ]);
        LeaveUsageReconciliationMembership::query()->create([
            'reconciliation_set_id' => $set->id,
            'annual_reconciliation_record_id' => $declaration->id,
            'itemized_usage_record_id' => $covered->id,
            'included_workdays' => 3,
        ]);
        $this->createUsage($employee, $annual, [
            'usage_year' => 2024,
            'effective_date' => '2024-06-10',
            'start_date' => '2024-06-10',
            'end_date' => '2024-06-11',
            'workdays' => 2,
        ]);
        LeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'tahun' => 2024,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 10,
            'sisa' => 2,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 2,
            'terpakai_tahun_berjalan' => 10,
            'hangus' => 0,
        ]);

        $row = app(CutiRekapQuery::class)->summaryRows(['periode' => '2024'])->sole();

        $this->assertSame($employee->id, $row['employee_id']);
        $this->assertSame('Cuti Tahunan', $row['jenis']);
        $this->assertSame(10, $row['total_hari']);
        $this->assertSame(2, $row['sisa_saldo']);
    }

    public function test_ringkasan_bulanan_tidak_mengarang_tanggal_deklarasi_agregat(): void
    {
        $employee = Employee::factory()->create();
        $annual = RefJenisCuti::query()->create([
            'code' => 'tahunan',
            'nama' => 'Cuti Tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $this->createUsage($employee, $annual, [
            'usage_year' => 2024,
            'effective_date' => '2024-06-03',
            'start_date' => '2024-06-03',
            'end_date' => '2024-06-05',
            'workdays' => 3,
        ]);
        LeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'tahun' => 2024,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 8,
            'sisa' => 4,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 4,
            'terpakai_tahun_berjalan' => 8,
            'hangus' => 0,
        ]);

        $row = app(CutiRekapQuery::class)->summaryRows(['periode' => '2024-06'])->sole();

        $this->assertSame(3, $row['total_hari']);
    }

    public function test_rekap_dan_laporan_merender_kontrol_get_kanonis_dengan_opsi_aman_terbatas(): void
    {
        $user = User::factory()->superAdmin()->create();
        $unit = $this->createUnit('Unit Kontrol Laporan');
        $pegawai = Employee::factory()->create(['nama_lengkap' => 'Pegawai Kontrol', 'nip' => '199001010001']);
        $this->assignUnit($pegawai, $unit);
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Kontrol Laporan']);
        $this->createLeaveRequest($pegawai, $jenis, '2026-06-10');
        $filters = [
            'periode' => '2026-06',
            'unit' => $unit->id,
            'pegawai' => $pegawai->id,
            'jenis' => $jenis->id,
        ];

        foreach (['cuti.rekap', 'cuti.laporan'] as $routeName) {
            $response = $this->actingAs($user)->get(route($routeName, $filters))->assertOk();

            $response->assertSee('method="GET"', false)
                ->assertSee('type="month"', false)
                ->assertSee('name="periode"', false)
                ->assertSee('value="2026-06"', false)
                ->assertSee('name="unit"', false)
                ->assertSee('name="jenis"', false)
                ->assertSee('value="'.$unit->id.'" selected', false)
                ->assertSee('value="'.$jenis->id.'" selected', false)
                ->assertSee('role="combobox"', false)
                ->assertSee('Pegawai Kontrol (199001010001)');
            $response->assertViewHas('unitOptions', fn ($options): bool => $options->count() <= 200
                && array_keys((array) $options->first()) === ['id', 'nama']);
            $response->assertViewHas('jenisOptions', fn ($options): bool => $options->count() <= 100
                && array_keys((array) $options->first()) === ['id', 'nama']);
        }

        $this->actingAs($user)->get(route('cuti.rekap', $filters))
            ->assertSee(route('cuti.laporan', $filters));
        $this->actingAs($user)->get(route('cuti.laporan', $filters))
            ->assertSee(route('cuti.laporan.pdf', $filters))
            ->assertSee(route('cuti.laporan.excel', $filters));
    }

    public function test_filter_rekap_dan_laporan_memakai_satu_form_get_dengan_satu_aksi_terapkan(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Filter Terpadu',
            'nip' => '199001012020011001',
        ]);
        $filters = ['periode' => '2026-08', 'pegawai' => $pegawai->id];

        foreach ([
            'cuti.rekap' => ['prefix' => 'rekap', 'label' => 'Filter rekap cuti'],
            'cuti.laporan' => ['prefix' => 'laporan', 'label' => 'Filter laporan cuti'],
        ] as $routeName => $expectation) {
            $response = $this->actingAs($user)->get(route($routeName, $filters))->assertOk();
            $html = (string) $response->getContent();
            $document = new \DOMDocument;

            libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
            libxml_clear_errors();

            $xpath = new \DOMXPath($document);
            $forms = $xpath->query(sprintf(
                '//section[@aria-label="%s"]//form',
                $expectation['label'],
            ));

            $this->assertNotFalse($forms);
            $this->assertCount(1, $forms, 'Seluruh kontrol filter harus berada di satu form GET.');
            $form = $forms->item(0);
            $this->assertNotNull($form);
            $this->assertSame($expectation['prefix'].'-filter', $form->attributes?->getNamedItem('id')?->nodeValue);
            $this->assertSame('GET', strtoupper((string) $form->attributes?->getNamedItem('method')?->nodeValue));

            foreach (['periode', 'unit', 'pegawai', 'jenis'] as $field) {
                $fields = $xpath->query('.//*[@name="'.$field.'"]', $form);
                $this->assertNotFalse($fields);
                $this->assertCount(1, $fields, "Filter {$field} harus dikirim tepat sekali.");
            }

            $employeeFields = $xpath->query('.//input[@type="hidden" and @name="pegawai"]', $form);
            $this->assertNotFalse($employeeFields);
            $employeeField = $employeeFields->item(0);
            $this->assertNotNull($employeeField);
            $this->assertSame($pegawai->id, $employeeField->attributes?->getNamedItem('value')?->nodeValue);
            $this->assertStringContainsString('@keydown.enter="selectActive($event)"', $html);
            $this->assertStringNotContainsString('@keydown.enter.prevent', $html);
            $this->assertStringContainsString('event.currentTarget?.form?.requestSubmit()', $html);
            $this->assertStringContainsString('this.$nextTick(() => document.getElementById', $html);

            $submitButtons = $xpath->query('.//button[@type="submit"]', $form);
            $resetLinks = $xpath->query('.//a[@data-filter-reset]', $form);
            $this->assertNotFalse($submitButtons);
            $this->assertNotFalse($resetLinks);
            $this->assertCount(1, $submitButtons);
            $this->assertCount(1, $resetLinks);
            $this->assertStringContainsString('Terapkan Filter', $submitButtons->item(0)?->textContent ?? '');
            $visibleFilterText = preg_replace('/\s+/', ' ', $form->textContent ?? '');
            $this->assertIsString($visibleFilterText);
            $this->assertStringContainsString('Aktif: Agustus 2026', $visibleFilterText);
            $this->assertStringNotContainsString('Pakai Tahun', $html);
            $this->assertStringNotContainsString('Pakai Bulan', $html);
        }
    }

    public function test_mode_tahun_merender_nilai_aktif_dan_mempertahankannya_pada_form_link_dan_paginator(): void
    {
        $user = User::factory()->superAdmin()->create();
        $unit = $this->createUnit('Unit Periode Tahunan');
        $pegawai = Employee::factory()->create(['nama_lengkap' => 'Pegawai Periode Tahunan']);
        $this->assignUnit($pegawai, $unit);
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Periode Tahunan']);
        foreach (range(1, 16) as $day) {
            $this->createLeaveRequest($pegawai, $jenis, sprintf('2026-06-%02d', $day));
        }
        $filters = [
            'periode' => '2026',
            'unit' => $unit->id,
            'pegawai' => $pegawai->id,
            'jenis' => $jenis->id,
        ];

        foreach ([
            'cuti.rekap' => ['prefix' => 'rekap', 'paginator' => 'usageRows'],
            'cuti.laporan' => ['prefix' => 'laporan', 'paginator' => 'rows'],
        ] as $routeName => $expectation) {
            $response = $this->actingAs($user)->get(route($routeName, $filters))->assertOk();
            $html = (string) $response->getContent();

            $this->assertMatchesRegularExpression(
                '/id="'.$expectation['prefix'].'-periode-tahun"[^>]*value="2026"/',
                $html,
            );
            $this->assertMatchesRegularExpression(
                '/id="'.$expectation['prefix'].'-periode-bulan"[^>]*value=""/',
                $html,
            );
            $this->assertMatchesRegularExpression(
                '/<form[^>]*id="'.$expectation['prefix'].'-filter"[^>]*>.*?<input type="hidden" name="periode" value="2026"/s',
                $html,
            );
            $document = new \DOMDocument;
            libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
            libxml_clear_errors();
            $annualInput = $document->getElementById($expectation['prefix'].'-periode-tahun');
            $monthlyInput = $document->getElementById($expectation['prefix'].'-periode-bulan');
            $appliedPeriod = (new \DOMXPath($document))->query('//*[@data-applied-period]')?->item(0);
            $this->assertNotNull($annualInput);
            $this->assertNotNull($monthlyInput);
            $this->assertInstanceOf(\DOMElement::class, $appliedPeriod);
            $this->assertFalse($annualInput->hasAttribute('disabled'));
            $this->assertTrue($monthlyInput->hasAttribute('disabled'));
            $this->assertSame("mode !== 'tahun'", $annualInput->getAttribute(':disabled'));
            $this->assertSame("mode !== 'bulan'", $monthlyInput->getAttribute(':disabled'));
            $this->assertSame('Aktif: Tahun 2026', trim($appliedPeriod->textContent));
            $this->assertFalse($appliedPeriod->hasAttribute('x-text'));
            $response->assertSeeText('Tahun 2026');
            $this->assertStringContainsString(
                'periode=2026',
                $response->viewData($expectation['paginator'])->url(2),
            );
        }

        $this->actingAs($user)->get(route('cuti.rekap', $filters))
            ->assertSee(route('cuti.laporan', $filters));
        $this->actingAs($user)->get(route('cuti.laporan', $filters))
            ->assertSee(route('cuti.laporan.pdf', $filters))
            ->assertSee(route('cuti.laporan.excel', $filters));
    }

    public function test_mode_bulan_merender_bulan_aktif_dan_tahun_asalnya_pada_kedua_halaman(): void
    {
        $user = User::factory()->superAdmin()->create();
        $filters = ['periode' => '2026-06'];

        foreach (['cuti.rekap' => 'rekap', 'cuti.laporan' => 'laporan'] as $routeName => $prefix) {
            $response = $this->actingAs($user)->get(route($routeName, $filters))->assertOk();
            $html = (string) $response->getContent();

            $this->assertMatchesRegularExpression(
                '/id="'.$prefix.'-periode-tahun"[^>]*value="2026"/',
                $html,
            );
            $this->assertMatchesRegularExpression(
                '/id="'.$prefix.'-periode-bulan"[^>]*value="2026-06"/',
                $html,
            );
            $this->assertMatchesRegularExpression(
                '/<form[^>]*id="'.$prefix.'-filter"[^>]*>.*?<input type="hidden" name="periode" value="2026-06"/s',
                $html,
            );
            $document = new \DOMDocument;
            libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
            libxml_clear_errors();
            $annualInput = $document->getElementById($prefix.'-periode-tahun');
            $monthlyInput = $document->getElementById($prefix.'-periode-bulan');
            $appliedPeriod = (new \DOMXPath($document))->query('//*[@data-applied-period]')?->item(0);
            $this->assertNotNull($annualInput);
            $this->assertNotNull($monthlyInput);
            $this->assertInstanceOf(\DOMElement::class, $appliedPeriod);
            $this->assertTrue($annualInput->hasAttribute('disabled'));
            $this->assertFalse($monthlyInput->hasAttribute('disabled'));
            $this->assertSame("mode !== 'tahun'", $annualInput->getAttribute(':disabled'));
            $this->assertSame("mode !== 'bulan'", $monthlyInput->getAttribute(':disabled'));
            $this->assertSame('Aktif: Juni 2026', trim($appliedPeriod->textContent));
            $this->assertFalse($appliedPeriod->hasAttribute('x-text'));
            $response->assertSeeText('Juni 2026');
        }

        $this->actingAs($user)->get(route('cuti.laporan', $filters))
            ->assertSee(route('cuti.laporan.pdf', $filters))
            ->assertSee(route('cuti.laporan.excel', $filters));
    }

    public function test_periode_invalid_atau_konflik_ditolak_di_semua_permukaan_laporan(): void
    {
        $user = User::factory()->superAdmin()->create();

        foreach (['cuti.rekap', 'cuti.laporan', 'cuti.laporan.pdf', 'cuti.laporan.excel'] as $routeName) {
            foreach (['2026-13', ['2026', '2026-06']] as $periode) {
                $this->actingAs($user)
                    ->getJson(route($routeName, ['periode' => $periode]))
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors('periode');
            }
        }
    }

    public function test_opsi_referensi_mematuhi_cap_payload_aman_dan_selalu_memuat_pilihan_aktif(): void
    {
        foreach (range(1, 200) as $index) {
            $this->createUnit(sprintf('AAA Unit %03d', $index));
        }
        $selectedUnit = $this->createUnit('ZZZ Unit Terpilih');
        foreach (range(1, 100) as $index) {
            RefJenisCuti::create(['nama' => sprintf('AAA Jenis %03d', $index)]);
        }
        $selectedJenis = RefJenisCuti::create(['nama' => 'ZZZ Jenis Terpilih']);
        $query = app(CutiRekapQuery::class);

        $this->assertFalse($query->unitOptions()->contains('id', $selectedUnit->id));
        $this->assertFalse($query->leaveTypeOptions()->contains('id', $selectedJenis->id));

        $unitOptions = $query->unitOptions($selectedUnit->id);
        $jenisOptions = $query->leaveTypeOptions($selectedJenis->id);

        $this->assertCount(200, $unitOptions);
        $this->assertCount(100, $jenisOptions);
        $this->assertSame($selectedUnit->id, $unitOptions->first()['id']);
        $this->assertSame($selectedJenis->id, $jenisOptions->first()['id']);
        foreach ($unitOptions->concat($jenisOptions) as $option) {
            $this->assertSame(['id', 'nama'], array_keys($option));
        }
    }

    public function test_pagination_detail_union_stabil_di_database_dan_mempertahankan_query_string(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Pagination Union']);
        $requestIds = [];
        $manualIds = [];

        foreach (range(1, 6) as $index) {
            $requestIds[] = $this->createLeaveRequestWithId(
                $pegawai,
                $jenis,
                sprintf('10000000-0000-4000-8000-%012d', $index),
                '2026-06-10',
                '2026-06-10 08:00:00',
            )->id;
            $manualIds[] = $this->createUsage($pegawai, $jenis, [
                'id' => sprintf('20000000-0000-4000-8000-%012d', $index),
                'start_date' => '2026-06-10',
                'end_date' => sprintf('2026-06-%02d', 9 + $index),
                'effective_date' => '2026-06-10',
                'created_at' => '2026-06-10 08:00:00',
                'updated_at' => '2026-06-10 08:00:00',
            ])->id;
        }

        sort($requestIds);
        sort($manualIds);
        $response = $this->actingAs($user)->get(route('cuti.rekap', [
            'pegawai' => $pegawai->id,
            'periode' => '2026-06',
            'page_usage' => 2,
        ]))->assertOk();
        $rows = $response->viewData('usageRows');

        $this->assertSame(12, $rows->total());
        $this->assertSame(2, $rows->count());
        $this->assertSame(array_slice($manualIds, 4), $rows->pluck('id')->all());
        $this->assertStringContainsString('pegawai='.$pegawai->id, $rows->url(1));
        $this->assertStringContainsString('periode=2026-06', $rows->url(1));
    }

    public function test_rekap_menampilkan_label_pengembalian_rollover_bukan_token_internal(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Label Rollover Rekap']);
        $this->createLeaveRequest($pegawai, $jenis, '2026-06-15', LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER)
            ->forceFill([
                'rollover_source_year' => 2026,
                'rollover_target_year' => 2027,
            ])->save();

        $this->actingAs($user)->get(route('cuti.rekap', ['pegawai' => $pegawai->id]))
            ->assertOk()
            ->assertSee('Dikembalikan karena Rollover')
            ->assertDontSee(LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER, false);
    }

    public function test_rekap_menampilkan_label_status_saldo(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        LeaveBalance::create([
            'employee_id' => $pegawai->id,
            'tahun' => 2026,
            'sisa' => 7,
        ]);

        $this->actingAs($user)->get(route('cuti.rekap', [
            'pegawai' => $pegawai->id,
            'periode' => '2026',
        ]))
            ->assertOk()
            ->assertSee('Aman');
    }

    #[DataProvider('rekapWaitingStatusProvider')]
    public function test_rekap_menampilkan_label_tahap_persetujuan_aktif(
        string $stepType,
        ?string $roleLabel,
        string $expected,
    ): void {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Tahap Rekap']);
        $leaveRequest = $this->createLeaveRequest($pegawai, $jenis, '2026-06-15', 'menunggu_approval');

        if ($roleLabel !== null) {
            LeaveRequestStep::create([
                'leave_request_id' => $leaveRequest->id,
                'step_order' => 1,
                'step_type' => $stepType,
                'role_label' => $roleLabel,
                'status' => 'active',
                'is_final' => false,
            ]);
        }

        $response = $this->actingAs($user)->get(route('cuti.rekap', ['pegawai' => $pegawai->id]));
        $row = collect($response->viewData('usageRows')->items())->firstWhere('id', $leaveRequest->id);

        $response->assertOk()->assertDontSee('menunggu_approval', false);
        $this->assertSame($expected, $row->statusLabel);
    }

    public function test_tahap_aktif_memilih_step_order_terawal_dan_mengabaikan_future_pending_tanpa_duplikasi(): void
    {
        $pegawai = Employee::factory()->create();
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Tahap Deterministik']);
        $leaveRequest = $this->createLeaveRequest($pegawai, $jenis, '2026-06-15', 'menunggu_approval');
        LeaveRequestStep::create([
            'leave_request_id' => $leaveRequest->id,
            'step_order' => 3,
            'step_type' => 'future',
            'role_label' => 'Tahap Masa Depan',
            'status' => 'pending',
            'is_final' => true,
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leaveRequest->id,
            'step_order' => 2,
            'step_type' => 'active_later',
            'role_label' => 'A Aktif Belakangan',
            'status' => 'active',
            'is_final' => false,
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leaveRequest->id,
            'step_order' => 1,
            'step_type' => 'active_first',
            'role_label' => 'Z Aktif Pertama',
            'status' => 'active',
            'is_final' => false,
        ]);

        $rows = app(CutiRekapQuery::class)->allDetailRows(['pegawai' => $pegawai->id]);

        $this->assertCount(1, $rows);
        $this->assertSame('Z Aktif Pertama', $rows->sole()->currentStepLabel);
        $this->assertSame('Menunggu Z Aktif Pertama', $rows->sole()->statusLabel);
    }

    /** @return array<string, array{string, string|null, string}> */
    public static function officialStatusProvider(): array
    {
        return [
            'disetujui' => ['disetujui', null, 'Disetujui melalui SIMPEG'],
            'ditangguhkan' => ['ditangguhkan', null, 'Ditangguhkan'],
            'perlu perubahan' => ['perlu_perubahan', null, 'Perubahan'],
            'tidak disetujui' => ['tidak_disetujui', null, 'Tidak Disetujui'],
            'dikembalikan karena rollover' => [LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER, null, 'Dikembalikan karena Rollover'],
            'tahap aktif' => ['menunggu_approval', 'active', 'Menunggu Verifikator'],
            'fallback approver' => ['menunggu_approval', null, 'Menunggu Approver'],
        ];
    }

    /** @return array<string, array{string, string|null, string}> */
    public static function rekapWaitingStatusProvider(): array
    {
        return [
            'tahap aktif' => ['verifier', 'Verifikator', 'Menunggu Verifikator'],
            'label lama atasan langsung' => ['kepala_bagian', 'Kepala Bagian', 'Menunggu Atasan Langsung'],
            'fallback approver' => ['verifier', null, 'Menunggu Approver'],
        ];
    }

    public function test_detail_rows_menerapkan_filter_kanonis_eager_load_dan_urutan_stabil(): void
    {
        $pegawaiCocok = Employee::factory()->create(['jabatan_terakhir' => 'Bagian SDM']);
        $pegawaiLain = Employee::factory()->create(['jabatan_terakhir' => 'Bagian Umum']);
        $unitCocok = $this->createUnit('Bagian SDM');
        $unitLain = $this->createUnit('Bagian Umum');
        $this->assignUnit($pegawaiCocok, $unitCocok);
        $this->assignUnit($pegawaiLain, $unitLain);
        $jenisCocok = RefJenisCuti::create(['nama' => 'Cuti Tahunan Rekap']);
        $jenisLain = RefJenisCuti::create(['nama' => 'Cuti Sakit Rekap']);

        $lama = $this->createLeaveRequest($pegawaiCocok, $jenisCocok, '2026-05-10');
        $tanggalSamaPertama = $this->createLeaveRequest($pegawaiCocok, $jenisCocok, '2026-06-10');
        $tanggalSamaKedua = $this->createLeaveRequest($pegawaiCocok, $jenisCocok, '2026-06-10');
        $this->createLeaveRequest($pegawaiLain, $jenisCocok, '2026-06-11');
        $this->createLeaveRequest($pegawaiCocok, $jenisLain, '2026-06-12');
        LeaveRequestStep::create([
            'leave_request_id' => $tanggalSamaKedua->id,
            'step_order' => 1,
            'step_type' => 'atasan_langsung',
            'role_label' => 'Atasan Langsung',
            'status' => 'active',
            'is_final' => false,
        ]);

        $rows = app(CutiRekapQuery::class)->allDetailRows([
            'unit' => $unitCocok->id,
            'pegawai' => $pegawaiCocok->id,
            'jenis' => $jenisCocok->id,
            'periode' => '2026',
        ]);

        $sameDateIds = [$tanggalSamaPertama->id, $tanggalSamaKedua->id];
        sort($sameDateIds);
        $this->assertSame([...$sameDateIds, $lama->id], $rows->pluck('id')->all());
        $this->assertSame('Atasan Langsung', $rows->firstWhere('id', $tanggalSamaKedua->id)->currentStepLabel);
        $this->assertSame([
            'id', 'employeeId', 'leaveTypeId', 'sourceType', 'sourceLabel', 'nip', 'nama', 'unit',
            'jenis', 'tanggalMulai', 'tanggalSelesai', 'hari', 'status', 'statusLabel', 'currentStepLabel',
        ], array_keys(get_object_vars($rows->first())));
        foreach (['administrative_note', 'correction_reason', 'recorded_by', 'documents', 'path', 'disk', 'mime_type', 'original_name'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, get_object_vars($rows->first()));
        }
    }

    #[DataProvider('recognizedPeriodProvider')]
    public function test_detail_rows_memahami_setiap_format_periode(string $periode): void
    {
        $pegawai = Employee::factory()->create();
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Periode '.$periode]);
        $juni = $this->createLeaveRequest($pegawai, $jenis, '2026-06-15');
        $this->createLeaveRequest($pegawai, $jenis, '2026-07-15');
        $this->createLeaveRequest($pegawai, $jenis, '2025-06-15');

        $ids = app(CutiRekapQuery::class)->detailRows(['periode' => $periode])->pluck('id')->all();

        $expected = $periode === '2026'
            ? LeaveRequest::query()->whereYear('tanggal_mulai', 2026)
                ->orderByDesc('tanggal_mulai')->orderBy('id')->pluck('id')->all()
            : [$juni->id];
        $this->assertSame($expected, $ids);
    }

    /** @return array<string, array{string}> */
    public static function recognizedPeriodProvider(): array
    {
        return [
            'tahun' => ['2026'],
            'tahun bulan' => ['2026-06'],
            'bulan Indonesia dan tahun' => ['Juni 2026'],
        ];
    }

    public function test_detail_rows_mengabaikan_periode_tidak_dikenal(): void
    {
        $pegawai = Employee::factory()->create();
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Periode Tidak Dikenal']);
        $terbaru = $this->createLeaveRequest($pegawai, $jenis, '2026-08-01');
        $terlama = $this->createLeaveRequest($pegawai, $jenis, '2025-01-01');

        $ids = app(CutiRekapQuery::class)->detailRows(['periode' => 'Semester Ganjil'])->pluck('id')->all();

        $this->assertSame([$terbaru->id, $terlama->id], $ids);
    }

    #[DataProvider('recognizedPeriodProvider')]
    public function test_balance_rows_memakai_tahun_dari_semua_format_periode_dan_saldo_materialized(
        string $periode,
    ): void {
        $pegawai = Employee::factory()->create(['jabatan_terakhir' => 'Bagian Keuangan']);
        $pegawaiLain = Employee::factory()->create(['jabatan_terakhir' => 'Bagian SDM']);
        $unit = $this->createUnit('Bagian Keuangan');
        $unitLain = $this->createUnit('Bagian SDM');
        $this->assignUnit($pegawai, $unit);
        $this->assignUnit($pegawaiLain, $unitLain);
        $saldo = LeaveBalance::create([
            'employee_id' => $pegawai->id,
            'tahun' => 2026,
            'jatah_awal' => 40,
            'carry_over' => 8,
            'terpakai' => 7,
            'sisa' => 31,
            'sisa_n2' => 2,
            'sisa_n1' => 5,
            'sisa_tahun_berjalan' => 24,
            'terpakai_tahun_berjalan' => 7,
            'hangus' => 1,
        ]);
        LeaveBalance::create(['employee_id' => $pegawai->id, 'tahun' => 2025]);
        LeaveBalance::create(['employee_id' => $pegawaiLain->id, 'tahun' => 2026]);

        $rows = app(CutiRekapQuery::class)->balanceRows([
            'unit' => $unit->id,
            'pegawai' => $pegawai->id,
            'periode' => $periode,
        ])->get();

        $this->assertCount(1, $rows);
        $this->assertSame($saldo->id, $rows->first()->id);
        $this->assertSame(40, $rows->first()->jatah_awal);
        $this->assertSame(31, $rows->first()->sisa);
        $this->assertSame(2, $rows->first()->sisa_n2);
        $this->assertTrue($rows->first()->relationLoaded('employee'));
        $this->assertSame(
            ['id', 'nama_lengkap', 'nip'],
            array_keys($rows->first()->employee->getAttributes()),
        );
        $this->assertSame('Bagian Keuangan', $rows->first()->getAttribute('unit_name'));
    }

    public function test_balance_rows_memakai_urutan_deterministik_hingga_id(): void
    {
        $pegawaiA = Employee::factory()->create(['id' => '10000000-0000-4000-8000-000000000001']);
        $pegawaiB = Employee::factory()->create(['id' => '20000000-0000-4000-8000-000000000002']);
        $saldoB2026 = LeaveBalance::unguarded(fn () => LeaveBalance::create([
            'id' => '40000000-0000-4000-8000-000000000004',
            'employee_id' => $pegawaiB->id,
            'tahun' => 2026,
        ]));
        $saldoA2026 = LeaveBalance::unguarded(fn () => LeaveBalance::create([
            'id' => '30000000-0000-4000-8000-000000000003',
            'employee_id' => $pegawaiA->id,
            'tahun' => 2026,
        ]));
        $saldoA2025 = LeaveBalance::unguarded(fn () => LeaveBalance::create([
            'id' => '50000000-0000-4000-8000-000000000005',
            'employee_id' => $pegawaiA->id,
            'tahun' => 2025,
        ]));

        $query = app(CutiRekapQuery::class)->balanceRows([]);
        $rows = $query->get()->map(fn (LeaveBalance $balance): array => [
            $balance->tahun,
            $balance->employee_id,
            $balance->id,
        ])->all();

        $this->assertSame([
            [2026, $pegawaiA->id, $saldoA2026->id],
            [2026, $pegawaiB->id, $saldoB2026->id],
            [2025, $pegawaiA->id, $saldoA2025->id],
        ], $rows);
        $this->assertSame([
            ['column' => 'balances.tahun', 'direction' => 'desc'],
            ['column' => 'balances.employee_id', 'direction' => 'asc'],
            ['column' => 'balances.id', 'direction' => 'asc'],
        ], $query->getQuery()->orders);
    }

    #[DataProvider('invalidDirectQueryUuidProvider')]
    public function test_query_menolak_uuid_tidak_aman_dari_pemanggil_langsung(
        string $method,
        string $field,
        mixed $value,
    ): void {
        $this->expectException(NotFoundHttpException::class);

        app(CutiRekapQuery::class)->{$method}([$field => $value]);
    }

    /** @return array<string, array{string, string, mixed}> */
    public static function invalidDirectQueryUuidProvider(): array
    {
        return [
            'detail unit malformed' => ['detailRows', 'unit', 'bukan-uuid'],
            'detail unit array' => ['detailRows', 'unit', ['uuid']],
            'detail pegawai malformed' => ['detailRows', 'pegawai', 'bukan-uuid'],
            'detail pegawai array' => ['detailRows', 'pegawai', ['uuid']],
            'detail jenis malformed' => ['detailRows', 'jenis', 'bukan-uuid'],
            'detail jenis array' => ['detailRows', 'jenis', ['uuid']],
            'balance pegawai malformed' => ['balanceRows', 'pegawai', 'bukan-uuid'],
            'balance pegawai array' => ['balanceRows', 'pegawai', ['uuid']],
            'balance unit malformed' => ['balanceRows', 'unit', 'bukan-uuid'],
            'balance unit array' => ['balanceRows', 'unit', ['uuid']],
        ];
    }

    private function createLeaveRequest(
        Employee $employee,
        RefJenisCuti $jenis,
        string $tanggalMulai,
        string $status = 'disetujui',
    ): LeaveRequest {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => $tanggalMulai,
            'tanggal_selesai' => $tanggalMulai,
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Data pengujian rekap cuti',
            'status' => $status,
        ]);
    }

    private function createUnit(string $nama): RefUnitKerja
    {
        return RefUnitKerja::create([
            'nama' => $nama,
            'jenis_unit' => 'bagian',
            'is_active' => true,
        ]);
    }

    private function assignUnit(Employee $employee, RefUnitKerja $unit): PositionHistory
    {
        return PositionHistory::create([
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Jabatan '.$employee->nama_lengkap,
            'unit_kerja_id' => $unit->id,
            'tmt_jabatan' => '2026-01-01',
            'is_latest' => true,
        ]);
    }

    private function createLeaveRequestWithId(
        Employee $employee,
        RefJenisCuti $jenis,
        string $id,
        string $tanggalMulai,
        string $createdAt,
    ): LeaveRequest {
        return LeaveRequest::query()->forceCreate([
            'id' => $id,
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => $tanggalMulai,
            'tanggal_selesai' => $tanggalMulai,
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Data pengujian pagination union.',
            'status' => 'menunggu_approval',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createUsage(Employee $employee, RefJenisCuti $jenis, array $overrides = []): LeaveUsageRecord
    {
        $record = LeaveUsageRecord::query()->forceCreate(array_merge([
            'id' => fake()->uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => $jenis->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'reconciliation_set_id' => null,
            'leave_request_id' => null,
            'leave_request_case_id' => null,
            'usage_year' => 2026,
            'effective_date' => '2026-06-20',
            'start_date' => '2026-06-20',
            'end_date' => '2026-06-20',
            'workdays' => 1,
            'administrative_note' => 'Fixture detail rekap source-aware.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'replaces_id' => null,
            'correction_reason' => null,
            'recorded_by' => null,
            'created_at' => '2026-06-20 08:00:00',
            'updated_at' => '2026-06-20 08:00:00',
        ], $overrides));

        return $record->source_type === LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL
            ? $this->attachValidManualApprovalSnapshot($record)
            : $record;
    }
}
