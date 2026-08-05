<?php

namespace Tests\Feature;

use App\Actions\Dashboards\BuildAdminDashboardAction;
use App\Actions\Dashboards\BuildPimpinanDashboardAction;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\User;
use App\Queries\Dashboards\EmployeeTrendQuery;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EmployeeTrendQueryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Waktu dibekukan pada pertengahan bulan supaya batas akhir bulan pada setiap titik
     * tren bersifat pasti dan test tidak berubah hasil ketika dijalankan di akhir bulan.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_menghasilkan_dua_belas_titik_berurutan(): void
    {
        $titik = app(EmployeeTrendQuery::class)->monthlyActiveCounts();

        $this->assertCount(12, $titik);
        $this->assertSame(['label', 'jumlah'], array_keys($titik[0]));
        $this->assertSame(now()->translatedFormat('M Y'), $titik[11]['label']);
        $this->assertSame(now()->subMonths(11)->translatedFormat('M Y'), $titik[0]['label']);
    }

    public function test_pegawai_aktif_terhitung_pada_bulan_berjalan(): void
    {
        Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => null,
            'created_at' => now()->subMonths(6),
        ]);

        $titik = app(EmployeeTrendQuery::class)->monthlyActiveCounts();

        $this->assertSame(1, $titik[11]['jumlah']);
    }

    public function test_pegawai_pensiun_tidak_terhitung_setelah_tanggal_pensiunnya(): void
    {
        Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => now()->subMonths(3)->startOfMonth()->toDateString(),
            'created_at' => now()->subMonths(10),
        ]);

        $titik = app(EmployeeTrendQuery::class)->monthlyActiveCounts();

        // Tanpa riwayat pengangkatan, pegawai dianggap sudah aktif sejak awal rentang,
        // sehingga test ini menjaga sisi keluar: pensiun memutus hitungan pada bulan berlakunya.
        $this->assertSame(1, $titik[0]['jumlah']);
        $this->assertSame(1, $titik[7]['jumlah']);
        $this->assertSame(0, $titik[8]['jumlah']);
        $this->assertSame(0, $titik[11]['jumlah']);
    }

    public function test_pegawai_terhapus_lunak_tidak_pernah_terhitung(): void
    {
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => null,
            'created_at' => now()->subMonths(6),
        ]);
        $employee->delete();

        $titik = app(EmployeeTrendQuery::class)->monthlyActiveCounts();

        $this->assertSame(0, $titik[11]['jumlah']);
    }

    public function test_pegawai_dihitung_sejak_tmt_pengangkatan_bukan_sejak_dicatat(): void
    {
        // Data diketik hari ini, tetapi pengangkatannya berlaku lima bulan lalu.
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => null,
            'created_at' => now(),
        ]);
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'CPNS',
            'tmt_pengangkatan' => now()->subMonths(5)->startOfMonth()->toDateString(),
        ]);

        $titik = app(EmployeeTrendQuery::class)->monthlyActiveCounts();

        // Indeks 6 adalah lima bulan lalu; sebelum itu pegawai belum diangkat.
        $this->assertSame(0, $titik[5]['jumlah']);
        $this->assertSame(1, $titik[6]['jumlah']);
        $this->assertSame(1, $titik[11]['jumlah']);
    }

    public function test_pegawai_tanpa_riwayat_pengangkatan_dianggap_aktif_sejak_awal_rentang(): void
    {
        Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => null,
            'created_at' => now(),
        ]);

        $titik = app(EmployeeTrendQuery::class)->monthlyActiveCounts();

        $this->assertSame(1, $titik[0]['jumlah']);
        $this->assertSame(1, $titik[11]['jumlah']);
    }

    public function test_pegawai_mutasi_berhenti_dihitung_sejak_tanggal_efektif_riwayat_status(): void
    {
        $employee = Employee::factory()->create([
            'status_aktif' => 'Mutasi',
            'tanggal_pensiun' => null,
            'created_at' => now()->subMonths(11),
        ]);
        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_nama' => 'Mutasi',
            'keterangan' => 'Mutasi ke instansi lain.',
            'tanggal_efektif' => now()->subMonths(3)->startOfMonth()->toDateString(),
            'is_latest' => true,
        ]);

        $titik = app(EmployeeTrendQuery::class)->monthlyActiveCounts();

        // Masih terhitung sebelum mutasi berlaku, berhenti pada bulan berlakunya.
        $this->assertSame(1, $titik[7]['jumlah']);
        $this->assertSame(0, $titik[8]['jumlah']);
        $this->assertSame(0, $titik[11]['jumlah']);
    }

    public function test_pegawai_kembali_aktif_setelah_nonaktif_tetap_dihitung(): void
    {
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => null,
            'created_at' => now()->subMonths(11),
        ]);
        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_nama' => 'Cuti Luar Tanggungan Negara',
            'keterangan' => 'CLTN satu tahun.',
            'tanggal_efektif' => now()->subMonths(6)->startOfMonth()->toDateString(),
            'is_latest' => false,
        ]);
        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_nama' => 'Aktif',
            'keterangan' => 'Kembali bertugas.',
            'tanggal_efektif' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'is_latest' => true,
        ]);

        $titik = app(EmployeeTrendQuery::class)->monthlyActiveCounts();

        // Riwayat terakhir menyatakan aktif, sehingga pegawai tidak boleh dianggap keluar permanen.
        $this->assertSame(1, $titik[11]['jumlah']);
    }

    public function test_pegawai_nonaktif_tanpa_jejak_tanggal_tidak_dihitung_sama_sekali(): void
    {
        Employee::factory()->create([
            'status_aktif' => 'Non-Aktif',
            'tanggal_pensiun' => null,
            'status_tanggal' => null,
            'created_at' => now()->subMonths(6),
        ]);

        $titik = app(EmployeeTrendQuery::class)->monthlyActiveCounts();

        $this->assertSame(0, $titik[0]['jumlah']);
        $this->assertSame(0, $titik[11]['jumlah']);
    }

    public function test_dashboard_admin_dan_pimpinan_menghasilkan_tren_yang_sama(): void
    {
        // Kontrak dashboard mewajibkan kedua surface memakai metode yang sama, sehingga
        // kesamaan angka perlu dijaga test, bukan hanya disepakati.
        $this->seed(RbacSeeder::class);
        $pimpinan = User::factory()->pimpinan()->create();

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => null,
            'created_at' => now(),
        ]);
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'CPNS',
            'tmt_pengangkatan' => now()->subMonths(4)->startOfMonth()->toDateString(),
        ]);

        $trenAdmin = app(BuildAdminDashboardAction::class)->execute()['trenPegawai'];
        $trenPimpinan = app(BuildPimpinanDashboardAction::class)->execute($pimpinan)['trenPegawai'];

        $this->assertSame(
            collect($trenAdmin)->toArray(),
            collect($trenPimpinan)->toArray(),
        );
        $this->assertSame(1, collect($trenAdmin)->last()['jumlah']);
    }
}
