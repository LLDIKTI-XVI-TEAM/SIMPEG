<?php

namespace Tests\Feature;

use App\Actions\Dashboards\BuildAdminDashboardAction;
use App\Actions\Dashboards\BuildKepalaBagianDashboardAction;
use App\Actions\Dashboards\BuildPegawaiDashboardAction;
use App\Actions\Dashboards\BuildPimpinanDashboardAction;
use App\Actions\Profiles\ShowProfilePageAction;
use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EwsQueryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceSeeder::class, RbacSeeder::class]);
    }

    /** Menangkap pemuatan penuh atau filter/sort sesudah pagination di PHP. */
    public function test_daftar_admin_memakai_pagination_pencarian_dan_sort_database(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $this->createAlerts(5);
        [, $smallQueries] = $this->measureQueries(
            fn () => $this->actingAs($admin)->get(route('ews', ['per_page' => 25])),
        );
        $this->createAlerts(100, 5);
        $needle = $this->createAlert('Pegawai Needle Database', '199901012026011999', 7);

        [$response, $largeQueries] = $this->measureQueries(
            fn () => $this->actingAs($admin)->get(route('ews', ['per_page' => 25])),
        );
        $response->assertOk();
        $alerts = $response->viewData('alerts');
        $this->assertInstanceOf(LengthAwarePaginator::class, $alerts);
        $this->assertSame(106, $alerts->total());
        $this->assertSame(25, $alerts->perPage());
        $this->assertCount(25, $alerts->items());
        $this->assertSame(
            collect($alerts->items())->pluck('tanggal_target')->sort()->values()->all(),
            collect($alerts->items())->pluck('tanggal_target')->values()->all(),
        );
        $this->assertLessThanOrEqual($smallQueries + 3, $largeQueries);

        $filtered = $this->actingAs($admin)->get(route('ews', [
            'search' => 'Needle Database',
            'per_page' => 25,
        ]));
        $filtered->assertOk();
        $filteredAlerts = $filtered->viewData('alerts');
        $this->assertInstanceOf(LengthAwarePaginator::class, $filteredAlerts);
        $this->assertSame(1, $filteredAlerts->total());
        $this->assertSame($needle->id, $filteredAlerts->items()[0]['alert_id']);
    }

    /** Menangkap pagination pimpinan yang masih memotong array penuh. */
    public function test_daftar_pimpinan_bounded_dan_query_tidak_tumbuh_linear(): void
    {
        $pimpinan = User::factory()->pimpinan()->create();
        $this->createAlerts(5);

        [$smallResponse, $smallQueries] = $this->measureQueries(
            fn () => $this->actingAs($pimpinan)->get(route('pimpinan.ews.index', ['per_page' => 25])),
        );
        $smallResponse->assertOk();

        $this->createAlerts(100, 5);
        [$largeResponse, $largeQueries] = $this->measureQueries(
            fn () => $this->actingAs($pimpinan)->get(route('pimpinan.ews.index', ['per_page' => 25])),
        );
        $largeResponse->assertOk();
        $alerts = $largeResponse->viewData('alerts');
        $this->assertInstanceOf(LengthAwarePaginator::class, $alerts);
        $this->assertSame(105, $alerts->total());
        $this->assertSame(25, $alerts->perPage());
        $this->assertCount(25, $alerts->items());
        $this->assertLessThanOrEqual(
            $smallQueries + 3,
            $largeQueries,
            "Query daftar Pimpinan tumbuh dari {$smallQueries} menjadi {$largeQueries}.",
        );
    }

    /** Menangkap load seluruh Employee/EWS dan preview dashboard di atas lima row. */
    public function test_dashboard_admin_dan_pimpinan_memakai_agregat_dan_preview_bounded(): void
    {
        $pimpinan = User::factory()->pimpinan()->create();
        $this->createAlerts(5);
        [, $smallAdminQueries] = $this->measureQueries(fn () => app(BuildAdminDashboardAction::class)->execute());
        [, $smallPimpinanQueries] = $this->measureQueries(
            fn () => app(BuildPimpinanDashboardAction::class)->execute($pimpinan),
        );

        $this->createAlerts(100, 5);
        [$admin, $largeAdminQueries] = $this->measureQueries(fn () => app(BuildAdminDashboardAction::class)->execute());
        [$pimpinanData, $largePimpinanQueries] = $this->measureQueries(
            fn () => app(BuildPimpinanDashboardAction::class)->execute($pimpinan),
        );

        $this->assertSame(105, $admin['totalPegawaiAktif']);
        $this->assertSame(105, $admin['dashboardEwsTotal']);
        $this->assertSame(105, $admin['totalEwsAktif']);
        $this->assertCount(5, $admin['dashboardEwsAlerts']);
        $this->assertCount(5, $admin['ewsAktif']);
        $this->assertSame(35, $admin['dashboardEwsUrgent']);
        $this->assertSame(35, $admin['dashboardEwsWarning']);
        $this->assertSame(35, $admin['dashboardEwsInfo']);
        $this->assertSame(105, $pimpinanData['totalPegawai']);
        $this->assertSame(105, $pimpinanData['totalEwsAktif']);
        $this->assertCount(5, $pimpinanData['ewsAktif']);
        $this->assertLessThanOrEqual($smallAdminQueries + 3, $largeAdminQueries);
        $this->assertLessThanOrEqual($smallPimpinanQueries + 3, $largePimpinanQueries);
    }

    /** Caller pegawai tidak boleh menurunkan total global dari preview 25 row. */
    public function test_surface_pegawai_memakai_total_global_preview_dan_pagination_bounded(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Banyak EWS']);
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $this->createEmployeeAlerts($employee, 5);

        [, $smallDashboardQueries] = $this->measureQueries(
            fn () => app(BuildPegawaiDashboardAction::class)->execute($user),
        );
        [, $smallProfileQueries] = $this->measureQueries(
            fn () => app(ShowProfilePageAction::class)->execute($user),
        );
        [, $smallPageQueries] = $this->measureQueries(
            fn () => $this->actingAs($user)->get(route('ews.saya')),
        );

        $this->createEmployeeAlerts($employee, 25, 5);

        [$dashboard, $largeDashboardQueries] = $this->measureQueries(
            fn () => app(BuildPegawaiDashboardAction::class)->execute($user),
        );
        [$profile, $largeProfileQueries] = $this->measureQueries(
            fn () => app(ShowProfilePageAction::class)->execute($user),
        );
        [$page, $largePageQueries] = $this->measureQueries(
            fn () => $this->actingAs($user)->get(route('ews.saya')),
        );

        $this->assertSame(30, $dashboard['dashboardEwsTotal']);
        $this->assertSame(10, $dashboard['dashboardEwsUrgent']);
        $this->assertSame(10, $dashboard['dashboardEwsWarning']);
        $this->assertSame(10, $dashboard['dashboardEwsInfo']);
        $this->assertCount(5, $dashboard['dashboardEwsAlerts']);
        $this->assertSame(30, $profile['ewsTotal']);
        $this->assertSame(['urgent' => 10, 'warning' => 10, 'info' => 10], $profile['ewsBuckets']);
        $this->assertCount(3, $profile['ewsAlerts']);

        $page->assertOk();
        $alerts = $page->viewData('alerts');
        $this->assertInstanceOf(LengthAwarePaginator::class, $alerts);
        $this->assertSame(30, $alerts->total());
        $this->assertSame(25, $alerts->perPage());
        $this->assertCount(25, $alerts->items());
        $this->assertLessThanOrEqual($smallDashboardQueries + 3, $largeDashboardQueries);
        $this->assertLessThanOrEqual($smallProfileQueries + 3, $largeProfileQueries);
        $this->assertLessThanOrEqual($smallPageQueries + 3, $largePageQueries);
    }

    /** Scope bawahan harus diterapkan sebelum pagination, summary, dan preview dashboard. */
    public function test_surface_kepala_bagian_memakai_scope_pagination_dan_preview_bounded(): void
    {
        $kepalaBagian = Employee::factory()->create();
        $user = User::factory()->kepalaBagian()->create(['employee_id' => $kepalaBagian->id]);
        $this->createDirectReportAlerts($kepalaBagian, 5);

        [, $smallPageQueries] = $this->measureQueries(
            fn () => $this->actingAs($user)->get(route('kepala-bagian.ews.index')),
        );
        [, $smallDashboardQueries] = $this->measureQueries(
            fn () => app(BuildKepalaBagianDashboardAction::class)->execute($user),
        );
        [, $smallSearchQueries] = $this->measureQueries(
            fn () => $this->actingAs($user)->get(route('kepala-bagian.ews.index', [
                'search' => 'Bawahan Performa 004',
            ])),
        );

        $this->createDirectReportAlerts($kepalaBagian, 25, 5);
        $outside = Employee::factory()->create(['nama_lengkap' => 'Bawahan Performa 029 Luar Scope']);
        $this->createAlertForEmployee($outside, 15, 999);

        [$page, $largePageQueries] = $this->measureQueries(
            fn () => $this->actingAs($user)->get(route('kepala-bagian.ews.index')),
        );
        [$dashboard, $largeDashboardQueries] = $this->measureQueries(
            fn () => app(BuildKepalaBagianDashboardAction::class)->execute($user),
        );
        [$filteredPage, $largeSearchQueries] = $this->measureQueries(
            fn () => $this->actingAs($user)->get(route('kepala-bagian.ews.index', [
                'search' => 'Bawahan Performa 029',
            ])),
        );

        $page->assertOk();
        $alerts = $page->viewData('alerts');
        $this->assertInstanceOf(LengthAwarePaginator::class, $alerts);
        $this->assertSame(30, $alerts->total());
        $this->assertSame(10, $alerts->perPage());
        $this->assertCount(10, $alerts->items());
        $this->assertSame(['total' => 30, 'urgent' => 10, 'warning' => 10, 'info' => 10], $page->viewData('summary'));
        $this->assertCount(5, $dashboard['ewsBawahan']);
        $this->assertNotContains('Bawahan Performa 029 Luar Scope', collect($dashboard['ewsBawahan'])->pluck('nama')->all());

        $filteredPage->assertOk();
        $filteredAlerts = $filteredPage->viewData('alerts');
        $this->assertInstanceOf(LengthAwarePaginator::class, $filteredAlerts);
        $this->assertSame(1, $filteredAlerts->total());
        $this->assertSame('Bawahan Performa 029', $filteredAlerts->items()[0]['nama']);
        parse_str((string) parse_url($filteredAlerts->url(1), PHP_URL_QUERY), $paginationQuery);
        $this->assertSame('Bawahan Performa 029', $paginationQuery['search'] ?? null);
        $filteredPage
            ->assertDontSee('Bawahan Performa 029 Luar Scope')
            ->assertSee('id="kabag-ews-search"', false)
            ->assertSee('for="kabag-ews-search"', false)
            ->assertSee('name="search"', false)
            ->assertSee('value="Bawahan Performa 029"', false)
            ->assertDontSee('x-model="search"', false)
            ->assertDontSee('x-show="search', false);
        $this->assertLessThanOrEqual($smallPageQueries + 3, $largePageQueries);
        $this->assertLessThanOrEqual($smallDashboardQueries + 3, $largeDashboardQueries);
        $this->assertLessThanOrEqual($smallSearchQueries + 3, $largeSearchQueries);
    }

    /** @return array{0: mixed, 1: int} */
    private function measureQueries(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = $callback();
            $count = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        return [$result, $count];
    }

    private function createAlerts(int $count, int $offset = 0): void
    {
        for ($index = 0; $index < $count; $index++) {
            $ordinal = $offset + $index;
            $days = match ($ordinal % 3) {
                0 => 10, 1 => 60, default => 120
            };
            $this->createAlert(
                sprintf('Pegawai Performa %03d', $ordinal),
                sprintf('199901012026%06d', $ordinal),
                $days,
            );
        }
    }

    private function createAlert(string $name, string $nip, int $days): EwsAlert
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => $name,
            'nip' => $nip,
            'golongan_terakhir' => $days < 30 ? 'III/a' : 'IV/a',
        ]);

        return $this->createAlertForEmployee($employee, $days, $days);
    }

    private function createEmployeeAlerts(Employee $employee, int $count, int $offset = 0): void
    {
        for ($index = 0; $index < $count; $index++) {
            $ordinal = $offset + $index;
            $days = match ($ordinal % 3) {
                0 => 10 + intdiv($ordinal, 3),
                1 => 40 + intdiv($ordinal, 3),
                default => 100 + intdiv($ordinal, 3),
            };
            $this->createAlertForEmployee($employee, $days, $ordinal);
        }
    }

    private function createDirectReportAlerts(Employee $kepalaBagian, int $count, int $offset = 0): void
    {
        for ($index = 0; $index < $count; $index++) {
            $ordinal = $offset + $index;
            $employee = Employee::factory()->create([
                'nama_lengkap' => sprintf('Bawahan Performa %03d', $ordinal),
                'kepala_bagian_id' => $kepalaBagian->id,
            ]);
            $days = match ($ordinal % 3) {
                0 => 10 + intdiv($ordinal, 3),
                1 => 40 + intdiv($ordinal, 3),
                default => 100 + intdiv($ordinal, 3),
            };
            $this->createAlertForEmployee($employee, $days, $ordinal);
        }
    }

    private function createAlertForEmployee(Employee $employee, int $days, int $ordinal): EwsAlert
    {
        return EwsAlert::query()->create([
            'employee_id' => $employee->id,
            'type' => 'KGB',
            'target_date' => now()->addDays($days)->toDateString(),
            'interval_days' => 1000 + $ordinal,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
    }
}
