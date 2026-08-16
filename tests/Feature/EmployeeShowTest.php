<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Models\EmployeeStatusHistory;
use App\Models\EwsAlert;
use App\Models\EwsConfig;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefAgama;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisCuti;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefJenjangPendidikan;
use App\Models\RefProgramStudi;
use App\Models\RefStatusPegawai;
use App\Models\RefStatusPerkawinan;
use App\Models\RefUnitKerja;
use App\Models\Role;
use App\Models\SalaryHistory;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeeShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(ReferenceSeeder::class);
    }

    public function test_admin_kepegawaian_can_show_employee_detail_by_id(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->employeeWithReferences(['nama_lengkap' => 'Siti Aminah']);

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Detail pegawai berhasil diambil.')
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('employee.nama_lengkap', 'Siti Aminah')
            ->assertJsonPath('employee.jenis_pegawai.nama', 'PNS')
            ->assertJsonPath('employee.agama.nama', 'Islam')
            ->assertJsonPath('employee.status_kawin.nama', 'Menikah');
    }

    public function test_super_admin_can_show_employee_detail_by_id(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = $this->employeeWithReferences();

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}");

        $response->assertOk()
            ->assertJsonPath('employee.id', $employee->id);
    }

    public function test_detail_admin_fallback_ke_profil_saat_query_tab_legacy_atau_tidak_valid(): void
    {
        $employee = $this->employeeWithReferences();
        $admin = User::factory()->adminKepegawaian()->create();

        foreach (['info', 'tab-tidak-valid'] as $invalidTab) {
            $this->actingAs($admin)
                ->get(route('pegawai.show', $employee).'?tab='.$invalidTab)
                ->assertOk()
                ->assertSee("activeTab: 'profile'", false);
        }
    }

    public function test_detail_admin_mempertahankan_query_tab_yang_valid(): void
    {
        $employee = $this->employeeWithReferences();

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee).'?tab=keluarga')
            ->assertOk()
            ->assertSee("activeTab: 'keluarga'", false);
    }

    public function test_employee_detail_page_prioritizes_manual_retirement_date_and_falls_back_to_bup(): void
    {
        EwsConfig::setVal('pensiun_required_age_years', '60');
        $user = User::factory()->adminKepegawaian()->create();

        // 1. Employee with manual date
        $employeeWithManual = $this->employeeWithReferences([
            'tanggal_lahir' => '1970-01-01',
            'tanggal_pensiun' => '2042-05-15',
        ]);

        $this->actingAs($user)
            ->get(route('pegawai.show', $employeeWithManual->id))
            ->assertOk()
            ->assertSee('15-05-2042', false)
            ->assertDontSee('01-01-2030', false);

        // 2. Employee without manual date (falls back to BUP)
        $employeeWithBup = $this->employeeWithReferences([
            'tanggal_lahir' => '1970-01-01',
            'tanggal_pensiun' => null,
        ]);

        $this->actingAs($user)
            ->get(route('pegawai.show', $employeeWithBup->id))
            ->assertOk()
            ->assertSee('01-01-2030', false);
    }

    public function test_employee_detail_page_uses_persisted_promotion_and_kgb_snapshots(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->employeeWithReferences([
            'tanggal_kenaikan_pangkat_berikutnya' => '2035-06-15',
            'tanggal_kgb_berikutnya' => null,
        ]);

        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => RefGolongan::where('kode', 'III/a')->firstOrFail()->id,
            'tmt_pangkat' => '2020-04-01',
            'is_latest' => true,
        ]);
        SalaryHistory::create([
            'employee_id' => $employee->id,
            'tmt_kgb' => '2020-01-01',
            'gaji_pokok' => 5000000,
            'is_latest' => true,
        ]);

        $this->actingAs($user)
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('15-06-2035', false)
            ->assertDontSee('01-04-2024', false)
            ->assertDontSee('01-01-2022', false);
    }

    public function test_detail_admin_tidak_memakai_riwayat_non_latest_sebagai_snapshot_pekerjaan(): void
    {
        $employee = $this->employeeWithReferences([
            'pangkat_terakhir' => 'Pangkat Snapshot Marker',
            'golongan_terakhir' => 'GOL-SNAPSHOT',
            'jabatan_terakhir' => 'Jabatan Snapshot Marker',
        ]);
        $historicalRank = RefGolongan::create([
            'kode' => 'HIST/Z',
            'nama' => 'Pangkat Historis Nonlatest Marker',
            'urutan' => 999,
        ]);
        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $historicalRank->id,
            'tmt_pangkat' => '2099-01-01',
            'no_sk' => 'SK-HIST-RANK-NONLATEST',
            'tanggal_sk' => '2098-12-01',
            'is_latest' => false,
        ]);
        PositionHistory::create([
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Jabatan Historis Nonlatest Marker',
            'jenis_jabatan_id' => RefJenisJabatan::where('nama', 'Struktural')->firstOrFail()->id,
            'unit_kerja_id' => RefUnitKerja::where('nama', 'Kepala Bagian Umum')->firstOrFail()->id,
            'tmt_jabatan' => '2099-01-01',
            'no_sk' => 'SK-HIST-POSITION-NONLATEST',
            'tanggal_sk' => '2098-12-01',
            'is_latest' => false,
        ]);

        $html = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee))
            ->assertOk()
            ->getContent();
        $profileStart = strpos($html, 'data-employee-detail-panel="profile"');
        $familyStart = strpos($html, 'data-employee-detail-panel="keluarga"', $profileStart ?: 0);
        $this->assertNotFalse($profileStart);
        $this->assertNotFalse($familyStart);
        $profileHtml = substr($html, $profileStart, $familyStart - $profileStart);

        $this->assertStringContainsString('Pangkat Snapshot Marker', $profileHtml);
        $this->assertStringContainsString('GOL-SNAPSHOT', $profileHtml);
        $this->assertStringContainsString('Jabatan Snapshot Marker', $profileHtml);
        $this->assertStringNotContainsString('Pangkat Historis Nonlatest Marker', $profileHtml);
        $this->assertStringNotContainsString('Jabatan Historis Nonlatest Marker', $profileHtml);
    }

    public function test_detail_page_menampilkan_kontrol_kepala_bagian_hanya_bila_role_dan_permission_memenuhi_syarat(): void
    {
        $employee = $this->employeeWithReferences();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('Ubah Kepala Bagian', false)
            ->assertSee(route('pegawai.assign-atasan', $employee->id), false);

        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.update')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertDontSee('Ubah Kepala Bagian', false);
    }

    public function test_detail_page_membedakan_tanggal_status_dan_tanggal_mulai_penugasan_kepala_bagian(): void
    {
        $employee = $this->employeeWithReferences([
            'status_tanggal' => '2026-01-15',
        ]);
        $supervisor = $this->employeeWithReferences([
            'nama_lengkap' => 'Kepala Bagian Penguji',
        ]);

        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisor->id,
            'tanggal_mulai' => '2026-07-20',
            'tanggal_berakhir' => null,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('Tanggal Mulai Penugasan Kepala Bagian', false)
            ->assertSee('Mulai Penugasan', false)
            ->assertSee('20-07-2026', false)
            ->assertSeeInOrder([
                'Tanggal Efektif Status Kepegawaian',
                '15-01-2026',
            ], false);
    }

    public function test_detail_page_memakai_history_terbaru_saat_snapshot_tanggal_status_kosong(): void
    {
        $employee = $this->employeeWithReferences([
            'status_tanggal' => null,
        ]);
        $status = RefStatusPegawai::query()->where('nama', 'Aktif')->firstOrFail();

        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_pegawai_id' => $status->id,
            'status_nama' => $status->nama,
            'tanggal_efektif' => '2025-02-14',
            'is_latest' => true,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSeeInOrder([
                'Tanggal Efektif Status Kepegawaian',
                '14-02-2025',
            ], false);
    }

    public function test_detail_page_memprioritaskan_history_berflag_latest_sebelum_tanggal_terbaru(): void
    {
        $employee = $this->employeeWithReferences([
            'status_tanggal' => null,
        ]);
        $status = RefStatusPegawai::query()->where('nama', 'Aktif')->firstOrFail();

        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_pegawai_id' => $status->id,
            'status_nama' => $status->nama,
            'tanggal_efektif' => '2025-02-14',
            'is_latest' => true,
        ]);
        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_pegawai_id' => $status->id,
            'status_nama' => $status->nama,
            'tanggal_efektif' => '2026-04-21',
            'is_latest' => false,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSeeInOrder([
                'Tanggal Efektif Status Kepegawaian',
                '14-02-2025',
            ], false);
    }

    public function test_detail_page_memakai_tanggal_history_terbaru_untuk_data_legacy_tanpa_flag_latest(): void
    {
        $employee = $this->employeeWithReferences([
            'status_tanggal' => null,
        ]);
        $status = RefStatusPegawai::query()->where('nama', 'Aktif')->firstOrFail();

        foreach (['2024-03-12', '2025-11-08'] as $tanggalEfektif) {
            EmployeeStatusHistory::create([
                'employee_id' => $employee->id,
                'status_pegawai_id' => $status->id,
                'status_nama' => $status->nama,
                'tanggal_efektif' => $tanggalEfektif,
                'is_latest' => false,
            ]);
        }

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSeeInOrder([
                'Tanggal Efektif Status Kepegawaian',
                '08-11-2025',
            ], false);
    }

    public function test_detail_admin_merender_riwayat_status_dengan_urutan_deterministik(): void
    {
        $employee = $this->employeeWithReferences();

        // Urutan insert sengaja kebalikan dari aturan tampilan agar query, bukan urutan fisik DB, yang menentukan hasil.
        $this->createStatusHistory($employee, 'Status Tie Rendah Admin', false, '2026-06-01', '2026-06-10 08:00:00', '10000000-0000-4000-8000-000000000002');
        $this->createStatusHistory($employee, 'Status Tie Tinggi Admin', false, '2026-06-01', '2026-06-10 08:00:00', '10000000-0000-4000-8000-000000000003');
        $this->createStatusHistory($employee, 'Status Dibuat Terbaru Admin', false, '2026-06-01', '2026-06-10 09:00:00', '10000000-0000-4000-8000-000000000001');
        $this->createStatusHistory($employee, 'Status Tanggal Terbaru Admin', false, '2026-08-01', '2026-06-10 07:00:00', '10000000-0000-4000-8000-000000000004');
        $this->createStatusHistory($employee, 'Status Flag Latest Admin', true, '2025-01-01', '2026-06-10 06:00:00', '10000000-0000-4000-8000-000000000005');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('pegawai.show', $employee))
            ->assertOk()
            ->assertSeeInOrder([
                'Status Flag Latest Admin',
                'Status Tanggal Terbaru Admin',
                'Status Dibuat Terbaru Admin',
                'Status Tie Rendah Admin',
                'Status Tie Tinggi Admin',
            ], false);
    }

    public function test_detail_admin_merender_dan_mengunduh_dokumen_status_legacy_milik_pegawai(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = $this->employeeWithReferences();
        $history = EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_nama' => 'Status Legacy Dengan Dokumen',
            'tanggal_efektif' => '2026-08-01',
            'nomor_berkas' => 'SK-STATUS-LEGACY-ADMIN',
            'is_latest' => true,
        ]);
        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_status_pegawai',
            'nama_dokumen' => 'SK Status Legacy',
            'nomor_dokumen' => 'SK-STATUS-LEGACY-ADMIN',
            'file_path' => 'pegawai/status-legacy-admin.pdf',
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($document->file_path, 'status legacy privat');
        $url = route('pegawai.history-attachments.download', [
            'employee' => $employee,
            'type' => 'status',
            'history' => $history,
        ]);
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get(route('pegawai.show', $employee))
            ->assertOk()
            ->assertSee($url, false)
            ->assertDontSee($document->file_path, false)
            ->assertDontSee('/storage/', false);

        $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertDownload();
    }

    public function test_detail_admin_memilih_kandidat_dokumen_status_legacy_tersedia_pertama_secara_konsisten(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = $this->employeeWithReferences();
        $history = EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_nama' => 'Status Legacy Duplikat',
            'tanggal_efektif' => '2026-08-01',
            'nomor_berkas' => 'SK-STATUS-LEGACY-DUPLIKAT',
            'is_latest' => true,
        ]);
        $missingDocument = new Document;
        $missingDocument->id = '30000000-0000-4000-8000-000000000001';
        $missingDocument->forceFill([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_status_pegawai',
            'nama_dokumen' => 'SK Status Lama Hilang',
            'nomor_dokumen' => 'SK-STATUS-LEGACY-DUPLIKAT',
            'file_path' => 'pegawai/status-legacy-hilang.pdf',
        ])->save();
        $availableDocument = new Document;
        $availableDocument->id = '30000000-0000-4000-8000-000000000002';
        $availableDocument->forceFill([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_status_pegawai',
            'nama_dokumen' => 'SK Status Lama Tersedia',
            'nomor_dokumen' => 'SK-STATUS-LEGACY-DUPLIKAT',
            'file_path' => 'pegawai/status-legacy-tersedia.pdf',
        ])->save();
        Storage::disk(Document::STORAGE_DISK)->put($availableDocument->file_path, 'status legacy tersedia');
        $url = route('pegawai.history-attachments.download', [
            'employee' => $employee,
            'type' => 'status',
            'history' => $history,
        ]);
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get(route('pegawai.show', $employee))
            ->assertOk()
            ->assertSee($url, false);

        $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertDownload();
    }

    public function test_detail_page_tidak_mengarang_tanggal_status_tanpa_sumber_resmi(): void
    {
        $employee = $this->employeeWithReferences([
            'status_tanggal' => null,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSeeInOrder([
                'Tanggal Efektif Status Kepegawaian',
                '-',
            ], false);
    }

    public function test_detail_page_menyediakan_form_penghapusan_kepala_bagian_yang_eksplisit(): void
    {
        $employee = $this->employeeWithReferences();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('id="assign-kepala-bagian-form"', false)
            ->assertSee('Hapus Kepala Bagian lalu simpan', false)
            ->assertSee('requestSubmit()', false);
    }

    public function test_detail_page_mencegah_submit_nama_kepala_bagian_tanpa_kandidat_terpilih(): void
    {
        $employee = $this->employeeWithReferences();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('@submit="validateSupervisorSelection($event)"', false)
            ->assertSee('Pilih kandidat Kepala Bagian dari hasil pencarian sebelum menyimpan.', false)
            ->assertSee('role="alert"', false)
            ->assertSee('supervisorClearConfirmed', false)
            ->assertSee('text-xs text-muted font-sans', false);
    }

    public function test_detail_page_memulihkan_label_kepala_bagian_dari_id_tervalidasi_bukan_input_browser(): void
    {
        $employee = $this->employeeWithReferences();
        $candidate = $this->employeeWithReferences(['nama_lengkap' => 'Kandidat Tersimpan']);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->withSession(['_old_input' => [
                'kepala_bagian_id' => $candidate->id,
                'kepala_bagian_label' => '<script>window.xss = true</script>',
            ]])
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('Kandidat Tersimpan', false)
            ->assertDontSee('window.xss = true', false);
    }

    public function test_detail_page_menampilkan_kontrol_tambah_riwayat_untuk_admin_yang_berizin(): void
    {
        $employee = $this->employeeWithReferences();

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('Tambah Riwayat Kepangkatan', false)
            ->assertSee('Tambah Riwayat Jabatan', false)
            ->assertSee('Tambah Riwayat KGB', false);
    }

    public function test_detail_page_menyembunyikan_kontrol_tambah_riwayat_tanpa_permission(): void
    {
        $employee = $this->employeeWithReferences();
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employee_histories.create')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertDontSee('Tambah Riwayat Kepangkatan', false)
            ->assertDontSee('Tambah Riwayat Jabatan', false)
            ->assertDontSee('Tambah Riwayat KGB', false);
    }

    public function test_detail_page_uses_created_history_payload_for_rank_and_position_rows(): void
    {
        $employee = $this->employeeWithReferences();

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee("golongan: h.golongan?.nama ?? '-',", false)
            ->assertSee("jabatan: h.jabatan?.nama ?? h.nama_jabatan ?? '-',", false)
            ->assertSee("unit: h.unit_kerja?.nama ?? '-',", false);
    }

    public function test_detail_page_formats_position_history_dates_in_table(): void
    {
        $employee = $this->employeeWithReferences();

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('x-text="formatDate(j.tgl_sk)"', false)
            ->assertSee('x-text="formatDate(j.tmt)"', false);
    }

    public function test_detail_page_formats_kgb_history_dates_in_table(): void
    {
        $employee = $this->employeeWithReferences();

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('x-text="formatDate(k.tgl_sk)"', false)
            ->assertSee('x-text="formatDate(k.tmt)"', false);
    }

    public function test_detail_page_mempertahankan_tanggal_kalender_kgb_pada_payload_awal_dan_baris_baru(): void
    {
        $employee = $this->employeeWithReferences();
        SalaryHistory::create([
            'employee_id' => $employee->id,
            'gaji_pokok' => 5000000,
            'no_sk' => 'SK-KGB-KALENDER',
            'tanggal_sk' => '2026-01-10',
            'tmt_kgb' => '2026-01-15',
            'is_latest' => true,
        ]);

        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk();

        $payload = $this->extractAlpineList($response->getContent(), 'kgbList', 'disiplinList');
        $row = collect($payload)->firstWhere('no_sk', 'SK-KGB-KALENDER');
        $this->assertIsArray($row);
        // Payload awal harus mempertahankan date-only DB tanpa konversi UTC yang menggeser hari.
        $this->assertSame('2026-01-10', $row['tgl_sk']);
        $this->assertSame('2026-01-15', $row['tmt']);

        $response
            // Baris hasil create tetap memakai tanggal date-only dari input/API yang sama.
            ->assertSee('tgl_sk: this.newKgb.tanggal_sk,', false)
            ->assertSee('tmt: this.newKgb.tmt_kgb', false);
    }

    public function test_detail_page_serializes_official_history_dates_without_timezone_shift(): void
    {
        config(['app.timezone' => 'Asia/Makassar']);

        $employee = $this->employeeWithReferences();
        $jenisJabatan = RefJenisJabatan::where('nama', 'Struktural')->firstOrFail();
        $jabatan = RefJabatan::firstOrCreate(
            ['nama' => 'Analis Payload Date-only'],
            ['jenis_jabatan_id' => $jenisJabatan->id, 'is_active' => true],
        );
        $unitKerja = RefUnitKerja::firstOrFail();

        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => RefGolongan::where('kode', 'III/b')->firstOrFail()->id,
            'tmt_pangkat' => '2026-09-22',
            'no_sk' => 'SK-PANGKAT-DATE-ONLY',
            'tanggal_sk' => '2026-09-22',
            'is_latest' => true,
        ]);
        PositionHistory::create([
            'employee_id' => $employee->id,
            'jabatan_id' => $jabatan->id,
            'nama_jabatan' => $jabatan->nama,
            'jenis_jabatan_id' => $jenisJabatan->id,
            'unit_kerja_id' => $unitKerja->id,
            'tmt_jabatan' => '2026-09-22',
            'no_sk' => 'SK-JABATAN-DATE-ONLY',
            'tanggal_sk' => '2026-09-22',
            'is_latest' => true,
        ]);
        SalaryHistory::create([
            'employee_id' => $employee->id,
            'tmt_kgb' => '2026-09-22',
            'gaji_pokok' => 4500000,
            'no_sk' => 'SK-KGB-DATE-ONLY',
            'tanggal_sk' => '2026-09-22',
            'is_latest' => true,
        ]);

        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk();
        $content = $response->getContent();

        $this->assertMatchesRegularExpression('/SK-PANGKAT-DATE-ONLY.{0,160}2026-09-22.{0,100}2026-09-22/s', $content);
        $this->assertMatchesRegularExpression('/SK-JABATAN-DATE-ONLY.{0,160}2026-09-22.{0,100}2026-09-22/s', $content);
        $this->assertMatchesRegularExpression('/SK-KGB-DATE-ONLY.{0,160}2026-09-22.{0,100}2026-09-22/s', $content);
        $this->assertStringNotContainsString('2026-09-21T16:00:00', $content);
        $this->assertStringNotContainsString('2026-09-22T00:00:00', $content);
    }

    public function test_detail_page_preserves_initial_discipline_period_fields_for_table(): void
    {
        $employee = $this->employeeWithReferences();
        DisciplineRecord::create([
            'employee_id' => $employee->id,
            'jenis_hukuman' => 'Sedang',
            'deskripsi' => 'Pelanggaran uji periode',
            'tanggal_mulai' => '2026-02-01',
            'tanggal_berakhir' => '2026-03-01',
            'no_sk' => 'SK-DISIPLIN-PERIODE',
            'tanggal_sk' => '2026-01-20',
            'is_active' => false,
        ]);

        $content = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/SK-DISIPLIN-PERIODE.{0,200}&quot;tgl_mulai&quot;:&quot;2026-02-01&quot;.{0,80}&quot;tgl_akhir&quot;:&quot;2026-03-01&quot;/s',
            $content,
        );
    }

    public function test_detail_page_versions_education_cache_after_program_studi_relation_is_added(): void
    {
        $employee = $this->employeeWithReferences();

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee("pendidikan_v2_{$employee->id}", false)
            ->assertDontSee("pendidikan_{$employee->id}", false);
    }

    public function test_detail_page_disciplines_use_normalized_dates_and_protected_download_url(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = $this->employeeWithReferences();
        $record = DisciplineRecord::create([
            'employee_id' => $employee->id,
            'jenis_hukuman' => 'Ringan',
            'deskripsi' => 'Pelanggaran tanggal kalender',
            'tanggal_mulai' => '2026-09-22',
            'tanggal_berakhir' => '2026-10-22',
            'no_sk' => 'SK-DISIPLIN-DATE-ONLY',
            'tanggal_sk' => '2026-09-22',
            'file_sk' => 'sk/disiplin-date-only.pdf',
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($record->file_sk, 'sk disiplin privat');

        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('x-text="formatDate(d.tgl_sk)"', false)
            ->assertSee('x-text="formatDate(d.tgl_mulai) + \' s/d \' + (d.tgl_akhir ? formatDate(d.tgl_akhir) : \'Sekarang\')"', false)
            ->assertSee('download_url: r.download_url', false);

        $payload = $this->extractAlpineList($response->getContent(), 'disiplinList', 'pendidikanList');
        $row = collect($payload)->firstWhere('no_sk', 'SK-DISIPLIN-DATE-ONLY');
        $this->assertIsArray($row);
        $this->assertSame('2026-09-22', $row['tgl_sk']);
        $this->assertSame('2026-09-22', $row['tgl_mulai']);
        $this->assertSame('2026-10-22', $row['tgl_akhir']);
        $this->assertSame(route('pegawai.history-attachments.download', [
            'employee' => $employee,
            'type' => 'discipline',
            'history' => $record,
        ]), $row['download_url']);
    }

    public function test_detail_page_preserves_education_download_url_after_metadata_update(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $content = $this->actingAs($admin)
            ->get(route('pegawai.show', $employee))
            ->assertOk()
            ->getContent();
        $updateHandler = Str::between(
            $content,
            'async submitEditPendidikan() {',
            'async deletePendidikan(id, index) {',
        );

        $this->assertStringContainsString('download_url: h.download_url', $updateHandler);
    }

    public function test_detail_page_payload_program_studi_mempertahankan_url_unduh_privat(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = $this->employeeWithReferences();
        $programStudi = RefProgramStudi::create(['nama' => 'Administrasi Publik Detail']);
        $history = EducationHistory::create([
            'employee_id' => $employee->id,
            'jenjang_id' => RefJenjangPendidikan::where('nama', 'D4 / S1')->firstOrFail()->id,
            'program_studi_id' => $programStudi->id,
            'nama_institusi' => 'Universitas Detail',
            'tahun_lulus' => 2024,
            'no_ijazah' => 'IJAZAH-PRODI-DOWNLOAD',
            'file_ijazah' => 'ijazah/program-studi-detail.pdf',
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($history->file_ijazah, 'ijazah privat');

        $content = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee))
            ->assertOk()
            ->getContent();
        $row = collect($this->extractAlpineList($content, 'pendidikanList', 'pendidikanLoading'))
            ->firstWhere('no_ijazah', 'IJAZAH-PRODI-DOWNLOAD');

        $this->assertIsArray($row);
        $this->assertSame($programStudi->id, $row['program_studi_id']);
        $this->assertSame($programStudi->nama, $row['prodi']);
        $this->assertSame(route('pegawai.history-attachments.download', [
            'employee' => $employee,
            'type' => 'education',
            'history' => $history,
        ]), $row['download_url']);
    }

    public function test_detail_admin_tidak_merender_tautan_attachment_yang_file_privatnya_hilang(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = $this->employeeWithReferences();
        $rank = RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => RefGolongan::where('kode', 'III/a')->firstOrFail()->id,
            'tmt_pangkat' => '2026-01-01',
            'file_sk' => 'sk/admin-rank-hilang.pdf',
            'is_latest' => true,
        ]);
        $position = PositionHistory::create([
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Jabatan File Hilang',
            'tmt_jabatan' => '2026-01-01',
            'file_sk' => 'sk/admin-position-hilang.pdf',
            'is_latest' => true,
        ]);
        $salary = SalaryHistory::create([
            'employee_id' => $employee->id,
            'gaji_pokok' => 4500000,
            'tmt_kgb' => '2026-01-01',
            'file_sk' => 'sk/admin-salary-hilang.pdf',
            'is_latest' => true,
        ]);
        $discipline = DisciplineRecord::create([
            'employee_id' => $employee->id,
            'jenis_hukuman' => 'Ringan',
            'deskripsi' => 'File tidak tersedia',
            'tanggal_mulai' => '2026-01-01',
            'no_sk' => 'SK-DIS-HILANG',
            'tanggal_sk' => '2025-12-31',
            'file_sk' => 'sk/admin-discipline-hilang.pdf',
        ]);
        $status = EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_nama' => 'Status File Hilang',
            'tanggal_efektif' => '2026-01-01',
            'file_sk' => 'sk/admin-status-hilang.pdf',
            'is_latest' => true,
        ]);

        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee))
            ->assertOk();
        $content = $response->getContent();

        $rankRow = collect($this->extractAlpineList($content, 'pangkatList', 'jabatanList'))->firstWhere('no_sk', $rank->no_sk);
        $positionRow = collect($this->extractAlpineList($content, 'jabatanList', 'kgbList'))->firstWhere('no_sk', $position->no_sk);
        $salaryRow = collect($this->extractAlpineList($content, 'kgbList', 'disiplinList'))->firstWhere('no_sk', $salary->no_sk);
        $disciplineRow = collect($this->extractAlpineList($content, 'disiplinList', 'pendidikanList'))->firstWhere('no_sk', $discipline->no_sk);

        $this->assertNull($rankRow['download_url']);
        $this->assertNull($positionRow['download_url']);
        $this->assertNull($salaryRow['download_url']);
        $this->assertNull($disciplineRow['download_url']);
        $response->assertDontSee(route('pegawai.history-attachments.download', [
            'employee' => $employee,
            'type' => 'status',
            'history' => $status,
        ]), false);
    }

    public function test_detail_admin_tidak_merender_tautan_snapshot_status_yang_file_privatnya_hilang(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = $this->employeeWithReferences([
            'status_berkas_path' => 'sk/admin-status-snapshot-hilang.pdf',
            'status_nomor_berkas' => 'SK-SNAPSHOT-HILANG',
        ]);

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee))
            ->assertOk()
            ->assertDontSee(route('pegawai.history-attachments.download', [
                'employee' => $employee,
                'type' => 'status-snapshot',
                'history' => $employee,
            ]), false);
    }

    public function test_detail_admin_memakai_snapshot_status_saat_semua_riwayat_tidak_memiliki_lampiran(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = $this->employeeWithReferences([
            'status_berkas_path' => 'sk/admin-status-snapshot-fallback.pdf',
            'status_nomor_berkas' => 'SK-SNAPSHOT-FALLBACK',
        ]);
        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_nama' => 'Aktif tanpa lampiran',
            'tanggal_efektif' => '2026-08-01',
            'is_latest' => true,
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($employee->status_berkas_path, 'snapshot status privat');
        $url = route('pegawai.history-attachments.download', [
            'employee' => $employee,
            'type' => 'status-snapshot',
            'history' => $employee,
        ]);

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee))
            ->assertOk()
            ->assertSee('Aktif tanpa lampiran')
            ->assertSee('SK-SNAPSHOT-FALLBACK')
            ->assertSee($url, false);
    }

    public function test_detail_page_provides_optional_sk_upload_controls_for_each_history_modal(): void
    {
        $employee = $this->employeeWithReferences();

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('id="file_sk_pangkat"', false)
            ->assertSee('id="file_sk_jabatan"', false)
            ->assertSee('id="file_sk_kgb"', false)
            ->assertSee('accept=".pdf,.jpg,.jpeg,.png"', false);
    }

    public function test_employee_detail_response_includes_kepala_lembaga_marker(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->employeeWithReferences(['is_kepala_lembaga' => true]);

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}");

        $response->assertOk()
            ->assertJsonPath('employee.is_kepala_lembaga', true);
    }

    public function test_employee_detail_response_includes_erd_relations(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->employeeWithReferences(['nama_lengkap' => 'Detail Lengkap']);
        $supervisor = $this->employeeWithReferences([
            'nama_lengkap' => 'Atasan Langsung',
            'email' => 'atasan@example.test',
        ]);

        $this->seedEmployeeDetail($employee, $supervisor);

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}");

        $response->assertOk()
            ->assertJsonPath('employee.families.0.nama_anggota', 'Budi Detail')
            ->assertJsonPath('employee.appointments.0.jenis_pengangkatan', 'PNS')
            ->assertJsonPath('employee.rank_histories.0.golongan.kode', 'III/a')
            ->assertJsonPath('employee.position_histories.0.jenis_jabatan.nama', 'Struktural')
            ->assertJsonPath('employee.position_histories.0.unit_kerja.nama', 'Kepala Bagian Umum')
            ->assertJsonPath('employee.salary_histories.0.gaji_pokok', '5000000.00')
            ->assertJsonPath('employee.discipline_records.0.jenis_hukuman', 'Ringan')
            ->assertJsonPath('employee.education_histories.0.jenjang.nama', 'D4 / S1')
            ->assertJsonPath('employee.documents.0.nama_dokumen', 'SK Pangkat')
            ->assertJsonPath('employee.supervisor_assignments.0.supervisor.nama_lengkap', 'Atasan Langsung')
            ->assertJsonPath('employee.leave_balances.0.tahun', 2026)
            ->assertJsonPath('employee.leave_requests.0.jenis_cuti.nama', 'Cuti Tahunan')
            ->assertJsonPath('employee.ews_alerts.0.type', 'KGB');
    }

    public function test_pegawai_cannot_show_other_employee_detail_by_admin_endpoint(): void
    {
        $user = User::factory()->pegawai()->create();
        $employee = $this->employeeWithReferences();

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}");

        $response->assertForbidden();
    }

    public function test_pegawai_can_show_own_detail_from_session(): void
    {
        $employee = $this->employeeWithReferences(['nama_lengkap' => 'Pegawai Login']);
        $otherEmployee = $this->employeeWithReferences([
            'nama_lengkap' => 'Pegawai Lain',
            'email' => 'pegawai.lain@example.test',
        ]);
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/profil-saya');

        $response->assertOk()
            ->assertJsonPath('message', 'Detail profil pegawai berhasil diambil.')
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('employee.nama_lengkap', 'Pegawai Login')
            ->assertJsonMissing(['id' => $otherEmployee->id]);
    }

    public function test_pegawai_without_linked_employee_gets_not_found_on_own_detail(): void
    {
        $user = User::factory()->pegawai()->create(['employee_id' => null]);

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/profil-saya');

        $response->assertNotFound()
            ->assertJsonPath('message', 'Data pegawai untuk akun ini belum terhubung.');
    }

    public function test_admin_kepegawaian_cannot_use_pegawai_profile_endpoint(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/profil-saya');

        $response->assertForbidden();
    }

    public function test_pegawai_profile_endpoint_requires_read_self_permission(): void
    {
        $role = Role::where('name', 'pegawai')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.read_self')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);

        $employee = $this->employeeWithReferences();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/profil-saya');

        $response->assertForbidden();
    }

    public function test_missing_employee_detail_returns_not_found(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/pegawai/'.Str::uuid());

        $response->assertNotFound();
    }

    private function employeeWithReferences(array $attributes = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'agama_id' => RefAgama::where('nama', 'Islam')->firstOrFail()->id,
            'status_kawin_id' => RefStatusPerkawinan::where('nama', 'Menikah')->firstOrFail()->id,
            'jenis_pegawai_id' => RefJenisPegawai::where('nama', 'PNS')->firstOrFail()->id,
            'email' => fake()->unique()->safeEmail(),
        ], $attributes));
    }

    private function createStatusHistory(
        Employee $employee,
        string $name,
        bool $isLatest,
        string $effectiveDate,
        string $createdAt,
        string $id,
    ): EmployeeStatusHistory {
        $history = new EmployeeStatusHistory;
        $history->id = $id;
        $history->forceFill([
            'employee_id' => $employee->id,
            'status_nama' => $name,
            'tanggal_efektif' => $effectiveDate,
            'is_latest' => $isLatest,
            'created_at' => Carbon::parse($createdAt),
            'updated_at' => Carbon::parse($createdAt),
        ])->save();

        return $history;
    }

    /** @return list<array<string, mixed>> */
    private function extractAlpineList(string $html, string $listName, string $nextListName): array
    {
        $startMarker = $listName.': ';
        $endMarker = "\n        ".$nextListName.': ';
        $start = strpos($html, $startMarker);
        $this->assertNotFalse($start);
        $end = strpos($html, $endMarker, $start);
        $this->assertNotFalse($end);
        $json = rtrim(trim(substr($html, $start + strlen($startMarker), $end - $start - strlen($startMarker))), ',');

        return json_decode(html_entity_decode($json, ENT_QUOTES | ENT_HTML5), true, flags: JSON_THROW_ON_ERROR);
    }

    private function seedEmployeeDetail(Employee $employee, Employee $supervisor): void
    {
        EmployeeFamily::create([
            'employee_id' => $employee->id,
            'nama_anggota' => 'Budi Detail',
            'hubungan' => 'Anak',
            'tanggal_lahir' => '2015-02-01',
            'jenis_kelamin' => 'L',
            'status_tunjangan' => true,
        ]);

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2010-01-01',
            'no_sk' => 'SK-ANGKAT-001',
            'tanggal_sk' => '2009-12-20',
        ]);

        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => RefGolongan::where('kode', 'III/a')->firstOrFail()->id,
            'tmt_pangkat' => '2022-04-01',
            'no_sk' => 'SK-PANGKAT-001',
            'tanggal_sk' => '2022-03-15',
            'is_latest' => true,
        ]);

        PositionHistory::create([
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Analis Kepegawaian',
            'jenis_jabatan_id' => RefJenisJabatan::where('nama', 'Struktural')->firstOrFail()->id,
            'eselon_id' => RefEselon::where('kode', 'IV.a')->firstOrFail()->id,
            'unit_kerja_id' => RefUnitKerja::where('nama', 'Kepala Bagian Umum')->firstOrFail()->id,
            'tmt_jabatan' => '2023-01-01',
            'no_sk' => 'SK-JABATAN-001',
            'tanggal_sk' => '2022-12-15',
            'is_latest' => true,
        ]);

        SalaryHistory::create([
            'employee_id' => $employee->id,
            'tmt_kgb' => '2024-01-01',
            'gaji_pokok' => 5000000,
            'no_sk' => 'SK-KGB-001',
            'tanggal_sk' => '2023-12-20',
            'is_latest' => true,
        ]);

        DisciplineRecord::create([
            'employee_id' => $employee->id,
            'jenis_hukuman' => 'Ringan',
            'deskripsi' => 'Teguran tertulis',
            'tanggal_mulai' => '2024-02-01',
            'tanggal_berakhir' => '2024-03-01',
            'no_sk' => 'SK-DISIPLIN-001',
            'tanggal_sk' => '2024-01-25',
            'is_active' => false,
        ]);

        EducationHistory::create([
            'employee_id' => $employee->id,
            'jenjang_id' => RefJenjangPendidikan::where('nama', 'D4 / S1')->firstOrFail()->id,
            'nama_institusi' => 'Universitas Contoh',
            'jurusan' => 'Administrasi Publik',
            'tahun_lulus' => 2010,
            'no_ijazah' => 'IJZ-001',
        ]);

        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'SK',
            'nama_dokumen' => 'SK Pangkat',
            'nomor_dokumen' => 'DOC-001',
            'tanggal_dokumen' => '2022-03-15',
            'file_path' => 'pegawai/sk-pangkat.pdf',
        ]);

        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'supervisor_id' => $supervisor->id,
            'tanggal_mulai' => '2024-01-01',
        ]);

        LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 1,
            'terpakai' => 2,
            'sisa' => 11,
        ]);

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::where('nama', 'Cuti Tahunan')->firstOrFail()->id,
            'tanggal_mulai' => '2026-02-02',
            'tanggal_selesai' => '2026-02-03',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Keperluan keluarga',
            'status' => 'menunggu_approval',
        ]);

        EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KGB',
            'target_date' => '2026-04-01',
            'interval_days' => 90,
            'is_processed' => false,
        ]);
    }
}
