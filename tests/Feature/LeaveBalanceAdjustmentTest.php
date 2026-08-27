<?php

namespace Tests\Feature;

use App\Actions\Cuti\ShowLeaveBalanceAdminAction;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\Cuti\LeaveUsageReconciliationService;
use App\Services\Cuti\LeaveUsageRecordService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeaveBalanceAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Carbon::setTestNow('2027-02-03 10:00:00');
        RefJenisCuti::query()->create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_rekonsiliasi_aktif_menjadi_status_dan_fakta_authoritative_administrasi_saldo(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Rekonsiliasi Aktif']);
        $actor = User::factory()->adminKepegawaian()->create();
        $set = $this->reconcile($employee, $actor, [2025 => 2, 2026 => 4, 2027 => 5]);

        $data = app(ShowLeaveBalanceAdminAction::class)->execute([
            'pegawai' => $employee->id,
            'status' => 'sudah_terdaftar',
        ]);

        $this->assertSame([$employee->id], $data['employeeRows']->pluck('employee_id')->all());
        $this->assertSame('rekonsiliasi_aktif', $data['employeeRows']->sole()['status_code']);
        $this->assertSame([
            'reconciled' => true,
            'set_id' => $set->id,
            'usage' => ['n2' => 2, 'n1' => 4, 'current' => 5],
            'actor_id' => $actor->id,
            'reconciled_at' => '2027-02-03',
            'note' => 'Rekonsiliasi authoritative untuk administrasi.',
        ], $this->reconciliationSnapshot($data['balanceReconciliation']));
    }

    public function test_projection_dan_marker_rollover_tanpa_set_aktif_tidak_menjadi_fallback_rekonsiliasi(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Tanpa Rekonsiliasi']);
        $balance = LeaveBalance::query()->create($this->balancePayload($employee));
        LeaveBalanceLedger::query()->create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
            'amount' => 0,
            'source_year' => 2026,
            'reason' => 'Marker tidak boleh menggantikan fakta rekonsiliasi.',
            'dedup_key' => "test:no-reconciliation:{$employee->id}",
            'occurred_at' => now(),
        ]);

        $data = app(ShowLeaveBalanceAdminAction::class)->execute([
            'pegawai' => $employee->id,
            'status' => 'perlu_tindakan',
        ]);

        $this->assertSame([$employee->id], $data['employeeRows']->pluck('employee_id')->all());
        $this->assertSame('rekonsiliasi_belum_tercatat', $data['employeeRows']->sole()['status_code']);
        $this->assertSame([
            'reconciled' => false,
            'set_id' => null,
            'usage' => null,
            'actor_id' => null,
            'reconciled_at' => null,
            'note' => null,
        ], $this->reconciliationSnapshot($data['balanceReconciliation']));
    }

    public function test_reader_hanya_memakai_set_aktif_dan_mengabaikan_fakta_superseded(): void
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();
        $old = $this->reconcile($employee, $actor, [2025 => 1, 2026 => 2, 2027 => 3]);
        $active = app(LeaveUsageReconciliationService::class)->replaceAnnualReconciliationSet(
            $old,
            [2025 => 4, 2026 => 5, 2027 => 6],
            now(config('app.timezone')),
            'Snapshot aktif pengganti.',
            'Koreksi total pemakaian.',
            $actor,
        );

        $data = app(ShowLeaveBalanceAdminAction::class)->execute(['pegawai' => $employee->id]);

        $this->assertSame(LeaveUsageReconciliationSet::STATUS_SUPERSEDED, $old->fresh()->status);
        $this->assertSame($active->id, $data['balanceReconciliation']['set_id']);
        $this->assertSame(['n2' => 4, 'n1' => 5, 'current' => 6], $data['balanceReconciliation']['usage']);
    }

    public function test_filter_dan_hitungan_status_berasal_dari_set_rekonsiliasi_aktif_tahun_exact(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $registered = Employee::factory()->create(['nama_lengkap' => 'Ayu Rekonsiliasi']);
        $pending = Employee::factory()->create(['nama_lengkap' => 'Bima Belum']);
        $this->reconcile($registered, $actor, [2025 => 0, 2026 => 0, 2027 => 0]);

        $registeredData = app(ShowLeaveBalanceAdminAction::class)->execute([
            'status' => 'sudah_terdaftar',
            'search' => 'ayu',
        ]);
        $pendingData = app(ShowLeaveBalanceAdminAction::class)->execute([
            'status' => 'perlu_tindakan',
            'search' => 'bima',
        ]);

        $this->assertSame([$registered->id], $registeredData['employeeRows']->pluck('employee_id')->all());
        $this->assertSame(['perlu_tindakan' => 0, 'sudah_terdaftar' => 1, 'semua_pegawai' => 1], $registeredData['statusCounts']);
        $this->assertSame([$pending->id], $pendingData['employeeRows']->pluck('employee_id')->all());
        $this->assertSame(['perlu_tindakan' => 1, 'sudah_terdaftar' => 0, 'semua_pegawai' => 1], $pendingData['statusCounts']);
    }

    /** Periode dan filter admin harus mengikuti tahun bisnis WITA, bukan tahun proses UTC. */
    public function test_periode_admin_memakai_tahun_baru_wita_saat_timezone_aplikasi_utc(): void
    {
        $originalTimezones = $this->freezeUtcApplicationAtNewYearWita();

        try {
            $actor = User::factory()->adminKepegawaian()->create();
            $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Tahun Baru WITA']);
            $this->reconcile($employee, $actor, [2024 => 0, 2025 => 0, 2026 => 0], 2026);

            $data = app(ShowLeaveBalanceAdminAction::class)->execute([
                'pegawai' => $employee->id,
                'status' => 'sudah_terdaftar',
                'periode' => '2025',
                'usage_year' => '2026',
            ]);

            $this->assertSame('2026', $data['periode']);
            $this->assertSame([$employee->id], $data['employeeRows']->pluck('employee_id')->all());
            $this->assertSame(2026, $data['selectedBalance']?->tahun);
            $this->assertSame(2026, $data['usageFilters']['usage_year']);
        } finally {
            $this->restoreTimezones($originalTimezones);
        }
    }

    public function test_antrian_administrasi_tetap_dipaginasi_sepuluh_baris(): void
    {
        Employee::factory()->count(11)->create();

        $data = app(ShowLeaveBalanceAdminAction::class)->execute([
            'status' => 'perlu_tindakan',
        ]);

        $this->assertInstanceOf(LengthAwarePaginator::class, $data['employeeRows']);
        $this->assertSame(11, $data['employeeRows']->total());
        $this->assertSame(10, $data['employeeRows']->count());
        $this->assertSame('page_pegawai', $data['employeeRows']->getPageName());
    }

    public function test_query_halaman_administrasi_tetap_bounded_saat_populasi_bertambah(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nama_lengkap' => 'Pegawai Bounded Awal']);
        $url = route('cuti.saldo.administrasi', ['status' => 'semua_pegawai']);
        $this->actingAs($actor)->get($url)->assertOk();

        $smallPopulationQueries = $this->pageQueryCount($url);
        Employee::factory()->count(30)->create();
        $largePopulationQueries = $this->pageQueryCount($url);

        $this->assertSame(
            $smallPopulationQueries,
            $largePopulationQueries,
            'Jumlah query halaman administrasi tidak boleh tumbuh mengikuti jumlah pegawai.',
        );
        $this->assertLessThanOrEqual(20, $largePopulationQueries);
    }

    public function test_workspace_mempertahankan_context_kembali_dan_pagination_antrian(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $selected = Employee::factory()->create(['nama_lengkap' => 'Konteks Pegawai Terpilih']);
        Employee::factory()->count(20)->sequence(
            fn ($sequence): array => ['nama_lengkap' => sprintf('Konteks Pegawai %02d', $sequence->index + 1)],
        )->create();
        $this->reconcile($selected, $actor, [2025 => 1, 2026 => 2, 2027 => 3]);

        $response = $this->actingAs($actor)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $selected->id,
            'status' => 'semua_pegawai',
            'search' => 'Konteks Pegawai',
            'tab' => 'riwayat',
            'page_pegawai' => 2,
            'page_ledger' => 2,
        ]));

        $response->assertOk();
        $employeeRows = $response->viewData('employeeRows');
        $this->assertInstanceOf(LengthAwarePaginator::class, $employeeRows);
        $this->assertSame(2, $employeeRows->currentPage());
        $this->assertSame('page_pegawai', $employeeRows->getPageName());
        $response->assertSee(route('cuti.saldo.administrasi', [
            'status' => 'semua_pegawai',
            'search' => 'Konteks Pegawai',
            'page_pegawai' => 2,
        ]));
        $response->assertSee('name="status" value="semua_pegawai"', false);
        $response->assertSee('name="search" value="Konteks Pegawai"', false);
        $response->assertSee('name="tab" value="riwayat"', false);
        $response->assertSee('name="page_pegawai" value="2"', false);
    }

    public function test_navigasi_ledger_dan_ringkasan_rollover_sinkron_dengan_workspace(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Ledger Sinkron']);

        foreach (range(1, 12) as $index) {
            $this->createLedger(
                $employee,
                $actor,
                2027,
                $index <= 6
                    ? LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED
                    : LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED,
                "Ledger periode aktif {$index}.",
                now()->subMinutes($index),
            );
        }
        $this->createLedger(
            $employee,
            $actor,
            2026,
            LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
            'Ledger periode lama tidak boleh tampil.',
            now()->addMinute(),
        );

        $response = $this->actingAs($actor)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'search' => 'Pegawai Ledger',
            'tab' => 'pendaftaran',
            'page_pegawai' => 1,
            'page_ledger' => 2,
        ]));

        $response->assertOk();
        $ledgerRows = $response->viewData('ledgerRows');
        $rolloverRows = $response->viewData('rolloverRows');
        $this->assertInstanceOf(LengthAwarePaginator::class, $ledgerRows);
        $this->assertSame('page_ledger', $ledgerRows->getPageName());
        $this->assertSame(2, $ledgerRows->currentPage());
        $this->assertSame(12, $ledgerRows->total());
        $this->assertSame([2027], $ledgerRows->pluck('tahun')->unique()->values()->all());
        $this->assertCount(5, $rolloverRows);
        $this->assertSame([2027], $rolloverRows->pluck('tahun')->unique()->values()->all());
        $this->assertSame(
            [
                'pegawai' => $employee->id,
                'status' => 'semua_pegawai',
                'search' => 'Pegawai Ledger',
                'tab' => 'pendaftaran',
                'page_pegawai' => '1',
                'page_ledger' => '1',
            ],
            $this->queryParameters($ledgerRows->url(1)),
        );
        $response->assertDontSee('Ledger periode lama tidak boleh tampil.', false);
    }

    public function test_fakta_cuti_besar_aktif_menampilkan_warning_tanpa_mengubah_bucket_historis(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Rule Lima']);
        $actor = User::factory()->adminKepegawaian()->create();
        $this->reconcile($employee, $actor, [2025 => 2, 2026 => 3, 2027 => 4]);
        $sourceBefore = $this->balanceBuckets($employee, 2027);
        $largeLeave = RefJenisCuti::query()->create([
            'nama' => 'Cuti Besar',
            'code' => 'besar',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => true,
        ]);

        $fact = app(LeaveUsageRecordService::class)->recordManual(
            $employee,
            $largeLeave,
            '2027-06-01',
            '2027-06-30',
            20,
            'Keputusan Cuti Besar eksternal yang sudah final.',
            null,
            'FIXTURE/2027/BESAR',
            $this->validManualApprovalStepData(),
            $actor,
            ['original_name' => 'bukti.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1024],
        );

        $response = $this->actingAs($actor)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
        ]));

        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $fact->record_status);
        $this->assertSame(LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL, $fact->source_type);
        $response->assertOk();
        $this->assertTrue($response->viewData('rule5Active'));
        $response->assertSee('Bucket saldo tercatat untuk riwayat administratif', false);
        $response->assertSee('Hak efektif Cuti Tahunan tahun ini adalah 0 karena Cuti Besar telah disetujui.', false);
        $this->assertSame($sourceBefore, $this->balanceBuckets($employee, 2027));
    }

    public function test_halaman_selected_memakai_form_rekonsiliasi_dan_tidak_merender_route_direct_write_legacy(): void
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($actor)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
        ]));

        $response->assertOk();
        $response->assertSee(route('cuti.reconciliation.store', $employee), false);
        $response->assertDontSee('opening-balance', false);
        $response->assertDontSee('cuti.saldo.adjust', false);
    }

    public function test_halaman_set_aktif_memakai_route_koreksi_rekonsiliasi(): void
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();
        $set = $this->reconcile($employee, $actor, [2025 => 1, 2026 => 2, 2027 => 3]);

        $response = $this->actingAs($actor)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'tab' => 'riwayat',
        ]));

        $response->assertOk();
        $response->assertSee(route('cuti.reconciliation.correct', $set), false);
        $response->assertSee('Fakta pemakaian tahun 2025', false);
        $response->assertSee('Perbaiki Data Pemakaian', false);
    }

    /** @param array<int, int> $usage */
    private function reconcile(
        Employee $employee,
        User $actor,
        array $usage,
        int $balanceYear = 2027,
    ): LeaveUsageReconciliationSet {
        Appointment::query()->updateOrCreate(
            ['employee_id' => $employee->id],
            [
                'jenis_pengangkatan' => 'PNS',
                'tmt_pengangkatan' => '2020-01-01',
            ],
        );

        return app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            $balanceYear,
            $usage,
            Carbon::now('Asia/Makassar'),
            'Rekonsiliasi authoritative untuk administrasi.',
            $actor,
        );
    }

    /** @return array{app:string,php:string} */
    private function freezeUtcApplicationAtNewYearWita(): array
    {
        $originalTimezones = [
            'app' => (string) config('app.timezone'),
            'php' => date_default_timezone_get(),
        ];

        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::create(2025, 12, 31, 16, 15, 0, 'UTC'));

        return $originalTimezones;
    }

    /** @param array{app:string,php:string} $timezones */
    private function restoreTimezones(array $timezones): void
    {
        config(['app.timezone' => $timezones['app']]);
        date_default_timezone_set($timezones['php']);
    }

    /** @return array<string, mixed> */
    private function balancePayload(Employee $employee): array
    {
        return [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ];
    }

    private function pageQueryCount(string $url): int
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $this->get($url)->assertOk();

            return count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }

    private function createLedger(
        Employee $employee,
        User $actor,
        int $year,
        string $eventType,
        string $reason,
        Carbon $occurredAt,
    ): LeaveBalanceLedger {
        return LeaveBalanceLedger::query()->create([
            'employee_id' => $employee->id,
            'tahun' => $year,
            'event_type' => $eventType,
            'amount' => 0,
            'source_year' => $year - 1,
            'reason' => $reason,
            'dedup_key' => 'test:admin-ledger:'.str()->uuid(),
            'created_by' => $actor->id,
            'occurred_at' => $occurredAt,
        ]);
    }

    /** @return array<string, string> */
    private function queryParameters(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);

        return $parameters;
    }

    /** @return array<string, int> */
    private function balanceBuckets(Employee $employee, int $year): array
    {
        $balance = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', $year)
            ->sole();

        return collect($balance->only([
            'jatah_awal',
            'carry_over',
            'terpakai',
            'sisa',
            'sisa_n2',
            'sisa_n1',
            'sisa_tahun_berjalan',
            'terpakai_tahun_berjalan',
            'hangus',
        ]))->map(fn (mixed $value): int => (int) $value)->all();
    }

    /** @param array<string, mixed> $status */
    private function reconciliationSnapshot(array $status): array
    {
        return [
            'reconciled' => $status['reconciled'],
            'set_id' => $status['set_id'],
            'usage' => $status['usage'],
            'actor_id' => $status['actor_id'],
            'reconciled_at' => $status['reconciled_at']?->toDateString(),
            'note' => $status['note'],
        ];
    }
}
