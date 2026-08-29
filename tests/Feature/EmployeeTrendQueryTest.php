<?php

namespace Tests\Feature;

use App\Actions\Dashboards\BuildAdminDashboardAction;
use App\Actions\Dashboards\BuildPimpinanDashboardAction;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\RefStatusPegawai;
use App\Models\User;
use App\Queries\Dashboards\EmployeeTrendQuery;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    public function test_label_tren_tetap_mencakup_setiap_bulan_saat_hari_ini_di_akhir_bulan(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 09:00:00'));

        $titik = app(EmployeeTrendQuery::class)->monthlyActiveCounts();
        $expected = collect(range(11, 0))
            ->map(fn (int $offset): string => now()->startOfMonth()->subMonths($offset)->translatedFormat('M Y'))
            ->all();

        $this->assertSame($expected, array_column($titik, 'label'));
        $this->assertCount(12, array_unique(array_column($titik, 'label')));
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

    public function test_pegawai_aktif_khusus_dengan_variasi_kelompok_terhitung_dalam_tren(): void
    {
        $status = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        RefStatusPegawai::query()->whereKey($status->id)->update(['kelompok' => 'Aktif/khusus']);
        DB::table('ref_status_pegawai')->where('id', $status->id)->update([
            'kelompok' => ' AKTIF/KHUSUS ',
        ]);
        $employee = Employee::factory()->create([
            'status_pegawai_id' => $status->id,
            'tanggal_pensiun' => null,
        ]);
        DB::table('employees')->where('id', $employee->id)->update([
            'status_pegawai_id' => $status->id,
            'status_aktif' => 'Tugas Belajar',
        ]);
        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_pegawai_id' => $status->id,
            'status_nama' => 'Tugas Belajar',
            'tanggal_efektif' => now()->subMonths(3)->startOfMonth()->toDateString(),
            'is_latest' => true,
        ]);

        $titik = app(EmployeeTrendQuery::class)->monthlyActiveCounts();

        $this->assertSame(1, $titik[11]['jumlah']);
    }

    public function test_pegawai_tanpa_relasi_status_gagal_tertutup_meski_snapshot_aktif(): void
    {
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => null,
            'status_tanggal' => null,
        ]);
        DB::table('employees')->where('id', $employee->id)->update([
            'status_pegawai_id' => null,
            'status_aktif' => 'Aktif',
        ]);

        $titik = app(EmployeeTrendQuery::class)->monthlyActiveCounts();

        $this->assertSame(0, $titik[0]['jumlah']);
        $this->assertSame(0, $titik[11]['jumlah']);
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
            'status_aktif' => 'Non-Aktif',
            'status_pegawai_id' => RefStatusPegawai::query()->where('kode', 'NONAKTIF')->value('id'),
            'tanggal_pensiun' => null,
            'created_at' => now()->subMonths(6),
        ]);

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

    public function test_riwayat_status_bertanggal_migrasi_tidak_menutupi_tanggal_pensiun(): void
    {
        // Migrasi riwayat status mengisi tanggal efektif dengan waktu migrasi ketika pegawai
        // tidak punya tanggal status, sehingga pegawai yang pensiun jauh sebelumnya bisa punya
        // riwayat bertanggal hari ini. Tanggal pensiun adalah data domain dan harus menang.
        $employee = Employee::factory()->create([
            'status_aktif' => 'Pensiun',
            'status_tanggal' => null,
            'tanggal_pensiun' => now()->subMonths(8)->startOfMonth()->toDateString(),
            'created_at' => now()->subMonths(11),
        ]);
        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_nama' => 'Pensiun',
            'keterangan' => 'Migrasi data existing',
            'tanggal_efektif' => now()->toDateString(),
            'is_latest' => true,
        ]);

        $titik = app(EmployeeTrendQuery::class)->monthlyActiveCounts();

        $this->assertSame(1, $titik[2]['jumlah']);
        $this->assertSame(0, $titik[3]['jumlah']);
        $this->assertSame(0, $titik[11]['jumlah']);
    }

    public function test_pegawai_yang_mulai_tepat_di_akhir_bulan_dihitung_pada_bulan_itu(): void
    {
        // Batas akhir bulan diuji eksplisit karena titik bulanan memakai perbandingan inklusif
        // pada akhir bulan, dan kekeliruan format tanggal hanya terlihat pada tanggal batas.
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => null,
            'created_at' => now()->subMonths(11),
        ]);
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'CPNS',
            'tmt_pengangkatan' => now()->subMonths(6)->endOfMonth()->toDateString(),
        ]);

        $titik = app(EmployeeTrendQuery::class)->monthlyActiveCounts();

        $this->assertSame(0, $titik[4]['jumlah']);
        $this->assertSame(1, $titik[5]['jumlah']);
    }

    public function test_pegawai_yang_keluar_tepat_di_akhir_bulan_tidak_dihitung_pada_bulan_itu(): void
    {
        Employee::factory()->create([
            'status_aktif' => 'Pensiun',
            'status_tanggal' => null,
            'tanggal_pensiun' => now()->subMonths(6)->endOfMonth()->toDateString(),
            'created_at' => now()->subMonths(11),
        ]);

        $titik = app(EmployeeTrendQuery::class)->monthlyActiveCounts();

        // Pegawai masih dihitung pada bulan sebelumnya, tetapi tidak pada bulan tanggal keluarnya.
        $this->assertSame(1, $titik[4]['jumlah']);
        $this->assertSame(0, $titik[5]['jumlah']);
    }

    public function test_batasan_bulan_jeda_nonaktif_masih_dihitung_karena_memakai_status_terakhir(): void
    {
        // Test ini mengunci batasan yang diketahui, bukan perilaku yang diinginkan. Karena tanggal
        // keluar ditentukan dari status terakhir, pegawai yang sempat nonaktif lalu kembali aktif
        // tetap terhitung pada bulan-bulan jedanya. Memperbaikinya menuntut evaluasi status per
        // titik bulan, dan itu hanya benar setelah definisi pegawai aktif diputuskan memakai
        // kelompok pada referensi status pegawai, karena Tugas Belajar termasuk kelompok aktif
        // meskipun namanya bukan Aktif. Bila perilaku ini kelak diperbaiki, test ini harus gagal
        // lebih dulu agar perubahannya dilakukan secara sadar.
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => null,
            'created_at' => now()->subMonths(11),
        ]);
        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_nama' => 'Cuti Luar Tanggungan Negara',
            'keterangan' => 'CLTN empat bulan.',
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

        $this->assertSame(1, $titik[6]['jumlah']);
        $this->assertSame(1, $titik[8]['jumlah']);
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
