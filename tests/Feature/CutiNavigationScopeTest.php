<?php

namespace Tests\Feature;

use App\Actions\Cuti\ListKepalaBagianLeavesAction;
use App\Actions\Cuti\ListPimpinanLeavesAction;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\PositionHistory;
use App\Models\RefJenisCuti;
use App\Models\RefUnitKerja;
use App\Models\Role;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Menjaga navigasi tunggal tanpa mencampur scope monitoring dengan assignment keputusan. */
class CutiNavigationScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_monitoring_kepala_bagian_membatasi_baris_counter_dan_opsi_unit(): void
    {
        $actor = $this->actor('kepala_bagian');
        $this->monitoringPermission('kepala_bagian', true);
        $report = Employee::factory()->create(['kepala_bagian_id' => $actor->employee_id]);
        $insideUnit = $this->assignUnit($report, 'Unit Dalam Scope');
        $outside = Employee::factory()->create();
        $this->assignUnit($outside, 'Unit Rahasia Luar Scope');
        $insideLeave = $this->leave($report);
        $this->leave($outside);

        $response = $this->actingAs($actor)->get(route('cuti'))->assertOk();

        $this->assertSame([$insideLeave->id], $response->viewData('riwayatCuti')->pluck('id')->all());
        $response->assertViewHas('totalPengajuan', 1)->assertViewHas('jumlahMenunggu', 1);
        $this->assertSame([$insideUnit->id => 'Unit Dalam Scope'], $response->viewData('optUnits')->all());
        $response->assertDontSee('Unit Rahasia Luar Scope');
    }

    public function test_grant_monitoring_pegawai_tetap_self_termasuk_opsi_unit(): void
    {
        $actor = $this->actor('pegawai');
        $this->assignUnit($actor->employee, 'Unit Pribadi');
        $this->monitoringPermission('pegawai', true);
        $ownLeave = $this->leave($actor->employee);
        $outside = Employee::factory()->create();
        $this->assignUnit($outside, 'Unit Tidak Berhak');
        $this->leave($outside);

        $response = $this->actingAs($actor)->get(route('cuti'))->assertOk();

        $this->assertSame([$ownLeave->id], $response->viewData('riwayatCuti')->pluck('id')->all());
        $response->assertViewHas('totalPengajuan', 1)->assertDontSee('Unit Tidak Berhak');
    }

    public function test_switch_role_tidak_mengganti_scope_identitas_monitoring(): void
    {
        $actor = $this->actor('super_admin');
        $actor->update(['temporary_role' => 'pegawai', 'temporary_role_started_at' => now()]);
        $this->monitoringPermission('pegawai', true);
        $outsideLeave = $this->leave(Employee::factory()->create());

        $response = $this->actingAs($actor)->get(route('cuti'))->assertOk();

        $this->assertSame([$outsideLeave->id], $response->viewData('riwayatCuti')->pluck('id')->all());
        $response->assertViewHas('totalPengajuan', 1);
        $this->assertSame('super_admin', $actor->fresh()->role);
    }

    public function test_scope_own_mempertahankan_daftar_pemohon_meski_monitoring_diberikan(): void
    {
        $actor = $this->actor('kepala_bagian');
        $this->monitoringPermission('kepala_bagian', true);
        $ownLeave = $this->leave($actor->employee);
        $this->leave(Employee::factory()->create(['kepala_bagian_id' => $actor->employee_id]));

        $response = $this->actingAs($actor)->get(route('cuti', ['scope' => 'own']))->assertOk();

        $this->assertSame([$ownLeave->id], $response->viewData('riwayatCuti')->pluck('id')->all());
        $response->assertViewHas('isPegawai', true)->assertViewHas('totalPengajuan', 1);
        $response->assertSee('href="'.route('cuti', ['scope' => 'own']).'"', false);
    }

    public function test_scope_palsu_tidak_memperluas_daftar_tanpa_permission_monitoring(): void
    {
        $actor = $this->actor('kepala_bagian');
        $this->monitoringPermission('kepala_bagian', false);
        $ownLeave = $this->leave($actor->employee);
        $this->leave(Employee::factory()->create(['kepala_bagian_id' => $actor->employee_id]));

        $response = $this->actingAs($actor)->get(route('cuti', ['scope' => 'all']))->assertOk();

        $this->assertSame([$ownLeave->id], $response->viewData('riwayatCuti')->pluck('id')->all());
        $response->assertViewHas('isPegawai', true)->assertViewHas('totalPengajuan', 1);
    }

    public function test_monitoring_pimpinan_default_semua_dan_filter_assignment_tetap_eksplisit(): void
    {
        $actor = $this->actor('pimpinan');
        $mine = $this->leave(Employee::factory()->create(), $actor->employee);
        $other = $this->leave(Employee::factory()->create(), Employee::factory()->create());

        $all = $this->actingAs($actor)->get(route('pimpinan.cuti.index'))->assertOk();
        $all->assertViewHas('filters', fn (array $filters): bool => $filters['status'] === 'all');
        $this->assertEqualsCanonicalizing([$mine->id, $other->id], $all->viewData('leaves')->pluck('id')->all());

        $assigned = $this->get(route('pimpinan.cuti.index', ['status' => 'menunggu_saya']))->assertOk();
        $this->assertSame([$mine->id], $assigned->viewData('leaves')->pluck('id')->all());
        $all->assertSee('Monitoring Cuti');
    }

    public function test_monitoring_pimpinan_memakai_scope_sebelum_counter_dan_membatasi_pagination(): void
    {
        $actor = $this->actor('kepala_bagian');
        $this->monitoringPermission('kepala_bagian', true);
        $report = Employee::factory()->create(['kepala_bagian_id' => $actor->employee_id]);
        $mine = $this->leave($report, $actor->employee);
        $this->leave(Employee::factory()->create(), $actor->employee);

        $result = app(ListPimpinanLeavesAction::class)->execute($actor, ['status' => 'all', 'per_page' => 25]);

        $this->assertSame([$mine->id], $result['leaves']->pluck('id')->all());
        $this->assertSame(1, $result['totalMenunggu']);
        $this->assertSame(1, $result['menungguTindakanSaya']);
        $this->assertSame(25, $result['leaves']->perPage());

        $result = app(ListPimpinanLeavesAction::class)->execute($actor, ['per_page' => 100000]);
        $this->assertSame(10, $result['leaves']->perPage());
    }

    public function test_monitoring_pimpinan_menolak_ukuran_halaman_tidak_terbatas(): void
    {
        $actor = $this->actor('pimpinan');

        $this->actingAs($actor)->getJson(route('pimpinan.cuti.index', ['per_page' => 100000]))
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_filter_menunggu_bawahan_tidak_dibatasi_approver_aktif_dan_mengecualikan_assignment_masa_depan(): void
    {
        $actor = $this->actor('kepala_bagian');
        $this->monitoringPermission('kepala_bagian', true);
        $report = Employee::factory()->create(['kepala_bagian_id' => $actor->employee_id]);
        $otherApprover = Employee::factory()->create();
        $visible = $this->leave($report, $otherApprover);
        $future = Employee::factory()->create(['kepala_bagian_id' => $actor->employee_id]);
        SupervisorAssignment::create([
            'employee_id' => $future->id,
            'kepala_bagian_id' => $actor->employee_id,
            'tanggal_mulai' => today()->addMonth()->toDateString(),
        ]);
        $this->leave($future, $actor->employee);
        $this->leave(Employee::factory()->create(), $actor->employee);

        $response = $this->actingAs($actor)->get(route('kepala-bagian.cuti.index', ['status' => 'menunggu_approval']))->assertOk();

        $this->assertSame([$visible->id], $response->viewData('leaves')->pluck('id')->all());
        $response->assertSee(route('cuti.show', ['id' => $visible->id, 'from' => 'bawahan']), false);
    }

    public function test_daftar_bawahan_mengiriskan_grant_pegawai_dengan_scope_identitas_asli(): void
    {
        $actor = $this->actor('pegawai');
        $this->monitoringPermission('pegawai', true);
        $report = Employee::factory()->create(['kepala_bagian_id' => $actor->employee_id]);
        $this->leave($report, $actor->employee);

        $leaves = app(ListKepalaBagianLeavesAction::class)->execute($actor, ['status' => 'menunggu_approval']);

        $this->assertSame(0, $leaves->total(), 'Grant Pegawai tetap self meski identitasnya pernah tercatat sebagai atasan.');
    }

    #[DataProvider('monitoringRoutes')]
    public function test_role_monitoring_tanpa_permission_tetap_bisa_antrean_tetapi_tidak_daftar_role(string $role, string $route): void
    {
        $actor = $this->actor($role);
        $this->monitoringPermission($role, false);
        $ownLeave = $this->leave($actor->employee);

        $this->actingAs($actor)->get(route($route))->assertForbidden();
        $queue = $this->get(route('cuti.approval'))->assertOk();
        $queue->assertDontSee('href="'.route($route).'"', false);
        $own = $this->get(route('cuti'))->assertOk();
        $this->assertSame([$ownLeave->id], $own->viewData('riwayatCuti')->pluck('id')->all());
    }

    public static function monitoringRoutes(): array
    {
        return [
            'Pimpinan' => ['pimpinan', 'pimpinan.cuti.index'],
            'Kepala Bagian' => ['kepala_bagian', 'kepala-bagian.cuti.index'],
        ];
    }

    #[DataProvider('dashboardQueueLinks')]
    public function test_tautan_antrean_dashboard_tetap_menuju_assignment_tanpa_izin_monitoring(string $role, string $dashboardRoute, string $label): void
    {
        $actor = $this->actor($role);
        $this->monitoringPermission($role, false);
        $assigned = $this->leave(Employee::factory()->create(), $actor->employee);
        $this->leave(Employee::factory()->create(), Employee::factory()->create());

        $response = $this->actingAs($actor)->get(route($dashboardRoute))->assertOk();
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $this->assertTrue($dom->loadHTML($response->getContent()));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $links = (new \DOMXPath($dom))->query('//main//a[contains(normalize-space(.), "'.$label.'")]');
        $this->assertCount(1, $links);
        $link = $links->item(0);
        $this->assertInstanceOf(\DOMElement::class, $link);
        $this->assertSame(route('cuti.approval'), $link->getAttribute('href'));

        $queue = $this->get($link->getAttribute('href'))->assertOk();
        $this->assertSame([$assigned->id], $queue->viewData('pending')->pluck('id')->all());
    }

    public static function dashboardQueueLinks(): array
    {
        return [
            'kartu Atasan' => ['kepala_bagian', 'kepala-bagian.dashboard', 'Cuti Menunggu Tindakan'],
            'tautan Atasan' => ['kepala_bagian', 'kepala-bagian.dashboard', 'Lihat Antrean'],
            'tautan Pimpinan' => ['pimpinan', 'pimpinan.dashboard', 'Buka antrean persetujuan cuti'],
        ];
    }

    public function test_pegawai_assigned_memiliki_satu_menu_antrean_aktif_dan_link_detail_kontekstual(): void
    {
        $actor = $this->actor('pegawai');
        $mine = $this->leave(Employee::factory()->create(), $actor->employee);
        $this->leave(Employee::factory()->create(), Employee::factory()->create());
        $this->leave(Employee::factory()->create(), $actor->employee, LeaveRequest::STATUS_CANCELLATION_PENDING);

        $response = $this->actingAs($actor)->get(route('cuti.approval'))->assertOk();
        $this->assertSame([$mine->id], $response->viewData('pending')->pluck('id')->all());
        $response->assertSee('Menunggu Tindakan Saya')
            ->assertSee(route('cuti.show', ['id' => $mine->id, 'from' => 'approval']), false);

        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $this->assertTrue($dom->loadHTML($response->getContent()));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($dom);
        $links = $xpath->query('//nav[@id="sidebar-nav"]//a[contains(@class,"bg-primary")]');
        $this->assertCount(1, $links);
        $link = $links->item(0);
        $this->assertInstanceOf(\DOMElement::class, $link);
        $this->assertSame(route('cuti.approval'), $link->getAttribute('href'));
    }

    #[DataProvider('sidebarIconRoles')]
    public function test_ikon_menu_cuti_membedakan_tujuan_tanpa_mengubah_tautan(string $role, string $monitoringLabel, string $monitoringRoute, string $monitoringPath): void
    {
        $this->monitoringPermission($role, true);
        Role::query()->where('name', $role)->sole()->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'cuti.cancellation.manage')->sole()->id,
        ]);
        $response = $this->actingAs($this->actor($role))->get(route('cuti.approval'))->assertOk();
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $this->assertTrue($dom->loadHTML($response->getContent()));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($dom);
        $menus = [
            ['Menunggu Tindakan Saya', route('cuti.approval'), 'M11.35 3.836'],
            [$monitoringLabel, route($monitoringRoute), $monitoringPath],
            ['Pengajuan Cuti Saya', route('cuti', ['scope' => 'own']), 'M6.75 3v2.25'],
            ['Permohonan Pembatalan Cuti', route('cuti.cancellations.index'), 'M9 15 3 9'],
        ];
        foreach ($menus as [$label, $url, $pathPrefix]) {
            $links = $xpath->query('//nav[@id="sidebar-nav"]//a[.//span[@data-sidebar-label-text and normalize-space()="'.$label.'"]]');
            $this->assertCount(1, $links, $label);
            $link = $links->item(0);
            $this->assertInstanceOf(\DOMElement::class, $link);
            $this->assertSame($url, $link->getAttribute('href'));
            $this->assertCount(1, $xpath->query('.//svg/path[starts-with(@d,"'.$pathPrefix.'")]', $link), $label);
            $this->assertSame($label === 'Menunggu Tindakan Saya' ? 'page' : '', $link->getAttribute('aria-current'));
        }
    }

    public static function sidebarIconRoles(): array
    {
        return [
            'menu umum admin' => ['admin_kepegawaian', 'Monitoring Cuti', 'cuti', 'M2.036 12.322'],
            'menu pimpinan' => ['pimpinan', 'Monitoring Cuti', 'pimpinan.cuti.index', 'M2.036 12.322'],
            'menu atasan' => ['kepala_bagian', 'Cuti Bawahan', 'kepala-bagian.cuti.index', 'M15 19.128'],
        ];
    }

    #[DataProvider('returnContexts')]
    public function test_detail_mempertahankan_filter_dan_halaman_asal(?string $from, string $route, array $filters): void
    {
        $actor = $this->actor('pimpinan');
        $leave = $this->leave($actor->employee);
        $expected = route($route, $from === null ? ['scope' => 'own', ...$filters] : $filters);

        $response = $this->actingAs($actor)->get(route('cuti.show', [
            'id' => $leave->id, 'from' => $from, 'return' => $filters,
        ]))->assertOk();

        $this->assertSame($expected, $response->viewData('backLink')['url']);
        $response->assertSee('href="'.e($expected).'"', false);
    }

    public static function returnContexts(): array
    {
        return [
            'pengajuan sendiri' => [null, 'cuti', ['status' => 'menunggu', 'periode' => '2026', 'per_page' => '25', 'page' => '2']],
            'monitoring' => ['monitoring', 'cuti', ['search' => 'Nama Pegawai', 'status' => 'menunggu', 'periode' => '2026-09', 'per_page' => '25', 'page' => '2']],
            'antrean' => ['approval', 'cuti.approval', ['per_page' => '25', 'page' => '2']],
            'pimpinan' => ['pimpinan', 'pimpinan.cuti.index', ['search' => 'Nama Pegawai', 'status' => 'menunggu_saya', 'periode' => '2026-09', 'per_page' => '50', 'page' => '3']],
            'bawahan' => ['bawahan', 'kepala-bagian.cuti.index', ['search' => 'Nama Pegawai', 'status' => 'all', 'tahun' => '2026', 'bulan' => '9', 'per_page' => '10', 'page' => '2']],
        ];
    }

    #[DataProvider('listReturnContexts')]
    public function test_tautan_daftar_membawa_filter_dan_halaman_ke_detail(string $role, string $route, ?string $from, array $filters): void
    {
        $actor = $this->actor($role);
        $this->monitoringPermission($role, true);
        $employee = $from === null ? $actor->employee : Employee::factory()->create(['kepala_bagian_id' => $actor->employee_id]);
        $leave = $this->leave($employee, $actor->employee);
        $expected = route('cuti.show', ['id' => $leave->id, 'from' => $from, 'return' => $filters]);

        $this->actingAs($actor)->get(route($route, $from === null ? ['scope' => 'own', ...$filters] : $filters))
            ->assertOk()->assertSee('href="'.e($expected).'"', false);
    }

    public static function listReturnContexts(): array
    {
        return [
            'pengajuan sendiri' => ['pimpinan', 'cuti', null, ['status' => 'menunggu', 'periode' => '2026', 'per_page' => '25', 'page' => '1']],
            'monitoring' => ['pimpinan', 'cuti', 'monitoring', ['status' => 'menunggu', 'periode' => '2026-09', 'per_page' => '25', 'page' => '1']],
            'antrean' => ['pegawai', 'cuti.approval', 'approval', ['per_page' => '25', 'page' => '1']],
            'pimpinan' => ['pimpinan', 'pimpinan.cuti.index', 'pimpinan', ['status' => 'all', 'periode' => '2026-09', 'per_page' => '25', 'page' => '1']],
            'bawahan' => ['kepala_bagian', 'kepala-bagian.cuti.index', 'bawahan', ['status' => 'all', 'tahun' => '2026', 'bulan' => '9', 'per_page' => '25', 'page' => '1']],
        ];
    }

    public function test_filter_status_kosong_bawahan_tetap_semua_status_setelah_kembali(): void
    {
        $actor = $this->actor('kepala_bagian');
        $this->monitoringPermission('kepala_bagian', true);
        $report = Employee::factory()->create(['kepala_bagian_id' => $actor->employee_id]);
        $leave = $this->leave($report, status: 'disetujui');
        $detailUrl = route('cuti.show', ['id' => $leave->id, 'from' => 'bawahan', 'return' => ['status' => 'all']]);

        $list = $this->actingAs($actor)->get(route('kepala-bagian.cuti.index', ['status' => '']))->assertOk();
        $this->assertSame([$leave->id], $list->viewData('leaves')->pluck('id')->all());
        $list->assertSee('href="'.e($detailUrl).'"', false);

        $detail = $this->get($detailUrl)->assertOk();
        $this->assertSame(route('kepala-bagian.cuti.index', ['status' => 'all']), $detail->viewData('backLink')['url']);
        $returned = $this->get($detail->viewData('backLink')['url'])->assertOk();
        $this->assertSame([$leave->id], $returned->viewData('leaves')->pluck('id')->all());
    }

    public function test_konteks_kembali_membuang_filter_tidak_sah_dan_tidak_memperluas_scope(): void
    {
        $actor = $this->actor('pegawai');
        $this->monitoringPermission('pegawai', false);
        $leave = $this->leave($actor->employee);

        $response = $this->actingAs($actor)->get(route('cuti.show', [
            'id' => $leave->id, 'return' => [
                'scope' => 'all', 'url' => 'https://example.invalid', 'jenis' => ['tidak sah'],
                'status' => 'menunggu_saya', 'periode' => '2026-13', 'per_page' => '100000', 'page' => '-1',
                'tahun' => '2026',
            ],
        ]))->assertOk();
        $this->assertSame(route('cuti', ['scope' => 'own', 'tahun' => '2026']), $response->viewData('backLink')['url']);

        foreach (['pimpinan', 'monitoring', 'bawahan', 'https://example.invalid'] as $from) {
            $fallback = $this->get(route('cuti.show', [
                'id' => $leave->id, 'from' => $from, 'return' => ['page' => '2', 'status' => 'disetujui'],
            ]))->assertOk();
            $this->assertSame(route('cuti', ['scope' => 'own']), $fallback->viewData('backLink')['url']);
        }

        $invalidEnvelope = $this->get(route('cuti.show', [
            'id' => $leave->id, 'return' => 'https://example.invalid',
        ]))->assertOk();
        $this->assertSame(route('cuti', ['scope' => 'own']), $invalidEnvelope->viewData('backLink')['url']);
    }

    /** Setiap fixture aktor mempunyai binding nyata agar test berfokus pada scope, bukan bypass auth. */
    private function actor(string $role): User
    {
        return User::factory()->create(['role' => $role, 'employee_id' => Employee::factory()->create()->id]);
    }

    /** Unit kerja berasal dari jabatan terbaru, terpisah dari nama jabatan snapshot pegawai. */
    private function assignUnit(Employee $employee, string $name): RefUnitKerja
    {
        $unit = RefUnitKerja::create(['nama' => $name, 'level' => 0, 'jenis_unit' => 'bagian', 'is_active' => true]);
        PositionHistory::create([
            'employee_id' => $employee->id, 'nama_jabatan' => 'Jabatan Uji Navigasi',
            'unit_kerja_id' => $unit->id, 'tmt_jabatan' => '2026-01-01', 'is_latest' => true,
        ]);

        return $unit;
    }

    /** Matrix diubah hanya dalam transaksi test untuk membuktikan grant/revoke efektif. */
    private function monitoringPermission(string $roleName, bool $enabled): void
    {
        $role = Role::query()->where('name', $roleName)->sole();
        $permission = Permission::query()->where('name', 'cuti.read_all')->sole();
        if ($enabled) {
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        } else {
            $role->permissions()->detach($permission->id);
        }
    }

    /** Snapshot sederhana membuktikan perbedaan pembaca monitoring dan pemilik tahap aktif. */
    private function leave(Employee $employee, ?Employee $approver = null, string $status = 'menunggu_approval'): LeaveRequest
    {
        $type = RefJenisCuti::firstOrCreate(['code' => 'sakit_navigation'], [
            'nama' => 'Cuti Sakit Navigasi', 'mengurangi_saldo_tahunan' => false, 'khusus_pns' => false,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $type->id,
            'tanggal_mulai' => '2026-09-21',
            'tanggal_selesai' => '2026-09-22',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Kebutuhan pengujian navigasi.',
            'status' => $status,
        ]);
        if ($approver !== null) {
            $leave->steps()->create([
                'step_order' => 1, 'step_type' => 'kepala_bagian', 'role_label' => 'Atasan Langsung',
                'approver_employee_id' => $approver->id, 'status' => 'active', 'is_final' => true,
            ]);
        }

        return $leave;
    }
}
