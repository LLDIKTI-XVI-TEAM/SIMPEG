<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Models\EmployeeStatusHistory;
use App\Models\Permission;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenjangPendidikan;
use App\Models\RefStatusPegawai;
use App\Models\RefStatusPerkawinan;
use App\Models\RefUnitKerja;
use App\Models\Role;
use App\Models\SalaryHistory;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PimpinanEmployeeDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_pimpinan_sees_a_real_read_only_employee_detail_without_sensitive_identifiers(): void
    {
        $pensiun = RefStatusPegawai::firstOrCreate(
            ['nama' => 'Pensiun'],
            ['keterangan' => 'Pegawai pensiun'],
        );
        $employee = Employee::factory()->lengkap()->create([
            'nama_lengkap' => 'Pegawai Detail Aktual',
            'jabatan_terakhir' => 'Analis Kepegawaian',
            'status_pegawai_id' => $pensiun->id,
            'status_aktif' => 'Pensiun',
            'tanggal_pensiun' => '2040-01-01',
        ]);
        $pimpinan = User::factory()->pimpinan()->create();

        $this->actingAs($pimpinan)
            ->get(route('pimpinan.pegawai.show', $employee))
            ->assertOk()
            ->assertSee('Pegawai Detail Aktual')
            ->assertSee('Analis Kepegawaian')
            ->assertSee('Pensiun')
            ->assertSee('bg-danger/10', false)
            ->assertSee('aria-label="Navigasi detail pegawai"', false)
            ->assertSee('aria-controls="pimpinan-panel-profile"', false)
            ->assertSee('id="pimpinan-panel-profile"', false)
            ->assertSee('history-export-unavailable', false)
            ->assertDontSee($employee->nik)
            ->assertDontSee('/pimpinan/laporan/pegawai/custom', false);
    }

    public function test_pimpinan_employee_list_uses_real_employee_rows(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Daftar Aktual',
            'jabatan_terakhir' => 'Pranata Komputer',
        ]);

        $apiResponse = $this->actingAs(User::factory()->pimpinan()->create())
            ->getJson(route('api.v1.pegawai.index'));

        $apiResponse->assertOk()
            ->assertJsonFragment(['nama_lengkap' => 'Pegawai Daftar Aktual'])
            ->assertJsonFragment(['jabatan' => 'Pranata Komputer']);

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.pegawai.index'))
            ->assertOk()
            ->assertSee(':href="detailUrl(p)"', false)
            ->assertSee(':aria-label="\'Detail pegawai \' + p.nama_lengkap"', false);
    }

    public function test_pimpinan_sees_read_only_family_and_active_supervisor_without_family_nik(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Keluarga Aktual']);
        $familyNik = '7171010101010001';
        EmployeeFamily::create([
            'employee_id' => $employee->id,
            'nama_anggota' => 'Siti Keluarga Aktual',
            'hubungan' => 'Istri',
            'nik' => $familyNik,
            'tempat_lahir' => 'Manado',
            'tanggal_lahir' => '1990-05-10',
            'jenis_kelamin' => 'P',
            'status_tunjangan' => true,
            'pekerjaan' => 'Guru',
        ]);
        $supervisor = Employee::factory()->create([
            'nama_lengkap' => 'Supervisor Aktual',
            'jabatan_terakhir' => 'Kepala Bagian Akademik',
        ]);
        $unit = RefUnitKerja::create(['nama' => 'Bagian Akademik']);
        $jenisJabatan = RefJenisJabatan::create([
            'nama' => 'Jabatan Struktural',
            'maks_usia_pensiun' => 60,
        ]);
        PositionHistory::create([
            'employee_id' => $supervisor->id,
            'nama_jabatan' => 'Kepala Bagian Akademik',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'unit_kerja_id' => $unit->id,
            'tmt_jabatan' => '2025-01-01',
            'no_sk' => 'SK-SUPERVISOR-001',
            'tanggal_sk' => '2025-01-01',
            'is_latest' => true,
        ]);
        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'supervisor_id' => $supervisor->id,
            'kepala_bagian_id' => $supervisor->id,
            'tanggal_mulai' => '2025-01-01',
        ]);

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.pegawai.show', $employee))
            ->assertOk()
            ->assertSee('Data Keluarga')
            ->assertSee('Siti Keluarga Aktual')
            ->assertSee('Istri')
            ->assertSee('Manado')
            ->assertSee('Perempuan')
            ->assertSee('Ditanggung')
            ->assertSee('10-05-1990')
            ->assertSee('Kepala Bagian/Supervisor Aktif')
            ->assertSee('Supervisor Aktual')
            ->assertSee('Kepala Bagian Akademik')
            ->assertSee('Bagian Akademik')
            ->assertDontSee($familyNik)
            ->assertDontSee('Tambah Keluarga')
            ->assertDontSee('Edit Keluarga')
            ->assertDontSee('Hapus Keluarga');
    }

    public function test_admin_melihat_nik_keluarga_pada_presentasi_shared(): void
    {
        $employee = Employee::factory()->create();
        $familyNik = '7171010101010042';
        EmployeeFamily::create([
            'employee_id' => $employee->id,
            'nama_anggota' => 'Keluarga Parity Marker',
            'hubungan' => 'Anak',
            'nik' => $familyNik,
            'tempat_lahir' => 'Tomohon Marker',
            'tanggal_lahir' => '2012-06-17',
            'jenis_kelamin' => 'L',
            'status_tunjangan' => false,
            'pekerjaan' => 'Pelajar Marker',
        ]);

        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee))
            ->assertOk()
            ->assertSee('data-family-readonly-row', false)
            ->assertSee('Tomohon Marker')
            ->assertSee('Laki-laki')
            ->assertSee('Tidak Ditanggung')
            ->assertSee('x-text="formatDate(fam.tanggal_lahir)"', false)
            ->assertSee('x-text="fam.nik || \'-\'"', false)
            ->assertSee($familyNik);

        $html = $response->getContent();
        $fetchStart = strpos($html, 'async fetchKeluarga()');
        $deleteStart = strpos($html, 'async deleteKeluarga', $fetchStart ?: 0);
        $this->assertNotFalse($fetchStart);
        $this->assertNotFalse($deleteStart);
        $fetchScript = substr($html, $fetchStart, $deleteStart - $fetchStart);
        $this->assertMatchesRegularExpression('/nik:\s*f\.nik/', $fetchScript);
    }

    public function test_pimpinan_memakai_presentasi_keluarga_shared_tanpa_nik_plaintext(): void
    {
        $employee = Employee::factory()->create();
        $familyNik = '7171010101010042';
        EmployeeFamily::create([
            'employee_id' => $employee->id,
            'nama_anggota' => 'Keluarga Parity Marker',
            'hubungan' => 'Anak',
            'nik' => $familyNik,
            'tempat_lahir' => 'Tomohon Marker',
            'tanggal_lahir' => '2012-06-17',
            'jenis_kelamin' => 'L',
            'status_tunjangan' => false,
            'pekerjaan' => 'Pelajar Marker',
        ]);

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.pegawai.show', $employee))
            ->assertOk()
            ->assertSee('data-family-readonly-row', false)
            ->assertSee('Tomohon Marker')
            ->assertSee('Laki-laki')
            ->assertSee('Tidak Ditanggung')
            ->assertSee('17-06-2012')
            ->assertSee('NIK. Tidak ditampilkan')
            ->assertDontSee($familyNik);
    }

    public function test_admin_dan_pimpinan_memakai_struktur_visual_shared_detail_shell_dan_sembilan_tab_horizontal(): void
    {
        $employee = Employee::factory()->create();
        $expectedTabs = [
            'profile' => 'Profil',
            'keluarga' => 'Keluarga',
            'kepangkatan' => 'Kepangkatan',
            'jabatan' => 'Jabatan',
            'kgb' => 'KGB',
            'disiplin' => 'Hukuman Disiplin',
            'pendidikan' => 'Pendidikan',
            'pengangkatan' => 'Pengangkatan',
            'docs' => 'Dokumen SK',
        ];

        $responses = [
            'admin' => $this->actingAs(User::factory()->adminKepegawaian()->create())
                ->get(route('pegawai.show', $employee))
                ->assertOk(),
            'pimpinan' => $this->actingAs(User::factory()->pimpinan()->create())
                ->get(route('pimpinan.pegawai.show', $employee))
                ->assertOk(),
        ];

        foreach ($responses as $idPrefix => $response) {
            $response
                ->assertSee('data-employee-detail-shell', false)
                ->assertSee('data-employee-detail-header', false)
                ->assertSee('data-employee-detail-tabs', false)
                ->assertSee('aria-orientation="horizontal"', false)
                ->assertSee('@keydown.right.prevent="moveTab(1)"', false)
                ->assertSee('@keydown.left.prevent="moveTab(-1)"', false)
                ->assertSee('@keydown.home.prevent="selectTab(tabs[0])"', false)
                ->assertSee('@keydown.end.prevent="selectTab(tabs[tabs.length - 1])"', false)
                ->assertSeeInOrder(array_values($expectedTabs));

            foreach ($expectedTabs as $tab => $label) {
                $response
                    ->assertSee('id="'.$idPrefix.'-tab-'.$tab.'"', false)
                    ->assertSee('aria-controls="'.$idPrefix.'-panel-'.$tab.'"', false)
                    ->assertSee(':aria-selected="activeTab === \''.$tab.'\'"', false)
                    ->assertSee(':tabindex="activeTab === \''.$tab.'\' ? 0 : -1"', false)
                    ->assertSee('id="'.$idPrefix.'-panel-'.$tab.'"', false)
                    ->assertSee('aria-labelledby="'.$idPrefix.'-tab-'.$tab.'"', false)
                    ->assertSee($label);
            }

            $this->assertSame(9, substr_count($response->getContent(), 'role="tab"'));
        }

        $pimpinanResponse = $responses['pimpinan'];
        $pimpinanResponse
            ->assertDontSee('id="pimpinan-tab-supervisor"', false)
            ->assertSee('Kepala Bagian/Supervisor Aktif')
            ->assertSeeInOrder([
                'id="pimpinan-panel-profile"',
                'Kepala Bagian/Supervisor Aktif',
                'id="pimpinan-panel-keluarga"',
            ], false);
    }

    public function test_pimpinan_memakai_header_identitas_dengan_field_aman_yang_setara_admin(): void
    {
        $status = RefStatusPegawai::firstOrCreate(
            ['nama' => 'Status Profil Saja Marker'],
            [
                'kode' => 'STATUS_PROFIL_SAJA',
                'kelompok' => 'sementara',
                'keterangan' => 'Marker status yang tidak boleh menjadi badge identitas',
            ],
        );
        $employee = Employee::factory()->create([
            'foto' => 'employees/photos/paritas-header.jpg',
            'is_kinerja_baik' => true,
            'is_kepala_lembaga' => true,
            'status_pegawai_id' => $status->id,
            'status_aktif' => 'Status Profil Saja Marker',
        ])->load('jenisPegawai');
        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Dokumen Privat Bukan Foto',
            'file_path' => 'pegawai/'.$employee->id.'/dokumen-privat-bukan-foto.pdf',
        ]);

        $response = $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.pegawai.show', $employee))
            ->assertOk()
            ->assertSee('src="'.asset('storage/employees/photos/paritas-header.jpg').'"', false)
            ->assertSee($employee->jenisPegawai?->nama ?? '-')
            ->assertSee('Kinerja Baik')
            ->assertSee('Kepala Lembaga')
            ->assertDontSee('/storage/pegawai/'.$employee->id.'/dokumen-privat-bukan-foto.pdf', false)
            ->assertDontSee('Edit Pegawai');

        $identityHeader = $this->extractHtmlBetween(
            $response->getContent(),
            'data-employee-detail-header',
            'data-employee-detail-tabs',
        );

        $this->assertStringContainsString($employee->jenisPegawai?->nama ?? '-', $identityHeader);
        $this->assertStringContainsString('Kinerja Baik', $identityHeader);
        $this->assertStringContainsString('Kepala Lembaga', $identityHeader);
        $this->assertStringNotContainsString('Status Profil Saja Marker', $identityHeader);
    }

    public function test_admin_dan_pimpinan_memakai_page_header_shared_dengan_aksi_sesuai_capability(): void
    {
        $employee = Employee::factory()->create();
        $responses = [
            'admin' => $this->actingAs(User::factory()->adminKepegawaian()->create())
                ->get(route('pegawai.show', $employee))
                ->assertOk(),
            'pimpinan' => $this->actingAs(User::factory()->pimpinan()->create())
                ->get(route('pimpinan.pegawai.show', $employee))
                ->assertOk(),
        ];

        foreach ($responses as $response) {
            $response
                ->assertSee('data-employee-detail-page-header', false)
                ->assertSee('text-2xl font-extrabold text-ink tracking-tight font-sans', false)
                ->assertSeeInOrder(['Dashboard', 'Data Pegawai', 'Detail Pegawai', 'Kembali']);
        }

        $responses['admin']->assertSee('Edit Pegawai');
        $responses['pimpinan']
            ->assertDontSee('Edit Pegawai')
            ->assertDontSee(route('pegawai.edit', $employee), false);
    }

    public function test_admin_dan_pimpinan_memakai_panel_dan_tabel_presentasi_shared_dengan_header_kolom_setara(): void
    {
        $employee = Employee::factory()->create();
        $responses = [
            'admin' => $this->actingAs(User::factory()->adminKepegawaian()->create())
                ->get(route('pegawai.show', $employee))
                ->assertOk(),
            'pimpinan' => $this->actingAs(User::factory()->pimpinan()->create())
                ->get(route('pimpinan.pegawai.show', $employee))
                ->assertOk(),
        ];
        $tables = [
            'keluarga' => [
                'headings' => ['Nama Lengkap & NIK', 'Hubungan', 'TTL', 'Pekerjaan', 'Status'],
                'empty' => 'Pegawai ini belum memiliki data anggota keluarga.',
                'admin_actions' => true,
            ],
            'kepangkatan' => [
                'headings' => ['Golongan', 'Nomor SK Pangkat', 'Tanggal SK', 'TMT Pangkat'],
                'empty' => 'Pegawai ini belum memiliki riwayat kepangkatan.',
                'admin_actions' => false,
            ],
            'jabatan' => [
                'headings' => ['Nama Jabatan', 'Unit Kerja', 'Nomor SK Jabatan', 'Tanggal SK', 'TMT Jabatan'],
                'empty' => 'Pegawai ini belum memiliki riwayat jabatan.',
                'admin_actions' => false,
            ],
            'kgb' => [
                'headings' => ['Gaji Pokok Baru', 'Nomor Surat KGB', 'Tanggal Surat', 'TMT KGB'],
                'empty' => 'Pegawai ini belum memiliki riwayat KGB.',
                'admin_actions' => false,
            ],
            'disiplin' => [
                'headings' => ['Jenis Hukuman', 'Alasan / Pelanggaran', 'Nomor SK', 'Tanggal SK', 'Masa Berlaku', 'Berkas'],
                'empty' => 'Pegawai ini tidak memiliki riwayat hukuman disiplin.',
                'admin_actions' => false,
            ],
            'pendidikan' => [
                'headings' => ['Jenjang', 'Nama Institusi', 'Program Studi', 'Tahun Lulus', 'Nomor Ijazah'],
                'empty' => 'Pegawai ini belum memiliki riwayat pendidikan formal.',
                'admin_actions' => true,
            ],
            'pengangkatan' => [
                'headings' => ['Jenis Pengangkatan', 'Nomor SK Pengangkatan', 'Tanggal SK', 'TMT Pengangkatan'],
                'empty' => 'Belum ada data pengangkatan.',
                'admin_actions' => false,
            ],
            'docs' => [
                'headings' => ['Nama Dokumen', 'Kategori', 'Nomor Dokumen', 'Tanggal Terbit', 'Ukuran'],
                'empty' => 'Belum ada dokumen atau berkas yang diunggah untuk pegawai ini.',
                'admin_actions' => true,
            ],
        ];

        foreach ($responses as $surface => $response) {
            foreach (['profile', 'keluarga', 'kepangkatan', 'jabatan', 'kgb', 'disiplin', 'pendidikan', 'pengangkatan', 'docs'] as $panel) {
                $response->assertSee('data-employee-detail-panel="'.$panel.'"', false);
            }

            foreach ($tables as $name => $contract) {
                $tableHtml = html_entity_decode($this->extractDetailTable($response->getContent(), $name));
                $headings = $contract['headings'];
                $headingPositions = array_map(
                    static fn (string $heading): int|false => strpos($tableHtml, '>'.$heading.'<'),
                    $headings,
                );
                $showActions = $surface === 'admin' && $contract['admin_actions'];
                $columnCount = count($headings) + ($showActions ? 1 : 0);

                $this->assertNotContains(false, $headingPositions, "Header tabel {$name} pada {$surface} harus lengkap.");
                $this->assertSame($headingPositions, collect($headingPositions)->sort()->values()->all());
                $this->assertSame($columnCount, substr_count($tableHtml, '<th '));
                $this->assertStringContainsString($contract['empty'], $tableHtml);
                $this->assertStringContainsString('colspan="'.$columnCount.'"', $tableHtml);
                $showActions
                    ? $this->assertStringContainsString('>Aksi<', $tableHtml)
                    : $this->assertStringNotContainsString('>Aksi<', $tableHtml);
            }
        }

        $responses['pimpinan']
            ->assertDontSee('>Aksi<', false)
            ->assertDontSee('>Tambah<', false)
            ->assertDontSee('>Edit<', false)
            ->assertDontSee('>Hapus<', false)
            ->assertDontSee('>Unggah<', false)
            ->assertDontSee('>Upload<', false)
            ->assertSeeInOrder([
                'data-employee-detail-panel="profile"',
                'Kepala Bagian/Supervisor Aktif',
                'data-employee-detail-panel="keluarga"',
            ], false);
    }

    public function test_detail_pimpinan_hanya_menampilkan_assignment_efektif_current(): void
    {
        Carbon::setTestNow('2026-07-23 08:00:00');

        try {
            $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Assignment Efektif']);
            $currentSupervisor = Employee::factory()->create(['nama_lengkap' => 'Supervisor Aktif Saat Ini']);
            $futureSupervisor = Employee::factory()->create(['nama_lengkap' => 'Supervisor Mendatang']);

            // Current: sudah dimulai dan belum berakhir -> wajib tampil.
            SupervisorAssignment::create([
                'employee_id' => $employee->id,
                'supervisor_id' => $currentSupervisor->id,
                'kepala_bagian_id' => $currentSupervisor->id,
                'tanggal_mulai' => today()->subDays(5)->toDateString(),
                'tanggal_berakhir' => null,
            ]);

            // Future open-ended: belum dimulai -> tidak boleh dianggap current.
            SupervisorAssignment::create([
                'employee_id' => $employee->id,
                'supervisor_id' => $futureSupervisor->id,
                'kepala_bagian_id' => $futureSupervisor->id,
                'tanggal_mulai' => today()->addDays(10)->toDateString(),
                'tanggal_berakhir' => null,
            ]);

            $this->actingAs(User::factory()->pimpinan()->create())
                ->get(route('pimpinan.pegawai.show', $employee))
                ->assertOk()
                ->assertSee('Supervisor Aktif Saat Ini')
                ->assertDontSee('Supervisor Mendatang');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_detail_pimpinan_memasukkan_assignment_yang_berakhir_hari_ini(): void
    {
        Carbon::setTestNow('2026-07-23 08:00:00');

        try {
            $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Assignment Berakhir Hari Ini']);
            $supervisor = Employee::factory()->create(['nama_lengkap' => 'Supervisor Berakhir Hari Ini']);

            // tanggal_berakhir = hari ini bersifat inklusif -> masih tampil.
            SupervisorAssignment::create([
                'employee_id' => $employee->id,
                'supervisor_id' => $supervisor->id,
                'kepala_bagian_id' => $supervisor->id,
                'tanggal_mulai' => today()->subDays(3)->toDateString(),
                'tanggal_berakhir' => today()->toDateString(),
            ]);

            $this->actingAs(User::factory()->pimpinan()->create())
                ->get(route('pimpinan.pegawai.show', $employee))
                ->assertOk()
                ->assertSee('Supervisor Berakhir Hari Ini');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_pimpinan_detail_menampilkan_seluruh_kategori_read_only(): void
    {
        $fixture = $this->createCompleteDetailFixture();

        $response = $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.pegawai.show', $fixture['employee']));

        $response->assertOk();

        foreach ($fixture['markers'] as $marker) {
            $response->assertSee($marker);
        }

        foreach (['profile', 'keluarga', 'kepangkatan', 'jabatan', 'kgb', 'disiplin', 'pendidikan', 'pengangkatan', 'docs'] as $tab) {
            $response
                ->assertSee('id="pimpinan-tab-'.$tab.'"', false)
                ->assertSee('aria-controls="pimpinan-panel-'.$tab.'"', false)
                ->assertSee('id="pimpinan-panel-'.$tab.'"', false)
                ->assertSee('aria-labelledby="pimpinan-tab-'.$tab.'"', false);
        }
    }

    public function test_pimpinan_detail_menampilkan_tanggal_sk_dan_tmt_secara_konsisten(): void
    {
        $fixture = $this->createCompleteDetailFixture();

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.pegawai.show', $fixture['employee']))
            ->assertOk()
            ->assertSee('11-01-2020')
            ->assertSee('12-01-2020')
            ->assertSee('21-02-2021')
            ->assertSee('22-02-2021')
            ->assertSee('31-03-2022')
            ->assertSee('01-04-2022')
            ->assertSee('14-05-2023')
            ->assertSee('15-05-2023');
    }

    public function test_pimpinan_detail_menyamarkan_identitas_sensitif_dan_tidak_membawa_kontrol_mutasi(): void
    {
        $fixture = $this->createCompleteDetailFixture();

        $response = $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.pegawai.show', $fixture['employee']));

        $response->assertOk()
            ->assertSee('Tidak ditampilkan')
            ->assertDontSee('Dilindungi')
            ->assertDontSee($fixture['nik'])
            ->assertDontSee($fixture['no_kk'])
            ->assertDontSee($fixture['family_nik'])
            ->assertDontSee('Tambah Riwayat')
            ->assertDontSee('Tambah Keluarga')
            ->assertDontSee('Unggah Berkas')
            ->assertDontSee('Edit Pegawai')
            ->assertDontSee('Hapus Pegawai')
            ->assertDontSee(route('pegawai.update', $fixture['employee']), false)
            ->assertDontSee(route('pegawai.destroy', $fixture['employee']), false)
            ->assertDontSee('/api/v1/pegawai/'.$fixture['employee']->id, false);

        $this->assertSame(3, substr_count($response->getContent(), 'Tidak ditampilkan'));
    }

    public function test_pimpinan_detail_memakai_tanggal_riwayat_status_terbaru_saat_snapshot_kosong(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Fallback Status Marker',
            'status_tanggal' => null,
        ]);
        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_nama' => 'Status Lama Marker',
            'tanggal_efektif' => '2025-01-02',
            'is_latest' => false,
        ]);
        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_nama' => 'Status Terbaru Marker',
            'tanggal_efektif' => '2026-03-04',
            'is_latest' => true,
        ]);

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.pegawai.show', $employee))
            ->assertOk()
            ->assertSee('04-03-2026')
            ->assertDontSee('02-01-2025');
    }

    public function test_pimpinan_detail_menampilkan_field_profil_dan_pekerjaan_aktif_setara_admin(): void
    {
        $statusKawin = RefStatusPerkawinan::create(['nama' => 'Status Kawin Marker']);
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Nama Dasar Marker',
            'nama_dengan_gelar' => 'Dr. Nama Gelar Marker, M.Si.',
            'status_kawin_id' => $statusKawin->id,
            'golongan_darah' => 'AB',
            'no_telepon_rumah' => '0431-777-MARKER',
            'kelas_jabatan_terakhir' => '12-MARKER',
            'jabatan_terakhir' => 'Snapshot Jabatan Lama Marker',
        ]);
        $unit = RefUnitKerja::create(['nama' => 'Unit Aktif Marker']);
        PositionHistory::create([
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Jabatan Aktif Marker',
            'unit_kerja_id' => $unit->id,
            'kelas_jabatan' => '9-MARKER',
            'tmt_jabatan' => '2026-01-01',
            'is_latest' => true,
        ]);

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.pegawai.show', $employee))
            ->assertOk()
            ->assertSee('Dr. Nama Gelar Marker, M.Si.')
            ->assertSee('Status Kawin Marker')
            ->assertSee('AB')
            ->assertSee('0431-777-MARKER')
            ->assertSee('12-MARKER')
            ->assertSee('Jabatan Aktif Marker')
            ->assertSee('Unit Aktif Marker')
            ->assertDontSee('Snapshot Jabatan Lama Marker');
    }

    public function test_profil_pimpinan_memakai_snapshot_bila_tidak_ada_jabatan_berstatus_terbaru(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Snapshot Jabatan',
            'jabatan_terakhir' => 'Snapshot Jabatan Aktif',
            'kelas_jabatan_terakhir' => '9',
        ]);
        PositionHistory::create([
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Jabatan Historis Bukan Aktif',
            'tmt_jabatan' => '2020-01-01',
            'is_latest' => false,
        ]);

        $response = $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.pegawai.show', $employee))
            ->assertOk();

        $profileStart = strpos($response->getContent(), 'Informasi Pekerjaan Utama');
        $this->assertNotFalse($profileStart);
        $profile = substr($response->getContent(), $profileStart, 2500);
        $this->assertStringContainsString('Snapshot Jabatan Aktif', $profile);
        $this->assertStringNotContainsString('Jabatan Historis Bukan Aktif', $profile);
        $this->assertStringContainsString('Jabatan Historis Bukan Aktif', $response->getContent());
    }

    public function test_role_non_pimpinan_tidak_dapat_mengakses_surface_detail_pimpinan(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs(User::factory()->pegawai()->create())
            ->get(route('pimpinan.pegawai.show', $employee))
            ->assertForbidden();
    }

    public function test_permission_employees_read_tetap_diwajibkan_untuk_detail_dan_unduhan_pimpinan(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $role = Role::where('name', 'pimpinan')->firstOrFail();
        $permission = Permission::where('name', 'employees.read')->firstOrFail();
        $role->permissions()->detach($permission->id);

        $employee = Employee::factory()->create();
        $document = $this->createStoredDocument($employee, 'permission-gate.pdf');
        $pimpinan = User::factory()->pimpinan()->create();

        $this->actingAs($pimpinan)
            ->get(route('pimpinan.pegawai.show', $employee))
            ->assertForbidden();

        $this->actingAs($pimpinan)
            ->get($this->pimpinanDocumentUrl($employee, $document))
            ->assertForbidden();
    }

    public function test_pimpinan_dapat_mengunduh_dokumen_pegawai_melalui_backend_berotorisasi(): void
    {
        Storage::fake('employee_documents');
        Storage::fake('public');
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Unduhan Pimpinan']);
        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Dokumen Pimpinan',
            'file_path' => 'pegawai/dokumen-pimpinan.pdf',
        ]);
        Storage::disk('employee_documents')->put($document->file_path, 'dokumen privat');

        $response = $this->actingAs(User::factory()->pimpinan()->create())
            ->get($this->pimpinanDocumentUrl($employee, $document));

        $response->assertOk()
            ->assertDownload(Str::slug($employee->nama_lengkap).'-'.Str::slug($document->nama_dokumen).'.pdf');
        Storage::disk('public')->assertMissing($document->file_path);
    }

    public function test_pimpinan_hanya_melihat_dan_mengunduh_kategori_dokumen_yang_tidak_sensitif(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Dokumen Tersaring']);
        $sensitive = $this->createStoredDocument($employee, 'ktp-rahasia.pdf', 'ktp_kk', 'KTP Rahasia Marker');
        $allowed = $this->createStoredDocument($employee, 'ijazah-aman.pdf', 'ijazah', 'Ijazah Aman Marker');

        $pimpinan = User::factory()->pimpinan()->create();
        $this->actingAs($pimpinan)
            ->get(route('pimpinan.pegawai.show', $employee))
            ->assertOk()
            ->assertDontSee('KTP Rahasia Marker')
            ->assertDontSee($this->pimpinanDocumentUrl($employee, $sensitive), false)
            ->assertSee('Ijazah Aman Marker')
            ->assertSee($this->pimpinanDocumentUrl($employee, $allowed), false);

        $this->actingAs($pimpinan)
            ->get($this->pimpinanDocumentUrl($employee, $sensitive))
            ->assertNotFound();
        $this->actingAs($pimpinan)
            ->get($this->pimpinanDocumentUrl($employee, $allowed))
            ->assertOk()
            ->assertDownload();
    }

    public function test_pimpinan_dapat_mengunduh_sk_hukuman_disiplin_melalui_backend_berotorisasi(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = Employee::factory()->create();
        $discipline = $this->createDisciplineAttachment($employee, 'discipline-allowed.pdf');
        Storage::disk(Document::STORAGE_DISK)->put($discipline->file_sk, 'sk disiplin privat');

        $response = $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.pegawai.show', $employee));

        $response->assertOk()
            ->assertSee('Unduh SK')
            ->assertSee($this->pimpinanDisciplineUrl($employee, $discipline), false)
            ->assertDontSee('/storage/'.$discipline->file_sk, false);
        $this->actingAs(User::factory()->pimpinan()->create())
            ->get($this->pimpinanDisciplineUrl($employee, $discipline))
            ->assertOk()
            ->assertDownload();
    }

    public function test_unduhan_sk_disiplin_pimpinan_fail_closed_untuk_cross_owner_uuid_malformed_dan_file_hilang(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $otherDiscipline = $this->createDisciplineAttachment($otherEmployee, 'discipline-other.pdf');
        Storage::disk(Document::STORAGE_DISK)->put($otherDiscipline->file_sk, 'sk milik pegawai lain');
        $missing = $this->createDisciplineAttachment($employee, 'discipline-missing.pdf');
        $pimpinan = User::factory()->pimpinan()->create();

        $this->actingAs($pimpinan)
            ->get($this->pimpinanDisciplineUrl($employee, $otherDiscipline))
            ->assertNotFound();
        $this->actingAs($pimpinan)
            ->get(route('pimpinan.pegawai.discipline-attachments.download', [
                'employee' => $employee->id,
                'history' => 'not-a-uuid',
            ]))
            ->assertNotFound();
        $this->actingAs($pimpinan)
            ->get($this->pimpinanDisciplineUrl($employee, $missing))
            ->assertNotFound();
    }

    public function test_unduhan_sk_disiplin_pimpinan_memerlukan_permission_employees_read(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = Employee::factory()->create();
        $discipline = $this->createDisciplineAttachment($employee, 'discipline-permission.pdf');
        Storage::disk(Document::STORAGE_DISK)->put($discipline->file_sk, 'sk disiplin');
        $role = Role::where('name', 'pimpinan')->firstOrFail();
        $permission = Permission::where('name', 'employees.read')->firstOrFail();
        $role->permissions()->detach($permission->id);

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get($this->pimpinanDisciplineUrl($employee, $discipline))
            ->assertForbidden();
    }

    public function test_rute_unduhan_pimpinan_menolak_uuid_dokumen_malformed(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.pegawai.documents.download', [
                'employee' => $employee->id,
                'document' => 'not-a-uuid',
            ]))
            ->assertNotFound();
    }

    public function test_status_nonaktif_tidak_memakai_badge_hijau_status_aktif(): void
    {
        $status = RefStatusPegawai::where('nama', 'Nonaktif')->firstOrFail();
        $employee = Employee::factory()->create([
            'status_pegawai_id' => $status->id,
            'status_aktif' => 'Nonaktif',
        ]);

        $response = $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.pegawai.show', $employee));

        $response->assertOk()
            ->assertSee('Nonaktif')
            ->assertSee('bg-muted/10 text-muted', false);

        // Badge Kinerja Baik boleh hijau; assertion status harus dibatasi pada kartu status kepegawaian.
        $statusCard = $this->extractHtmlBetween(
            $response->getContent(),
            'Status Kepegawaian',
            'Kontak &amp; Rumah',
        );
        $this->assertStringContainsString('bg-muted/10 text-muted', $statusCard);
        $this->assertStringNotContainsString('bg-success/10 text-success', $statusCard);
    }

    public function test_role_tanpa_hak_ditolak_mengunduh_dokumen_dari_surface_pimpinan(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = Employee::factory()->create();
        $document = $this->createStoredDocument($employee, 'dokumen-terlarang.pdf');

        $this->actingAs(User::factory()->pegawai()->create())
            ->get($this->pimpinanDocumentUrl($employee, $document))
            ->assertForbidden();
    }

    public function test_unduhan_dokumen_pimpinan_mengembalikan_404_ketika_file_hilang(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = Employee::factory()->create();
        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Dokumen Hilang',
            'file_path' => 'pegawai/file-hilang.pdf',
        ]);

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get($this->pimpinanDocumentUrl($employee, $document))
            ->assertNotFound();
    }

    public function test_detail_pimpinan_tidak_mengekspos_url_storage_publik(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = Employee::factory()->create();
        $document = $this->createStoredDocument($employee, 'rahasia-marker.pdf');

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.pegawai.show', $employee))
            ->assertOk()
            ->assertSee($this->pimpinanDocumentUrl($employee, $document), false)
            ->assertDontSee('/storage/'.$document->file_path, false);
    }

    public function test_unduhan_dokumen_pimpinan_menolak_dokumen_milik_pegawai_lain(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $targetEmployee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $document = $this->createStoredDocument($otherEmployee, 'dokumen-pegawai-lain.pdf');

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get($this->pimpinanDocumentUrl($targetEmployee, $document))
            ->assertNotFound();
    }

    /**
     * Menyediakan marker unik agar satu respons membuktikan seluruh kategori benar-benar berasal dari database.
     *
     * @return array{employee: Employee, markers: list<string>, nik: string, no_kk: string, family_nik: string}
     */
    private function createCompleteDetailFixture(): array
    {
        $status = RefStatusPegawai::firstOrCreate(
            ['nama' => 'Tugas Belajar Marker'],
            [
                'kode' => 'TUGAS_BELAJAR_MARKER',
                'kelompok' => 'sementara',
                'keterangan' => 'Status efektif marker',
            ],
        );
        $nik = '7171010101010099';
        $noKk = '7171010101010088';
        $familyNik = '7171010101010077';
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Profil Marker Pimpinan',
            'nik' => $nik,
            'no_kk' => $noKk,
            'email_pribadi' => 'kontak-marker@example.test',
            'no_hp' => '081234567890',
            'alamat' => 'Alamat Kontak Marker',
            'status_pegawai_id' => $status->id,
            'status_keterangan' => 'Keterangan Status Efektif Marker',
            'status_tanggal' => '2026-06-07',
        ]);

        EmployeeFamily::create([
            'employee_id' => $employee->id,
            'nama_anggota' => 'Keluarga Marker Pimpinan',
            'hubungan' => 'Anak',
            'nik' => $familyNik,
            'tanggal_lahir' => '2010-01-01',
            'jenis_kelamin' => 'L',
            'status_tunjangan' => true,
        ]);

        $jenjang = RefJenjangPendidikan::create(['nama' => 'S2 Marker', 'urutan' => 8]);
        EducationHistory::create([
            'employee_id' => $employee->id,
            'jenjang_id' => $jenjang->id,
            'nama_institusi' => 'Universitas Pendidikan Marker',
            'jurusan' => 'Administrasi Marker',
            'tahun_lulus' => 2020,
            'no_ijazah' => 'IJAZAH-MARKER-01',
        ]);

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'no_sk' => 'SK-ANGKAT-MARKER',
            'tanggal_sk' => '2020-01-11',
            'tmt_pengangkatan' => '2020-01-12',
        ]);

        $golongan = RefGolongan::create(['kode' => 'IV/Z', 'nama' => 'Golongan Pangkat Marker', 'urutan' => 99]);
        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'no_sk' => 'SK-PANGKAT-MARKER',
            'tanggal_sk' => '2021-02-21',
            'tmt_pangkat' => '2021-02-22',
            'is_latest' => true,
        ]);

        $unit = RefUnitKerja::create(['nama' => 'Unit Jabatan Marker']);
        $jenisJabatan = RefJenisJabatan::create(['nama' => 'Jenis Jabatan Marker', 'maks_usia_pensiun' => 60]);
        PositionHistory::create([
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Jabatan Riwayat Marker',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'unit_kerja_id' => $unit->id,
            'no_sk' => 'SK-JABATAN-MARKER',
            'tanggal_sk' => '2022-03-31',
            'tmt_jabatan' => '2022-04-01',
            'is_latest' => true,
        ]);

        SalaryHistory::create([
            'employee_id' => $employee->id,
            'gaji_pokok' => 7654321,
            'no_sk' => 'SK-KGB-MARKER',
            'tanggal_sk' => '2023-05-14',
            'tmt_kgb' => '2023-05-15',
            'is_latest' => true,
        ]);

        DisciplineRecord::create([
            'employee_id' => $employee->id,
            'jenis_hukuman' => 'Ringan',
            'deskripsi' => 'Deskripsi Disiplin Marker',
            'tanggal_mulai' => '2024-01-01',
            'tanggal_berakhir' => '2024-02-01',
            'no_sk' => 'SK-DISIPLIN-MARKER',
            'tanggal_sk' => '2023-12-31',
            'is_active' => false,
        ]);

        $supervisor = Employee::factory()->create([
            'nama_lengkap' => 'Kepala Bagian Aktif Marker',
            'jabatan_terakhir' => 'Kepala Bagian Marker',
        ]);
        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'supervisor_id' => $supervisor->id,
            'kepala_bagian_id' => $supervisor->id,
            'tanggal_mulai' => '2025-01-02',
        ]);

        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Dokumen Metadata Marker',
            'nomor_dokumen' => 'DOC-MARKER-01',
            'tanggal_dokumen' => '2025-06-01',
            'file_path' => 'pegawai/dokumen-marker.pdf',
            'keterangan' => 'Keterangan Dokumen Marker',
        ]);

        return [
            'employee' => $employee,
            'markers' => [
                'Profil Marker Pimpinan',
                'kontak-marker@example.test',
                'Keterangan Status Efektif Marker',
                'Keluarga Marker Pimpinan',
                'Universitas Pendidikan Marker',
                'SK-ANGKAT-MARKER',
                'Golongan Pangkat Marker',
                'Jabatan Riwayat Marker',
                '7.654.321',
                'Deskripsi Disiplin Marker',
                'Kepala Bagian Aktif Marker',
                'Dokumen Metadata Marker',
                'DOC-MARKER-01',
            ],
            'nik' => $nik,
            'no_kk' => $noKk,
            'family_nik' => $familyNik,
        ];
    }

    private function createStoredDocument(
        Employee $employee,
        string $fileName,
        string $category = 'lainnya',
        string $name = 'Dokumen Unduhan Pimpinan',
    ): Document {
        $filePath = 'pegawai/'.$employee->id.'/'.$fileName;
        Storage::disk(Document::STORAGE_DISK)->put($filePath, 'isi dokumen pengujian');

        return Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => $category,
            'nama_dokumen' => $name,
            'nomor_dokumen' => 'DOC-PIMPINAN-DL',
            'tanggal_dokumen' => '2026-08-01',
            'file_path' => $filePath,
        ]);
    }

    private function extractDetailTable(string $html, string $name): string
    {
        $start = strpos($html, 'data-employee-detail-table="'.$name.'"');
        $this->assertNotFalse($start, "Marker tabel {$name} wajib tersedia.");
        $end = strpos($html, '</table>', $start);
        $this->assertNotFalse($end, "Penutup tabel {$name} wajib tersedia.");

        return substr($html, $start, $end + strlen('</table>') - $start);
    }

    private function extractHtmlBetween(string $html, string $startMarker, string $endMarker): string
    {
        $start = strpos($html, $startMarker);
        $this->assertNotFalse($start, "Marker awal {$startMarker} wajib tersedia.");
        $end = strpos($html, $endMarker, $start);
        $this->assertNotFalse($end, "Marker akhir {$endMarker} wajib tersedia.");

        return substr($html, $start, $end - $start);
    }

    private function pimpinanDocumentUrl(Employee $employee, Document $document): string
    {
        return '/pimpinan/pegawai/'.$employee->id.'/dokumen/'.$document->id.'/unduh';
    }

    private function createDisciplineAttachment(Employee $employee, string $path): DisciplineRecord
    {
        return DisciplineRecord::create([
            'employee_id' => $employee->id,
            'jenis_hukuman' => 'Ringan',
            'deskripsi' => 'Fixture unduhan Pimpinan',
            'tanggal_mulai' => '2026-01-01',
            'no_sk' => 'SK-DIS-PIMPINAN',
            'tanggal_sk' => '2025-12-31',
            'file_sk' => $path,
        ]);
    }

    private function pimpinanDisciplineUrl(Employee $employee, DisciplineRecord $discipline): string
    {
        return '/pimpinan/pegawai/'.$employee->id.'/hukuman-disiplin/'.$discipline->id.'/unduh';
    }
}
