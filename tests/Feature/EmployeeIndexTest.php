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

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
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
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['profil_status' => 'belum_lengkap']);

        $this->assertEmployeeDocumentCompleteness($user, $employee, true);
    }

    public function test_document_completeness_requires_file_for_the_existing_rank_history_only(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['profil_status' => 'lengkap']);
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
        $this->assertEmployeeDocumentCompleteness($user, $employee, true);

        Storage::disk(Document::STORAGE_DISK)->delete($filePath);
        $this->assertEmployeeDocumentCompleteness($user, $employee, false);
    }

    public function test_document_completeness_requires_storage_files_for_every_existing_history_type(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
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
        $this->assertEmployeeDocumentCompleteness($user, $employee, true);

        foreach ($paths as $type => $path) {
            $disk->delete($path);
            $this->assertEmployeeDocumentCompleteness($user, $employee, false);
            $disk->put($path, 'SK tersedia');

            $records[$type]->update(['file_sk' => null]);
            $this->assertEmployeeDocumentCompleteness($user, $employee, false);
            $records[$type]->update(['file_sk' => $path]);
        }

        $this->assertEmployeeDocumentCompleteness($user, $employee, true);
    }

    public function test_document_status_endpoint_reports_each_history_file_from_storage(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
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
        Document::create([
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
            ->assertJsonPath('document_status.is_lengkap', true)
            ->assertJsonPath('document_status.total_riwayat', 1)
            ->assertJsonPath('document_status.file_tersedia', 1)
            ->assertJsonPath('document_status.records.0.jenis', 'Pangkat')
            ->assertJsonPath('document_status.records.0.nomor_sk', 'SK-PANGKAT-DETAIL')
            ->assertJsonPath('document_status.records.0.file_tersedia', true)
            ->assertJsonPath('document_status.total_dokumen', 3)
            ->assertJsonPath('document_status.dokumen_tersedia', 2)
            ->assertJsonFragment([
                'kategori' => 'KTP & KK',
                'nama' => 'KTP',
                'file_tersedia' => true,
            ])
            ->assertJsonFragment([
                'kategori' => 'Lainnya',
                'nama' => 'Berkas Lainnya',
                'status_label' => 'File tidak ditemukan',
            ]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        Storage::disk(Document::STORAGE_DISK)->delete($filePath);

        $this->actingAs($user)
            ->getJson("/api/v1/pegawai/{$employee->id}/status-dokumen")
            ->assertOk()
            ->assertJsonPath('document_status.is_lengkap', false)
            ->assertJsonPath('document_status.file_tersedia', 0)
            ->assertJsonPath('document_status.records.0.status_label', 'File tidak ditemukan');
    }

    private function assertEmployeeDocumentCompleteness(User $user, Employee $employee, bool $expected): void
    {
        $this->actingAs($user)
            ->getJson(self::PEGAWAI_ENDPOINT.'?search='.urlencode($employee->nip))
            ->assertOk()
            ->assertJsonCount(1, 'employees.data')
            ->assertJsonPath('employees.data.0.is_lengkap', $expected);
    }
}
