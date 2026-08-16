<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefUnitKerja;
use App\Models\Role;
use App\Models\SalaryHistory;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeIndexTest extends TestCase
{
    use RefreshDatabase;

    private const PEGAWAI_ENDPOINT = '/api/v1/pegawai';

    private RefJenisPegawai $pns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
        $this->pns = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
    }

    private function createPnsEmployee(array $attributes = []): Employee
    {
        return Employee::factory()->create(array_merge(['jenis_pegawai_id' => $this->pns->id], $attributes));
    }

    public function test_guest_cannot_list_employees(): void
    {
        $response = $this->getJson(self::PEGAWAI_ENDPOINT);

        $response->assertRedirect('/login');
    }

    public function test_admin_kepegawaian_can_list_employees(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nama_lengkap' => 'Budi Santoso']);

        $this->actingAs($user);
        $response = $this->getJson(self::PEGAWAI_ENDPOINT);

        $response->assertOk();
        $response->assertJsonPath('message', 'Daftar pegawai berhasil diambil.');
        $response->assertJsonPath('employees.data.0.nama_lengkap', 'Budi Santoso');
    }

    public function test_super_admin_can_list_employees(): void
    {
        $user = User::factory()->superAdmin()->create();
        Employee::factory()->create(['nama_lengkap' => 'Admin View']);

        $this->actingAs($user);
        $response = $this->getJson(self::PEGAWAI_ENDPOINT);

        $response->assertOk();
        $response->assertJsonPath('employees.data.0.nama_lengkap', 'Admin View');
    }

    public function test_pegawai_cannot_list_employees(): void
    {
        $user = User::factory()->pegawai()->create();

        $this->actingAs($user);
        $response = $this->getJson(self::PEGAWAI_ENDPOINT);

        $response->assertForbidden();
    }

    public function test_default_only_lists_active_employees(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nama_lengkap' => 'Pegawai Aktif', 'status_aktif' => 'Aktif']);
        Employee::factory()->create(['nama_lengkap' => 'Pegawai Nonaktif', 'status_aktif' => 'Non-Aktif']);

        $this->actingAs($user);
        $response = $this->getJson(self::PEGAWAI_ENDPOINT);

        $response->assertOk();
        $response->assertJsonCount(1, 'employees.data');
        $response->assertJsonPath('employees.data.0.nama_lengkap', 'Pegawai Aktif');
    }

    public function test_search_matches_name_and_nip(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create([
            'nama_lengkap' => 'Siti Aminah',
            'nip' => '198001012006041001',
        ]);
        Employee::factory()->create([
            'nama_lengkap' => 'Budi Santoso',
            'nip' => '197701012006041002',
        ]);

        $this->actingAs($user);

        $responseByName = $this->getJson(self::PEGAWAI_ENDPOINT.'?search=aminah');
        $responseByName->assertOk();
        $responseByName->assertJsonCount(1, 'employees.data');
        $responseByName->assertJsonPath('employees.data.0.nama_lengkap', 'Siti Aminah');

        $responseByNip = $this->getJson(self::PEGAWAI_ENDPOINT.'?search=197701012006041002');
        $responseByNip->assertOk();
        $responseByNip->assertJsonCount(1, 'employees.data');
        $responseByNip->assertJsonPath('employees.data.0.nama_lengkap', 'Budi Santoso');
    }

    public function test_can_filter_by_status_golongan_and_jenis_pegawai(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $pns = RefJenisPegawai::where('nama', 'PNS')->firstOrFail();
        $pppk = RefJenisPegawai::where('nama', 'PPPK')->firstOrFail();

        Employee::factory()->create([
            'nama_lengkap' => 'Target Filter',
            'status_aktif' => 'Pensiun',
            'golongan_terakhir' => 'III/a',
            'jenis_pegawai_id' => $pns->id,
        ]);
        Employee::factory()->create([
            'nama_lengkap' => 'Bukan Target',
            'status_aktif' => 'Pensiun',
            'golongan_terakhir' => 'IV/a',
            'jenis_pegawai_id' => $pppk->id,
        ]);

        $this->actingAs($user);
        $response = $this->getJson(self::PEGAWAI_ENDPOINT.'?status_aktif=Pensiun&golongan=III/a&jenis_pegawai_id='.$pns->id);

        $response->assertOk();
        $response->assertJsonCount(1, 'employees.data');
        $response->assertJsonPath('employees.data.0.nama_lengkap', 'Target Filter');
        $response->assertJsonPath('employees.data.0.jenis_pegawai', 'PNS');
    }

    public function test_sorting_and_pagination_follow_allowed_parameters(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nama_lengkap' => 'Charlie']);
        Employee::factory()->create(['nama_lengkap' => 'Bravo']);
        Employee::factory()->create(['nama_lengkap' => 'Alpha']);

        $this->actingAs($user);
        $response = $this->getJson(self::PEGAWAI_ENDPOINT.'?sort=nama_lengkap&direction=desc&per_page=10');

        $response->assertOk();
        $response->assertJsonPath('employees.data.0.nama_lengkap', 'Charlie');
        $response->assertJsonPath('employees.per_page', 10);
        $response->assertJsonPath('employees.total', 3);
    }

    public function test_invalid_per_page_is_rejected(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->getJson(self::PEGAWAI_ENDPOINT.'?per_page=100');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('per_page');
    }

    public function test_permission_is_enforced_even_when_role_allows(): void
    {
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.read')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);

        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->getJson(self::PEGAWAI_ENDPOINT);

        $response->assertForbidden();
    }

    public function test_document_completeness_is_true_when_no_required_history_exists(): void
    {

        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->createPnsEmployee(['profil_status' => 'belum_lengkap']);

        $this->assertEmployeeDocumentCompleteness($user, $employee, 'belum_ada');
    }

    public function test_non_pns_document_completeness_is_not_required(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $pppk = RefJenisPegawai::firstOrCreate(['nama' => 'PPPK']);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pppk->id]);

        $this->assertEmployeeDocumentCompleteness($user, $employee, 'tidak_wajib');
        $this->actingAs($user)
            ->getJson("/api/v1/pegawai/{$employee->id}/status-dokumen")
            ->assertOk()
            ->assertJsonPath('document_status.status_kelengkapan', 'tidak_wajib')
            ->assertJsonPath('document_status.total_wajib', 0);
    }

    public function test_employee_index_renders_not_required_document_status_ui(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->get(route('data-pegawai'))
            ->assertOk()
            ->assertSee("p.is_lengkap === 'tidak_wajib'", false)
            ->assertSee('Tidak Wajib', false)
            ->assertSee("documentStatus.status_kelengkapan === 'tidak_wajib'", false)
            ->assertSee('Dokumen SK wajib tidak berlaku untuk jenis pegawai ini.', false);
    }

    public function test_document_completeness_is_belum_lengkap_when_only_rank_sk_is_valid(): void
    {

        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->createPnsEmployee(['profil_status' => 'lengkap']);
        $rank = RefGolongan::where('kode', 'III/a')->firstOrFail();
        $filePath = 'ranks/sk/'.$employee->id.'.pdf';

        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $rank->id,
            'tmt_pangkat' => '2026-01-01',
            'no_sk' => 'SK-PANGKAT-001',
            'tanggal_sk' => '2025-12-20',
            'file_sk' => $filePath,
            'is_latest' => true,
        ]);

        Storage::disk(Document::STORAGE_DISK)->put($filePath, 'SK pangkat');
        $this->assertEmployeeDocumentCompleteness($user, $employee, 'belum_lengkap');

        Storage::disk(Document::STORAGE_DISK)->delete($filePath);
        $this->assertEmployeeDocumentCompleteness($user, $employee, 'perlu_perbaikan');
    }

    public function test_document_completeness_uses_canonical_rank_and_salary_histories(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->createPnsEmployee();

        $rankFile = 'ranks/sk/'.$employee->id.'-lama.pdf';
        $salaryFile = 'salaries/sk/'.$employee->id.'-lama.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($rankFile, 'SK pangkat lama');
        Storage::disk(Document::STORAGE_DISK)->put($salaryFile, 'SK KGB lama');

        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2022-01-01',
            'file_sk' => $rankFile,
            'is_latest' => false,
        ]);
        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2024-01-01',
            'file_sk' => null,
            'is_latest' => true,
        ]);
        SalaryHistory::create([
            'employee_id' => $employee->id,
            'tmt_kgb' => '2022-01-01',
            'file_sk' => $salaryFile,
            'is_latest' => false,
        ]);
        SalaryHistory::create([
            'employee_id' => $employee->id,
            'tmt_kgb' => '2024-01-01',
            'file_sk' => null,
            'is_latest' => true,
        ]);

        $this->assertEmployeeDocumentCompleteness($user, $employee, 'perlu_perbaikan');
    }

    public function test_document_completeness_is_belum_ada_when_only_other_documents_with_files_exist(): void
    {

        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->createPnsEmployee(['profil_status' => 'belum_lengkap']);

        // Tidak ada riwayat SK (rankHistories, positionHistories, salaryHistories, appointments)
        // Tapi ada berkas lainnya (mis. KTP) yang filenya tersedia di storage
        $filePath = 'documents/ktp/'.$employee->id.'.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($filePath, 'file KTP');

        Document::create([
            'employee_id' => $employee->id,
            'nama_dokumen' => 'KTP',
            'jenis_dokumen' => 'ktp_kk',
            'file_path' => $filePath,
        ]);

        $this->assertEmployeeDocumentCompleteness($user, $employee, 'belum_ada');
    }

    public function test_document_completeness_is_belum_lengkap_when_only_three_required_sks_are_valid(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->createPnsEmployee();

        foreach (['sk_pengangkatan', 'sk_pangkat', 'sk_jabatan'] as $category) {
            $path = "{$employee->id}/{$category}.pdf";
            Storage::disk(Document::STORAGE_DISK)->put($path, 'SK tersedia');
            Document::create([
                'employee_id' => $employee->id,
                'jenis_dokumen' => $category,
                'nama_dokumen' => $category,
                'file_path' => $path,
            ]);
        }

        $this->assertEmployeeDocumentCompleteness($user, $employee, 'belum_lengkap');

        $this->actingAs($user)
            ->getJson("/api/v1/pegawai/{$employee->id}/status-dokumen")
            ->assertOk()
            ->assertJsonPath('document_status.tersedia_count', 3)
            ->assertJsonPath('document_status.total_wajib', 4)
            ->assertJsonPath('document_status.required_sks.3.jenis', 'sk_kgb')
            ->assertJsonPath('document_status.required_sks.3.status', 'belum_ada');
    }

    public function test_document_completeness_requires_storage_files_for_every_existing_history_type(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->createPnsEmployee();
        $rank = RefGolongan::where('kode', 'III/a')->firstOrFail();
        $positionType = RefJenisJabatan::where('nama', 'Struktural')->firstOrFail();
        $unit = RefUnitKerja::firstOrFail();
        $paths = [
            'rank' => 'ranks/sk/'.$employee->id.'.pdf',
            'position' => 'positions/sk/'.$employee->id.'.pdf',
            'salary' => 'salaries/sk/'.$employee->id.'.pdf',
            'appointment' => 'appointments/sk/'.$employee->id.'.pdf',
        ];

        $records = [
            'rank' => RankHistory::create([
                'employee_id' => $employee->id,
                'golongan_id' => $rank->id,
                'tmt_pangkat' => '2026-01-01',
                'no_sk' => 'SK-PANGKAT-002',
                'tanggal_sk' => '2025-12-20',
                'file_sk' => $paths['rank'],
                'is_latest' => true,
            ]),
            'position' => PositionHistory::create([
                'employee_id' => $employee->id,
                'nama_jabatan' => 'Analis Kepegawaian',
                'jenis_jabatan_id' => $positionType->id,
                'unit_kerja_id' => $unit->id,
                'tmt_jabatan' => '2026-01-01',
                'no_sk' => 'SK-JABATAN-001',
                'tanggal_sk' => '2025-12-20',
                'file_sk' => $paths['position'],
                'is_latest' => true,
            ]),
            'salary' => SalaryHistory::create([
                'employee_id' => $employee->id,
                'tmt_kgb' => '2026-01-01',
                'gaji_pokok' => 5000000,
                'no_sk' => 'SK-KGB-001',
                'tanggal_sk' => '2025-12-20',
                'file_sk' => $paths['salary'],
                'is_latest' => true,
            ]),
            'appointment' => Appointment::create([
                'employee_id' => $employee->id,
                'jenis_pengangkatan' => 'PNS',
                'tmt_pengangkatan' => '2020-01-01',
                'no_sk' => 'SK-PENGANGKATAN-001',
                'tanggal_sk' => '2019-12-20',
                'file_sk' => $paths['appointment'],
            ]),
        ];

        $disk = Storage::disk(Document::STORAGE_DISK);
        foreach ($paths as $path) {
            $disk->put($path, 'SK tersedia');
        }
        $this->assertEmployeeDocumentCompleteness($user, $employee, 'lengkap');

        foreach ($paths as $type => $path) {
            $disk->delete($path);
            $this->assertEmployeeDocumentCompleteness($user, $employee, 'perlu_perbaikan');

            $newPath = str_replace('.pdf', '_new.pdf', $path);
            $disk->put($newPath, 'SK tersedia');

            $records[$type]->update(['file_sk' => null]);
            $this->assertEmployeeDocumentCompleteness($user, $employee, 'perlu_perbaikan');
            $records[$type]->update(['file_sk' => $newPath]);
            $paths[$type] = $newPath;
        }

        $this->assertEmployeeDocumentCompleteness($user, $employee, 'lengkap');
    }

    public function test_document_status_endpoint_reports_each_history_file_from_storage(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->createPnsEmployee();
        $rank = RefGolongan::where('kode', 'III/a')->firstOrFail();
        $filePath = 'ranks/sk/'.$employee->id.'.pdf';

        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $rank->id,
            'tmt_pangkat' => '2026-01-01',
            'no_sk' => 'SK-PANGKAT-DETAIL',
            'tanggal_sk' => '2025-12-20',
            'file_sk' => $filePath,
            'is_latest' => true,
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($filePath, 'SK pangkat');
        $archiveFilePath = 'berkas/'.$employee->id.'/ktp.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($archiveFilePath, 'KTP');
        $archiveDocument = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'ktp_kk',
            'nama_dokumen' => 'KTP',
            'file_path' => $archiveFilePath,
        ]);
        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Berkas Lainnya',
            'file_path' => 'berkas/'.$employee->id.'/berkas-hilang.pdf',
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/pegawai/{$employee->id}/status-dokumen");
        $response
            ->assertOk()
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('document_status.status_kelengkapan', 'belum_lengkap')
            ->assertJsonPath('document_status.is_lengkap', false)
            ->assertJsonPath('document_status.tersedia_count', 1)
            ->assertJsonPath('document_status.total_wajib', 4)
            ->assertJsonPath('document_status.required_sks.0.status', 'belum_ada')
            ->assertJsonPath('document_status.total_riwayat', 1)
            ->assertJsonPath('document_status.file_tersedia', 1)
            ->assertJsonPath('document_status.records.0.jenis', 'Pangkat')
            ->assertJsonPath('document_status.records.0.nomor_sk', 'SK-PANGKAT-DETAIL')
            ->assertJsonPath('document_status.records.0.file_tersedia', true)
            ->assertJsonPath(
                'document_status.records.0.file_url',
                route('pegawai.history-attachments.download', [
                    'employee' => $employee,
                    'type' => 'rank',
                    'history' => $employee->rankHistories()->firstOrFail(),
                ]),
            )
            ->assertJsonPath('document_status.total_dokumen', 3)
            ->assertJsonPath('document_status.dokumen_tersedia', 2)
            ->assertJsonFragment([
                'kategori' => 'KTP & KK',
                'nama' => 'KTP',
                'file_tersedia' => true,
                'file_url' => route('dokumen.download', $archiveDocument),
            ])
            ->assertJsonFragment([
                'kategori' => 'Lainnya',
                'nama' => 'Berkas Lainnya',
                'status_label' => 'File tidak ditemukan',
            ]);
        $this->assertStringNotContainsString('/storage/', $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $downloadResponse = $this->actingAs($user)
            ->get($response->json('document_status.records.0.file_url'))
            ->assertOk()
            ->assertDownload();
        $this->assertStringContainsString('no-store', (string) $downloadResponse->headers->get('Cache-Control'));

        Storage::disk(Document::STORAGE_DISK)->delete($filePath);

        $this->actingAs($user)
            ->getJson("/api/v1/pegawai/{$employee->id}/status-dokumen")
            ->assertOk()
            ->assertJsonPath('document_status.status_kelengkapan', 'perlu_perbaikan')
            ->assertJsonPath('document_status.is_lengkap', false)
            ->assertJsonPath('document_status.required_sks.1.jenis', 'sk_pangkat')
            ->assertJsonPath('document_status.required_sks.1.status', 'perlu_perbaikan')
            ->assertJsonPath('document_status.required_sks.1.status_label', 'File tidak ditemukan di storage')
            ->assertJsonPath('document_status.file_tersedia', 0)
            ->assertJsonPath('document_status.records.0.status_label', 'File tidak ditemukan');
    }

    public function test_document_status_rejects_history_path_with_conflicting_document_scope(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $rank = RefGolongan::where('kode', 'III/a')->firstOrFail();
        $filePath = 'ranks/sk/konflik-scope.pdf';
        $history = RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $rank->id,
            'tmt_pangkat' => '2026-01-01',
            'no_sk' => 'SK-PANGKAT-KONFLIK',
            'tanggal_sk' => '2025-12-20',
            'file_sk' => $filePath,
            'is_latest' => true,
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($filePath, 'SK milik pegawai lain');
        Document::create([
            'employee_id' => $otherEmployee->id,
            'jenis_dokumen' => 'sk_pangkat',
            'nama_dokumen' => 'SK Pangkat Pegawai Lain',
            'file_path' => $filePath,
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/pegawai/{$employee->id}/status-dokumen")
            ->assertOk()
            ->assertJsonPath('document_status.file_tersedia', 0)
            ->assertJsonPath('document_status.records.0.file_tersedia', false)
            ->assertJsonPath('document_status.records.0.file_url', null);

        $this->actingAs($user)
            ->get(route('pegawai.history-attachments.download', [
                'employee' => $employee,
                'type' => 'rank',
                'history' => $history,
            ]))
            ->assertNotFound();
    }

    public function test_table_row_uses_required_sk_archives_for_document_status(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->createPnsEmployee();

        foreach (['sk_pengangkatan', 'sk_pangkat', 'sk_jabatan', 'sk_kgb'] as $jenis) {
            $path = "archives/{$employee->id}/{$jenis}.pdf";
            Storage::disk(Document::STORAGE_DISK)->put($path, 'SK tersedia');
            Document::create([
                'employee_id' => $employee->id,
                'jenis_dokumen' => $jenis,
                'nama_dokumen' => $jenis,
                'file_path' => $path,
            ]);
        }

        $this->actingAs($user)
            ->getJson("/api/v1/pegawai/{$employee->id}/table-row")
            ->assertOk()
            ->assertJsonPath('employee.is_lengkap', 'lengkap');
    }

    public function test_document_status_endpoint_prioritizes_is_latest_over_later_tmt(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->createPnsEmployee();
        $newerFile = "ranks/sk/{$employee->id}-newer.pdf";
        Storage::disk(Document::STORAGE_DISK)->put($newerFile, 'SK lebih baru');

        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2022-01-01',
            'file_sk' => null,
            'is_latest' => true,
        ]);
        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2024-01-01',
            'file_sk' => $newerFile,
            'is_latest' => false,
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/pegawai/{$employee->id}/status-dokumen")
            ->assertOk()
            ->assertJsonPath('document_status.required_sks.1.jenis', 'sk_pangkat')
            ->assertJsonPath('document_status.required_sks.1.status', 'perlu_perbaikan');
    }

    private function assertEmployeeDocumentCompleteness(User $user, Employee $employee, string $expected): void
    {
        $response = $this->actingAs($user)
            ->getJson(self::PEGAWAI_ENDPOINT.'?search='.urlencode($employee->nip));

        $response->assertOk()->assertJsonCount(1, 'employees.data');
        $response->assertJsonPath('employees.data.0.is_lengkap', $expected);
    }
}
