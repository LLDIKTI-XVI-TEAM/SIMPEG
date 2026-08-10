<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Mengunci filter periode daftar cuti pada dua cakupan: setahun penuh dan satu bulan.
 * Sebelumnya hanya format tahun-bulan yang dikenali sehingga filter tahun diabaikan tanpa pesan apa pun.
 */
class CutiListPeriodFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_filter_tahun_hanya_memuat_pengajuan_tahun_tersebut(): void
    {
        $this->seedThreeYears();

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('cuti', ['periode' => '2025']));

        $response->assertOk();
        $tanggal = $response->viewData('riwayatCuti')->getCollection()->pluck('periode')->all();

        $this->assertCount(2, $tanggal);
        foreach ($tanggal as $periode) {
            $this->assertStringStartsWith('2025', (string) $periode);
        }
    }

    /**
     * Bulan Maret 2025 berada di luar daftar dua belas bulan terakhir, sehingga baris ini membuktikan
     * filter tahun menjangkau data yang tidak dapat dipilih lewat opsi bulan.
     */
    public function test_filter_tahun_menjangkau_bulan_di_luar_dua_belas_bulan_terakhir(): void
    {
        $this->seedThreeYears();

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('cuti', ['periode' => '2024']));

        $response->assertOk();
        $rows = $response->viewData('riwayatCuti');

        $this->assertSame(1, $rows->total());
        $this->assertStringStartsWith('2024', (string) $rows->getCollection()->first()['periode']);
    }

    public function test_filter_bulan_yang_sudah_ada_tetap_bekerja(): void
    {
        $this->seedThreeYears();

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('cuti', ['periode' => '2025-03']));

        $response->assertOk();
        $rows = $response->viewData('riwayatCuti');

        $this->assertSame(1, $rows->total());
        $this->assertSame('2025-03', substr((string) $rows->getCollection()->first()['periode'], 0, 7));
    }

    public function test_periode_kosong_memuat_seluruh_tahun(): void
    {
        $this->seedThreeYears();

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('cuti'));

        $response->assertOk();
        $this->assertSame(4, $response->viewData('riwayatCuti')->total());
    }

    /**
     * Nama bulan Indonesia dipakai tautan dari permukaan rekap; daftar wajib menafsirkannya sama.
     */
    public function test_nama_bulan_indonesia_ditafsirkan_sama_seperti_rekap(): void
    {
        $this->seedThreeYears();

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('cuti', ['periode' => 'Maret 2025']));

        $response->assertOk();
        $rows = $response->viewData('riwayatCuti');

        $this->assertSame(1, $rows->total());
        $this->assertSame('2025-03', substr((string) $rows->getCollection()->first()['periode'], 0, 7));
    }

    /**
     * Nilai tidak sah tidak memfilter apa pun, mengikuti tafsir yang sudah dipakai permukaan rekap.
     *
     * @return array<string, array{0: string}>
     */
    public static function periodeTidakSahProvider(): array
    {
        return [
            'bukan angka' => ['abc'],
            'bulan di luar rentang' => ['2026-13'],
            'bulan nol' => ['2026-00'],
            'tahun tidak lengkap' => ['202'],
            // Tahun 0 sempat diteruskan sebagai batas tanggal dan ditolak PostgreSQL sehingga halaman gagal dimuat.
            'tahun nol' => ['0000'],
            'tahun nol dengan bulan' => ['0000-01'],
        ];
    }

    #[DataProvider('periodeTidakSahProvider')]
    public function test_periode_tidak_sah_tidak_menggagalkan_halaman(string $periode): void
    {
        $this->seedThreeYears();

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('cuti', ['periode' => $periode]));

        $response->assertOk();
        $this->assertSame(4, $response->viewData('riwayatCuti')->total());
    }

    /**
     * Dropdown tahun memakai parameter tersendiri di samping filter periode;
     * keduanya harus menghasilkan irisan tahun yang sama.
     */
    public function test_filter_tahun_memuat_hanya_pengajuan_pada_tahun_tersebut(): void
    {
        $this->seedThreeYears();

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('cuti', ['tahun' => '2025']));

        $response->assertOk();
        $rows = $response->viewData('riwayatCuti');

        $this->assertSame(2, $rows->total());
        foreach ($rows->getCollection() as $row) {
            $this->assertStringStartsWith('2025', (string) $row['periode']);
        }
    }

    /**
     * Nilai tahun tidak sah diabaikan seperti perilaku filter periode: halaman tetap
     * termuat penuh dan nilai tersebut tidak pernah mencapai query database.
     */
    public function test_filter_tahun_tidak_sah_diabaikan_tanpa_menggagalkan_halaman(): void
    {
        $this->seedThreeYears();

        foreach (['abc', '202', '20255'] as $tahunTidakSah) {
            $response = $this->actingAs(User::factory()->superAdmin()->create())
                ->get(route('cuti', ['tahun' => $tahunTidakSah]));

            $response->assertOk();
            $this->assertSame(4, $response->viewData('riwayatCuti')->total(), "Tahun {$tahunTidakSah} seharusnya diabaikan.");
        }
    }

    public function test_opsi_tahun_disediakan_untuk_dropdown_dan_terurut_menurun(): void
    {
        $this->seedThreeYears();

        $response = $this->actingAs(User::factory()->superAdmin()->create())->get(route('cuti'));

        $response->assertOk();
        $optTahuns = $response->viewData('optTahuns')->all();

        $this->assertContains('2024', $optTahuns);
        $this->assertContains('2025', $optTahuns);
        $this->assertContains('2026', $optTahuns);
        $this->assertSame(array_values(array_reverse(array_unique($optTahuns))), array_reverse($optTahuns));
    }

    public function test_counter_ringkasan_tidak_terpengaruh_filter_periode(): void
    {
        $this->seedThreeYears();

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('cuti', ['periode' => '2025']));

        $response->assertOk();
        $this->assertSame(4, $response->viewData('totalPengajuan'));
    }

    /**
     * Filter periode tidak boleh menjadi jalan memutari batas data pegawai.
     */
    public function test_pegawai_tetap_hanya_melihat_data_sendiri_saat_memfilter_tahun(): void
    {
        $jenis = $this->createJenis();
        $milikSendiri = Employee::factory()->create(['nama_lengkap' => 'Pegawai Pemilik Data']);
        $milikOrangLain = Employee::factory()->create(['nama_lengkap' => 'Pegawai Lain']);

        $this->createLeave($milikSendiri, $jenis, '2025-05-04', 'menunggu_approval');
        $this->createLeave($milikOrangLain, $jenis, '2025-06-04', 'menunggu_approval');

        $user = User::factory()->create(['employee_id' => $milikSendiri->id]);
        $user->update(['role' => 'pegawai']);

        $response = $this->actingAs($user)->get(route('cuti', ['periode' => '2025']));

        $response->assertOk();
        $rows = $response->viewData('riwayatCuti');

        $this->assertSame(1, $rows->total());
        $this->assertSame('Pegawai Pemilik Data', $rows->getCollection()->first()['nama']);
        $response->assertDontSee('Pegawai Lain');
    }

    /**
     * Warna badge mengikuti ketetapan kanonik: Perubahan biru dan Menunggu kuning.
     * Sebelumnya Perubahan memakai merah sehingga tidak terbedakan dari Tidak Disetujui.
     */
    public function test_warna_badge_status_mengikuti_ketetapan_kanonik(): void
    {
        $jenis = $this->createJenis();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Uji Warna']);

        foreach ([
            'menunggu_approval' => 'text-warning',
            'disetujui' => 'text-success',
            'perlu_perubahan' => 'text-info',
            'ditangguhkan' => 'text-orange',
            'tidak_disetujui' => 'text-danger',
        ] as $status => $kelasWarna) {
            LeaveRequest::query()->delete();
            $this->createLeave($employee, $jenis, '2026-04-06', $status);

            $response = $this->actingAs(User::factory()->superAdmin()->create())->get(route('cuti'));

            $response->assertOk();
            $this->assertMatchesRegularExpression(
                $this->polaWarnaBaris($status, $kelasWarna),
                $response->getContent(),
                "Status {$status} seharusnya memakai {$kelasWarna}.",
            );
        }
    }

    public function test_perubahan_tidak_lagi_memakai_warna_yang_sama_dengan_tidak_disetujui(): void
    {
        $jenis = $this->createJenis();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Uji Beda Warna']);
        $this->createLeave($employee, $jenis, '2026-04-06', 'perlu_perubahan');

        $response = $this->actingAs(User::factory()->superAdmin()->create())->get(route('cuti'));

        $response->assertOk();
        $this->assertDoesNotMatchRegularExpression(
            $this->polaWarnaBaris('perlu_perubahan', 'text-danger'),
            $response->getContent(),
        );
    }

    /**
     * Pencocokan dibatasi pada satu baris tabel agar kelas warna elemen lain di halaman tidak ikut terbaca.
     */
    private function polaWarnaBaris(string $status, string $kelasWarna): string
    {
        return '/<tr\b[^>]*\bdata-status="'.preg_quote($status, '/').'"[^>]*>'
            .'(?:(?!<\/tr>).)*'.preg_quote($kelasWarna, '/').'/s';
    }

    /**
     * Kartu ringkasan menyampaikan status yang sama dengan badge, sehingga warnanya tidak boleh berbeda.
     * Merah tetap milik penolakan; penangguhan menahan pengajuan dan bukan menolaknya.
     */
    public function test_kartu_ringkasan_ditangguhkan_tidak_memakai_warna_penolakan(): void
    {
        $jenis = $this->createJenis();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Uji Kartu']);
        $this->createLeave($employee, $jenis, '2026-04-06', 'ditangguhkan');

        $response = $this->actingAs(User::factory()->superAdmin()->create())->get(route('cuti'));

        $response->assertOk();
        $konten = $response->getContent();

        $this->assertStringContainsString('border-b-orange', $konten);
        $this->assertStringNotContainsString('border-b-danger', $konten);
    }

    /**
     * Opsi tahun bersumber dari data dalam scope; tahun yang hanya dimiliki pegawai lain
     * tidak boleh muncul karena itu membocorkan keberadaan pengajuan orang lain.
     */
    public function test_opsi_tahun_pegawai_tidak_membocorkan_tahun_milik_pegawai_lain(): void
    {
        $jenis = $this->createJenis();
        $milikSendiri = Employee::factory()->create(['nama_lengkap' => 'Pegawai Pemilik Opsi']);
        $milikOrangLain = Employee::factory()->create(['nama_lengkap' => 'Pegawai Lain Opsi']);

        $this->createLeave($milikSendiri, $jenis, '2026-02-03', 'menunggu_approval');
        $this->createLeave($milikOrangLain, $jenis, '2019-02-03', 'disetujui');

        $user = User::factory()->create(['employee_id' => $milikSendiri->id]);
        $user->update(['role' => 'pegawai']);

        $response = $this->actingAs($user)->get(route('cuti'));

        $response->assertOk();
        $optTahuns = $response->viewData('optTahuns')->all();

        $this->assertNotContains('2019', $optTahuns);
        $this->assertContains('2026', $optTahuns);
    }

    /**
     * Tautan berfilter harus tetap berfilter setelah melewati jalur alias, kalau tidak pengguna
     * membaca seluruh data sambil menyangka sedang melihat hasil tersaring.
     */
    public function test_jalur_alias_mempertahankan_filter_periode(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->get('/cuti?periode=2025')
            ->assertRedirect(route('cuti', ['periode' => '2025']));

        $this->actingAs($user)
            ->get('/dashboard/cuti/legacy?periode=2025&status=disetujui')
            ->assertRedirect(route('cuti', ['periode' => '2025', 'status' => 'disetujui']));
    }

    public function test_jalur_alias_tanpa_filter_tetap_menuju_daftar(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)->get('/cuti')->assertRedirect(route('cuti'));
        $this->actingAs($user)->get('/dashboard/cuti/legacy')->assertRedirect(route('cuti'));
    }

    /**
     * Basis data mengizinkan status di luar lima status keputusan. Seluruhnya harus tetap dapat dirender
     * karena label status dibaca dari peta tetap, sehingga satu status tanpa label akan menjatuhkan daftar.
     *
     * @return array<string, array{0: string}>
     */
    public static function statusDiizinkanProvider(): array
    {
        return [
            'menunggu approval' => ['menunggu_approval'],
            'ditangguhkan' => ['ditangguhkan'],
            'ditangguhkan tugas dinas' => ['ditangguhkan_tugas_dinas'],
            'perlu perubahan' => ['perlu_perubahan'],
            'disetujui' => ['disetujui'],
            'tidak disetujui' => ['tidak_disetujui'],
            'dikembalikan karena rollover' => ['dikembalikan_karena_rollover'],
        ];
    }

    #[DataProvider('statusDiizinkanProvider')]
    public function test_setiap_status_yang_diizinkan_tetap_dapat_dirender(string $status): void
    {
        $jenis = $this->createJenis();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Status Lengkap']);
        $this->createLeave($employee, $jenis, '2026-03-02', $status);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('cuti'));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('riwayatCuti')->total());
    }

    private function seedThreeYears(): void
    {
        $jenis = $this->createJenis();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Rentang Tahun']);

        $this->createLeave($employee, $jenis, '2024-11-04', 'disetujui');
        $this->createLeave($employee, $jenis, '2025-03-05', 'disetujui');
        $this->createLeave($employee, $jenis, '2025-10-06', 'menunggu_approval');
        $this->createLeave($employee, $jenis, '2026-04-06', 'menunggu_approval');
    }

    private function createJenis(): RefJenisCuti
    {
        return RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
    }

    private function createLeave(Employee $employee, RefJenisCuti $jenis, string $mulai, string $status): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => $mulai,
            'tanggal_selesai' => $mulai,
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Uji filter periode',
            'status' => $status,
        ]);
    }
}
