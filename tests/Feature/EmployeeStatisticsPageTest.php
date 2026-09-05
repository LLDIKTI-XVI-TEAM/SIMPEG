<?php

namespace Tests\Feature;

use App\Actions\Reports\ShowEmployeeStatisticsPageAction;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use App\Models\Role;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Queries\Reports\EmployeeStatisticsQuery;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeStatisticsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_role_laporan_dapat_membuka_statistik_agregat_tanpa_data_pribadi(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Rahasia Statistik',
            'nip' => '199001012026011001',
        ]);

        $response = $this->actingAs($admin)->get(route('reporting.employee-statistics'));

        $response->assertOk()
            ->assertSee('Statistik Kepegawaian')
            ->assertSee('Golongan')
            ->assertSee('Jenis Jabatan')
            ->assertSee('Jabatan')
            ->assertSee('Unit Kerja')
            ->assertSee('Jenis Pegawai')
            ->assertDontSee($employee->nama_lengkap)
            ->assertDontSee($employee->nip);
    }

    public function test_seluruh_role_dashboard_yang_diizinkan_dapat_membuka_statistik(): void
    {
        $users = [
            User::factory()->superAdmin()->create(),
            User::factory()->adminKepegawaian()->create(),
            User::factory()->pimpinan()->create(),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)
                ->get(route('reporting.employee-statistics'))
                ->assertOk();
        }
    }

    public function test_role_di_luar_laporan_ditolak(): void
    {
        $response = $this->actingAs(User::factory()->pegawai()->create())
            ->get(route('reporting.employee-statistics'));

        $response->assertForbidden();
    }

    public function test_akses_statistik_mengikuti_perubahan_permission_efektif_pada_request_berikutnya(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $role = Role::query()->where('name', 'admin_kepegawaian')->firstOrFail();
        $permission = Permission::query()->where('name', 'employees.read')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('reporting.employee-statistics'))
            ->assertOk();

        $role->permissions()->detach($permission->id);

        $this->get(route('reporting.employee-statistics'))
            ->assertForbidden();
    }

    public function test_permission_statistik_dapat_diberikan_dinamis_melalui_matriks_rbac(): void
    {
        $pegawai = User::factory()->pegawai()->create();
        $role = Role::query()->where('name', 'pegawai')->firstOrFail();
        $permission = Permission::query()->where('name', 'employees.read')->firstOrFail();

        $this->actingAs($pegawai)
            ->get(route('reporting.employee-statistics'))
            ->assertForbidden();

        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $this->get(route('reporting.employee-statistics'))
            ->assertOk()
            ->assertSee('href="'.route('reporting.employee-statistics').'"', false);
    }

    public function test_statistik_mengikuti_scope_dashboard_aktor_di_backend(): void
    {
        $kepalaBagian = Employee::factory()->create();
        $bawahan = Employee::factory()->create([
            'jabatan_terakhir' => 'Jabatan Dalam Scope Statistik',
        ]);
        Employee::factory()->create([
            'jabatan_terakhir' => 'Jabatan Luar Scope Statistik',
        ]);
        SupervisorAssignment::query()->create([
            'employee_id' => $bawahan->id,
            'kepala_bagian_id' => $kepalaBagian->id,
            'tanggal_mulai' => today()->subDay(),
            'tanggal_berakhir' => null,
        ]);
        $actor = User::factory()->kepalaBagian()->create(['employee_id' => $kepalaBagian->id]);
        $role = Role::query()->where('name', 'kepala_bagian')->firstOrFail();
        $permission = Permission::query()->where('name', 'employees.read')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $statistics = app(ShowEmployeeStatisticsPageAction::class)->execute($actor);

        $this->assertSame(1, $statistics['total']);
        $this->assertSame(1, collect($statistics['dimensions']['jabatan'])
            ->firstWhere('label', 'Jabatan Dalam Scope Statistik')['total']);
        $this->assertNull(collect($statistics['dimensions']['jabatan'])
            ->firstWhere('label', 'Jabatan Luar Scope Statistik'));

        $this->actingAs($actor)
            ->get(route('reporting.employee-statistics'))
            ->assertOk()
            ->assertViewHas('total', 1)
            ->assertSee('Jabatan Dalam Scope Statistik')
            ->assertDontSee('Jabatan Luar Scope Statistik');
    }

    public function test_scope_statistik_memakai_role_efektif_tanpa_mengganti_identitas_aktor(): void
    {
        $pegawaiAsli = Employee::factory()->create([
            'jabatan_terakhir' => 'Jabatan Milik Aktor Simulasi',
        ]);
        Employee::factory()->create([
            'jabatan_terakhir' => 'Jabatan Di Luar Scope Simulasi',
        ]);
        $actor = User::factory()->superAdmin()->create([
            'employee_id' => $pegawaiAsli->id,
            'temporary_role' => 'pegawai',
            'temporary_role_started_at' => now(),
        ]);
        $role = Role::query()->where('name', 'pegawai')->firstOrFail();
        $permission = Permission::query()->where('name', 'employees.read')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $statistics = app(ShowEmployeeStatisticsPageAction::class)->execute($actor);

        $this->assertSame(1, $statistics['total']);
        $this->assertSame(1, collect($statistics['dimensions']['jabatan'])
            ->firstWhere('label', 'Jabatan Milik Aktor Simulasi')['total']);
        $this->assertNull(collect($statistics['dimensions']['jabatan'])
            ->firstWhere('label', 'Jabatan Di Luar Scope Simulasi'));

        $this->actingAs($actor)
            ->get(route('reporting.employee-statistics'))
            ->assertOk()
            ->assertViewHas('total', 1)
            ->assertSee('Jabatan Milik Aktor Simulasi')
            ->assertDontSee('Jabatan Di Luar Scope Simulasi');
    }

    public function test_query_mengagregasi_hanya_pegawai_aktif_tanpa_memuat_kolom_pribadi(): void
    {
        $active = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Aktif Statistik',
            'nip' => '199001012026011002',
            'jabatan_terakhir' => 'Analis Statistik',
        ]);
        $nonactiveStatus = RefStatusPegawai::query()->create([
            'kode' => 'NONAKTIF_STATISTIK',
            'nama' => 'Nonaktif Statistik',
            'kelompok' => 'Nonaktif',
            'keterangan' => 'Fixture reporting statistik.',
            'is_default' => false,
        ]);
        Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Nonaktif Statistik',
            'nip' => '199001012026011003',
            'status_pegawai_id' => $nonactiveStatus->id,
            'status_aktif' => 'Nonaktif',
            'jabatan_terakhir' => 'Jabatan Tidak Boleh Masuk',
        ]);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = mb_strtolower($query->sql);
        });

        $statistics = app(EmployeeStatisticsQuery::class)->execute(Employee::query());
        $statisticsSql = implode("\n", $queries);

        $this->assertSame(1, $statistics['total']);
        $this->assertStringContainsString('count(*)', $statisticsSql);
        $this->assertStringContainsString('group by', $statisticsSql);
        $this->assertStringNotContainsString('nama_lengkap', $statisticsSql);
        $this->assertStringNotContainsString('nip', $statisticsSql);
        $this->assertContains(
            ['label' => $active->jabatan_terakhir, 'total' => 1, 'tone' => 'primary'],
            $statistics['dimensions']['jabatan'],
        );
        $this->assertDoesNotMatchRegularExpression('/Pegawai Nonaktif Statistik|199001012026011003/', json_encode($statistics, JSON_THROW_ON_ERROR));
    }

    public function test_query_memilih_snapshot_saat_nama_referensi_hanya_berisi_spasi(): void
    {
        $status = RefStatusPegawai::query()->create([
            'kode' => 'AKTIF_SPASI_STATISTIK',
            'nama' => '   ',
            'kelompok' => 'Aktif',
            'keterangan' => 'Fixture fallback statistik.',
            'is_default' => false,
            'is_active' => true,
        ]);
        $golongan = RefGolongan::query()->create([
            'kode' => '   ',
            'nama' => 'Golongan Referensi Spasi Statistik',
            'urutan' => 401,
            'is_active' => true,
        ]);
        $jabatan = RefJabatan::query()->create([
            'nama' => '   ',
            'default_bup' => 60,
            'is_active' => true,
        ]);
        $employee = Employee::factory()->create([
            'golongan_terakhir' => 'Gol Snapshot',
            'jabatan_terakhir' => 'Jabatan Snapshot',
        ]);
        DB::table('employees')->where('id', $employee->id)->update([
            'status_pegawai_id' => $status->id,
            'status_aktif' => 'Status Snapshot',
        ]);
        RankHistory::query()->create([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => '2025-01-01',
            'no_sk' => 'SK-FALLBACK-GOLONGAN-STATISTIK',
            'tanggal_sk' => '2025-01-01',
            'is_latest' => true,
        ]);
        PositionHistory::query()->create([
            'employee_id' => $employee->id,
            'jabatan_id' => $jabatan->id,
            'nama_jabatan' => 'Jabatan Riwayat Statistik',
            'tmt_jabatan' => '2025-01-01',
            'no_sk' => 'SK-FALLBACK-JABATAN-STATISTIK',
            'tanggal_sk' => '2025-01-01',
            'is_latest' => true,
        ]);

        $dimensions = app(EmployeeStatisticsQuery::class)->execute(Employee::query())['dimensions'];

        $this->assertSame(1, collect($dimensions['golongan'])
            ->firstWhere('label', 'Gol Snapshot')['total']);
        $this->assertSame(1, collect($dimensions['jabatan'])
            ->firstWhere('label', 'Jabatan Riwayat Statistik')['total']);
        $this->assertSame(1, collect($dimensions['status_pegawai'])
            ->firstWhere('label', 'Status Snapshot')['total']);
    }

    public function test_query_membatasi_kategori_dan_mengelompokkan_sisanya_sebagai_lainnya(): void
    {
        $totalEmployees = EmployeeStatisticsQuery::MAX_VISIBLE_CATEGORIES + 2;

        foreach (range(1, $totalEmployees) as $number) {
            Employee::factory()->create([
                'jabatan_terakhir' => sprintf('Jabatan Statistik %02d', $number),
            ]);
        }

        $rows = app(EmployeeStatisticsQuery::class)->execute(Employee::query())['dimensions']['jabatan'];

        $this->assertCount(EmployeeStatisticsQuery::MAX_VISIBLE_CATEGORIES + 1, $rows);
        $this->assertSame($totalEmployees, array_sum(array_column($rows, 'total')));
        $this->assertSame('Lainnya', $rows[array_key_last($rows)]['label']);
        $this->assertSame(2, $rows[array_key_last($rows)]['total']);
    }

    public function test_query_menormalisasi_semua_varian_kategori_lainnya_sebelum_rollup(): void
    {
        Employee::factory()->create([
            'jabatan_terakhir' => 'Lainnya',
        ]);
        Employee::factory()->create([
            'jabatan_terakhir' => 'lainnya',
        ]);
        Employee::factory()->create([
            'jabatan_terakhir' => ' Lainnya ',
        ]);

        foreach (range(1, EmployeeStatisticsQuery::MAX_VISIBLE_CATEGORIES + 1) as $number) {
            Employee::factory()->create([
                'jabatan_terakhir' => sprintf('Z Jabatan Statistik %02d', $number),
            ]);
        }

        $rows = app(EmployeeStatisticsQuery::class)->execute(Employee::query())['dimensions']['jabatan'];
        $otherRows = collect($rows)->filter(
            static fn (array $row): bool => strcasecmp(trim($row['label']), 'Lainnya') === 0,
        )->values();

        $this->assertCount(EmployeeStatisticsQuery::MAX_VISIBLE_CATEGORIES, $rows);
        $this->assertCount(1, $otherRows);
        $this->assertSame(5, $otherRows->sole()['total']);
        $this->assertSame(14, array_sum(array_column($rows, 'total')));
    }

    public function test_query_memilih_satu_riwayat_jabatan_terbaru_saat_data_legacy_memiliki_dua_penanda_terbaru(): void
    {
        $employee = Employee::factory()->create();
        $jenisJabatanBertmt = RefJenisJabatan::query()->create([
            'nama' => 'Administrasi Bertanggal Statistik',
            'maks_usia_pensiun' => 60,
            'is_active' => true,
        ]);
        $jenisJabatanTanpaTmt = RefJenisJabatan::query()->create([
            'nama' => 'Administrasi Tanpa TMT Statistik',
            'maks_usia_pensiun' => 60,
            'is_active' => true,
        ]);
        $unitKerjaBertmt = RefUnitKerja::query()->create([
            'nama' => 'Unit Bertanggal Statistik',
            'jenis_unit' => 'Bagian',
            'level' => 1,
            'is_active' => true,
        ]);
        $unitKerjaTanpaTmt = RefUnitKerja::query()->create([
            'nama' => 'Unit Tanpa TMT Statistik',
            'jenis_unit' => 'Bagian',
            'level' => 1,
            'is_active' => true,
        ]);

        PositionHistory::query()->create([
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Jabatan Lama Statistik',
            'jenis_jabatan_id' => $jenisJabatanBertmt->id,
            'unit_kerja_id' => $unitKerjaBertmt->id,
            'tmt_jabatan' => '2024-01-01',
            'no_sk' => 'SK-LAMA-STATISTIK',
            'tanggal_sk' => '2024-01-01',
            'is_latest' => true,
        ]);
        PositionHistory::query()->create([
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Jabatan Baru Statistik',
            'jenis_jabatan_id' => $jenisJabatanBertmt->id,
            'unit_kerja_id' => $unitKerjaBertmt->id,
            'tmt_jabatan' => '2025-01-01',
            'no_sk' => 'SK-BARU-STATISTIK',
            'tanggal_sk' => '2025-01-01',
            'is_latest' => true,
        ]);
        PositionHistory::query()->create([
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Jabatan Tanpa TMT Statistik',
            'jenis_jabatan_id' => $jenisJabatanTanpaTmt->id,
            'unit_kerja_id' => $unitKerjaTanpaTmt->id,
            'tmt_jabatan' => null,
            'no_sk' => 'SK-TANPA-TMT-STATISTIK',
            'tanggal_sk' => '2025-06-01',
            'is_latest' => true,
        ]);

        $dimensions = app(EmployeeStatisticsQuery::class)->execute(Employee::query())['dimensions'];
        $jabatan = $dimensions['jabatan'];

        $this->assertSame(1, collect($jabatan)->firstWhere('label', 'Jabatan Baru Statistik')['total']);
        $this->assertNull(collect($jabatan)->firstWhere('label', 'Jabatan Lama Statistik'));
        $this->assertNull(collect($jabatan)->firstWhere('label', 'Jabatan Tanpa TMT Statistik'));
        $this->assertSame(1, collect($dimensions['jenis_jabatan'])->firstWhere('label', 'Administrasi Bertanggal Statistik')['total']);
        $this->assertNull(collect($dimensions['jenis_jabatan'])->firstWhere('label', 'Administrasi Tanpa TMT Statistik'));
        $this->assertSame(1, collect($dimensions['unit_kerja'])->firstWhere('label', 'Unit Bertanggal Statistik')['total']);
        $this->assertNull(collect($dimensions['unit_kerja'])->firstWhere('label', 'Unit Tanpa TMT Statistik'));
    }

    public function test_query_memilih_riwayat_pangkat_bertmt_saat_data_legacy_memiliki_tmt_null(): void
    {
        $employee = Employee::factory()->create();
        $golonganBertmt = RefGolongan::query()->create([
            'kode' => 'STAT-TMT',
            'nama' => 'Golongan Bertanggal Statistik',
            'urutan' => 101,
            'is_active' => true,
        ]);
        $golonganTanpaTmt = RefGolongan::query()->create([
            'kode' => 'STAT-NULL',
            'nama' => 'Golongan Tanpa TMT Statistik',
            'urutan' => 102,
            'is_active' => true,
        ]);

        RankHistory::query()->create([
            'employee_id' => $employee->id,
            'golongan_id' => $golonganBertmt->id,
            'tmt_pangkat' => '2025-01-01',
            'no_sk' => 'SK-PANGKAT-BERTMT-STATISTIK',
            'tanggal_sk' => '2025-01-01',
            'is_latest' => true,
        ]);
        RankHistory::query()->create([
            'employee_id' => $employee->id,
            'golongan_id' => $golonganTanpaTmt->id,
            'tmt_pangkat' => null,
            'no_sk' => 'SK-PANGKAT-TANPA-TMT-STATISTIK',
            'tanggal_sk' => '2025-06-01',
            'is_latest' => true,
        ]);

        $golongan = app(EmployeeStatisticsQuery::class)->execute(Employee::query())['dimensions']['golongan'];

        $this->assertSame(1, collect($golongan)->firstWhere('label', 'STAT-TMT')['total']);
        $this->assertNull(collect($golongan)->firstWhere('label', 'STAT-NULL'));
    }
}
