<?php

namespace Tests\Feature;

use App\Actions\Cuti\ShowCutiRekapAction;
use App\Http\Requests\Cuti\ListCutiRekapRequest;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RefJenisCuti;
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
        $pegawai = Employee::factory()->create();
        $request = new ListCutiRekapRequest;

        $validator = Validator::make([
            'periode' => 'Juni 2026',
            'unit' => 'Bagian Kepegawaian',
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
            'unit' => str_repeat('u', 151),
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

        foreach (['cuti.laporan', 'cuti.laporan.pdf', 'cuti.laporan.excel'] as $routeName) {
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

        $this->actingAs($pimpinan)->get(route('cuti.rekap'))->assertOk();
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
            foreach (['pegawai', 'jenis'] as $field) {
                $cases[$path.' '.$field.' malformed'] = [$path, $field, 'bukan-uuid'];
                $cases[$path.' '.$field.' array'] = [$path, $field, ['uuid']];
            }
        }

        return $cases;
    }

    public function test_action_rekap_mengembalikan_11_key_dengan_paginasi_dan_rollover_terbatas(): void
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
        foreach (range(1, 11) as $index) {
            LeaveBalanceLedger::create([
                'employee_id' => $pegawai->id,
                'tahun' => 2026,
                'event_type' => $index <= 6 ? 'rollover_applied' : 'manual_adjustment',
                'amount' => 1,
                'reason' => 'Kontrak ledger '.$index,
                'occurred_at' => now()->subMinutes($index),
            ]);
        }

        $data = app(ShowCutiRekapAction::class)->execute(['pegawai' => $pegawai->id, 'periode' => '2026']);

        $this->assertSame([
            'summary', 'leaveBalances', 'usageRows', 'periode', 'unit', 'pegawaiId', 'jenisId',
            'selectedEmployee', 'selectedBalance', 'ledgerRows', 'rolloverRows',
        ], array_keys($data));
        $this->assertSame(10, $data['leaveBalances']->perPage());
        $this->assertSame(10, $data['usageRows']->perPage());
        $this->assertSame(10, $data['ledgerRows']->perPage());
        $this->assertCount(5, $data['rolloverRows']);
    }

    public function test_tautan_laporan_rekap_mempertahankan_filter_kanonis_tanpa_url_legacy(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create(['jabatan_terakhir' => 'Bagian Tautan']);
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Tautan']);
        $filters = [
            'periode' => '2026-06',
            'unit' => 'Bagian Tautan',
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
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Preview']);

        foreach (range(1, 16) as $day) {
            $this->createLeaveRequest($pegawai, $jenis, '2026-06-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT));
        }
        $this->createLeaveRequest($pegawaiLain, $jenis, '2026-06-20');

        $filters = ['periode' => '2026-06', 'unit' => 'Bagian Laporan', 'pegawai' => $pegawai->id, 'jenis' => $jenis->id];
        $expectedIds = (new CutiRekapQuery)->detailRows($filters)->limit(15)->pluck('id')->all();

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

    /** @return array<string, array{string, string|null, string}> */
    public static function officialStatusProvider(): array
    {
        return [
            'disetujui' => ['disetujui', null, 'Disetujui'],
            'ditangguhkan' => ['ditangguhkan', null, 'Ditangguhkan'],
            'perlu perubahan' => ['perlu_perubahan', null, 'Perubahan'],
            'tidak disetujui' => ['tidak_disetujui', null, 'Tidak Disetujui'],
            'tahap aktif' => ['menunggu_approval', 'active', 'Menunggu Verifikator'],
            'fallback approver' => ['menunggu_approval', null, 'Menunggu Approver'],
        ];
    }

    public function test_detail_rows_menerapkan_filter_kanonis_eager_load_dan_urutan_stabil(): void
    {
        $pegawaiCocok = Employee::factory()->create(['jabatan_terakhir' => 'Bagian SDM']);
        $pegawaiLain = Employee::factory()->create(['jabatan_terakhir' => 'Bagian Umum']);
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
            'status' => 'approved',
            'is_final' => false,
        ]);

        $rows = (new CutiRekapQuery)->detailRows([
            'unit' => 'Bagian SDM',
            'pegawai' => $pegawaiCocok->id,
            'jenis' => $jenisCocok->id,
            'periode' => '2026',
        ])->get();

        $sameDateIds = [$tanggalSamaPertama->id, $tanggalSamaKedua->id];
        sort($sameDateIds);
        $this->assertSame([...$sameDateIds, $lama->id], $rows->pluck('id')->all());
        $this->assertTrue($rows->every(fn (LeaveRequest $row): bool => $row->relationLoaded('employee')
            && $row->relationLoaded('jenisCuti') && $row->relationLoaded('steps')));
        $this->assertSame(
            ['id', 'nama_lengkap', 'nip', 'jabatan_terakhir'],
            array_keys($rows->first()->employee->getAttributes()),
        );
        $this->assertSame(['id', 'nama'], array_keys($rows->first()->jenisCuti->getAttributes()));
        $this->assertSame(
            ['id', 'leave_request_id', 'step_order', 'role_label', 'status'],
            array_keys($rows->firstWhere('id', $tanggalSamaKedua->id)->steps->first()->getAttributes()),
        );
    }

    #[DataProvider('recognizedPeriodProvider')]
    public function test_detail_rows_memahami_setiap_format_periode(string $periode): void
    {
        $pegawai = Employee::factory()->create();
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Periode '.$periode]);
        $juni = $this->createLeaveRequest($pegawai, $jenis, '2026-06-15');
        $this->createLeaveRequest($pegawai, $jenis, '2026-07-15');
        $this->createLeaveRequest($pegawai, $jenis, '2025-06-15');

        $ids = (new CutiRekapQuery)->detailRows(['periode' => $periode])->pluck('id')->all();

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

        $ids = (new CutiRekapQuery)->detailRows(['periode' => 'Semester Ganjil'])->pluck('id')->all();

        $this->assertSame([$terbaru->id, $terlama->id], $ids);
    }

    #[DataProvider('recognizedPeriodProvider')]
    public function test_balance_rows_memakai_tahun_dari_semua_format_periode_dan_saldo_materialized(
        string $periode,
    ): void {
        $pegawai = Employee::factory()->create(['jabatan_terakhir' => 'Bagian Keuangan']);
        $pegawaiLain = Employee::factory()->create(['jabatan_terakhir' => 'Bagian SDM']);
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

        $rows = (new CutiRekapQuery)->balanceRows([
            'unit' => 'Bagian Keuangan',
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
            ['id', 'nama_lengkap', 'nip', 'jabatan_terakhir'],
            array_keys($rows->first()->employee->getAttributes()),
        );
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

        $query = (new CutiRekapQuery)->balanceRows([]);
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
            ['column' => 'tahun', 'direction' => 'desc'],
            ['column' => 'employee_id', 'direction' => 'asc'],
            ['column' => 'id', 'direction' => 'asc'],
        ], $query->getQuery()->orders);
    }

    #[DataProvider('invalidDirectQueryUuidProvider')]
    public function test_query_menolak_uuid_tidak_aman_dari_pemanggil_langsung(
        string $method,
        string $field,
        mixed $value,
    ): void {
        $this->expectException(NotFoundHttpException::class);

        (new CutiRekapQuery)->{$method}([$field => $value]);
    }

    /** @return array<string, array{string, string, mixed}> */
    public static function invalidDirectQueryUuidProvider(): array
    {
        return [
            'detail pegawai malformed' => ['detailRows', 'pegawai', 'bukan-uuid'],
            'detail pegawai array' => ['detailRows', 'pegawai', ['uuid']],
            'detail jenis malformed' => ['detailRows', 'jenis', 'bukan-uuid'],
            'detail jenis array' => ['detailRows', 'jenis', ['uuid']],
            'balance pegawai malformed' => ['balanceRows', 'pegawai', 'bukan-uuid'],
            'balance pegawai array' => ['balanceRows', 'pegawai', ['uuid']],
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
}
