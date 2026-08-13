<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\Permission;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJenjangPendidikan;
use App\Models\RefStatusPegawai;
use App\Models\Role;
use App\Models\SalaryHistory;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeHistoryAttachmentDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
        Storage::fake(Document::STORAGE_DISK);
    }

    public function test_admin_dapat_mengunduh_seluruh_tipe_attachment_riwayat_melalui_backend(): void
    {
        $employee = Employee::factory()->create();

        foreach ($this->historyAttachments($employee) as [$type, $history, $path]) {
            Storage::disk(Document::STORAGE_DISK)->put($path, 'attachment '.$type);

            $this->actingAs(User::factory()->adminKepegawaian()->create())
                ->get($this->url($employee, $type, $history->id))
                ->assertOk()
                ->assertDownload();
        }
    }

    public function test_attachment_riwayat_menolak_cross_employee_type_tidak_dikenal_dan_uuid_malformed(): void
    {
        $employee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $rank = $this->historyAttachments($otherEmployee)[0][1];

        $admin = User::factory()->adminKepegawaian()->create();
        $this->actingAs($admin)
            ->get($this->url($employee, 'rank', $rank->id))
            ->assertNotFound();
        $this->actingAs($admin)
            ->get($this->url($employee, 'unknown', $rank->id))
            ->assertNotFound();
        $this->actingAs($admin)
            ->get($this->url($employee, 'rank', 'not-a-uuid'))
            ->assertNotFound();
    }

    public function test_attachment_riwayat_mengembalikan_404_bila_file_hilang(): void
    {
        $employee = Employee::factory()->create();
        $rank = $this->historyAttachments($employee)[0][1];

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get($this->url($employee, 'rank', $rank->id))
            ->assertNotFound();
    }

    public function test_attachment_status_legacy_hanya_memakai_dokumen_privat_milik_pegawai_dengan_nomor_dan_kategori_tepat(): void
    {
        $employee = Employee::factory()->create();
        $history = EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_nama' => 'Status Legacy',
            'tanggal_efektif' => '2026-08-01',
            'nomor_berkas' => 'SK-STATUS-LEGACY-DL',
            'is_latest' => true,
        ]);
        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_status_pegawai',
            'nama_dokumen' => 'SK Status Legacy',
            'nomor_dokumen' => 'SK-STATUS-LEGACY-DL',
            'file_path' => 'pegawai/status-legacy-download.pdf',
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($document->file_path, 'status legacy privat');

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get($this->url($employee, 'status', $history->id))
            ->assertOk()
            ->assertDownload();
    }

    public function test_attachment_status_legacy_menolak_dokumen_lintas_pegawai_dan_file_privat_yang_hilang(): void
    {
        $employee = Employee::factory()->create();
        $history = EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_nama' => 'Status Legacy Tertutup',
            'tanggal_efektif' => '2026-08-01',
            'nomor_berkas' => 'SK-STATUS-LEGACY-TERTUTUP',
            'is_latest' => true,
        ]);
        $otherEmployee = Employee::factory()->create();
        $crossOwnerDocument = Document::create([
            'employee_id' => $otherEmployee->id,
            'jenis_dokumen' => 'sk_status_pegawai',
            'nama_dokumen' => 'SK Milik Pegawai Lain',
            'nomor_dokumen' => 'SK-STATUS-LEGACY-TERTUTUP',
            'file_path' => 'pegawai/status-cross-owner.pdf',
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($crossOwnerDocument->file_path, 'dokumen pegawai lain');

        $admin = User::factory()->adminKepegawaian()->create();
        $this->actingAs($admin)
            ->get($this->url($employee, 'status', $history->id))
            ->assertNotFound();

        $wrongCategoryDocument = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Kategori Tidak Cocok',
            'nomor_dokumen' => 'SK-STATUS-LEGACY-TERTUTUP',
            'file_path' => 'pegawai/status-wrong-category.pdf',
        ]);
        $wrongNumberDocument = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_status_pegawai',
            'nama_dokumen' => 'Nomor Tidak Cocok',
            'nomor_dokumen' => 'SK-STATUS-NOMOR-LAIN',
            'file_path' => 'pegawai/status-wrong-number.pdf',
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($wrongCategoryDocument->file_path, 'kategori salah');
        Storage::disk(Document::STORAGE_DISK)->put($wrongNumberDocument->file_path, 'nomor salah');

        $this->actingAs($admin)
            ->get($this->url($employee, 'status', $history->id))
            ->assertNotFound();

        $missingFileDocument = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_status_pegawai',
            'nama_dokumen' => 'SK Tanpa File',
            'nomor_dokumen' => 'SK-STATUS-LEGACY-TERTUTUP',
            'file_path' => 'pegawai/status-missing.pdf',
        ]);

        $this->assertFalse(Storage::disk(Document::STORAGE_DISK)->exists($missingFileDocument->file_path));
        $this->actingAs($admin)
            ->get($this->url($employee, 'status', $history->id))
            ->assertNotFound();
    }

    public function test_attachment_riwayat_memerlukan_permission_employees_read_secara_independen_dari_role(): void
    {
        $employee = Employee::factory()->create();
        [$type, $rank, $path] = $this->historyAttachments($employee)[0];
        Storage::disk(Document::STORAGE_DISK)->put($path, 'attachment');
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permission = Permission::where('name', 'employees.read')->firstOrFail();
        $role->permissions()->detach($permission->id);

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get($this->url($employee, $type, $rank->id))
            ->assertForbidden();
    }

    public function test_show_dan_edit_admin_tidak_mengekspos_url_storage_attachment_riwayat(): void
    {
        $employee = Employee::factory()->create();
        $attachments = $this->historyAttachments($employee);
        foreach ($attachments as [, , $path]) {
            Storage::disk(Document::STORAGE_DISK)->put($path, 'attachment');
        }

        $admin = User::factory()->superAdmin()->create();
        $show = $this->actingAs($admin)->get(route('pegawai.show', $employee));
        $edit = $this->actingAs($admin)->get(route('pegawai.edit', $employee));

        $show->assertOk()->assertDontSee('/storage/', false);
        $edit->assertOk()->assertDontSee('/storage/', false);
        foreach ($attachments as [$type, $history]) {
            $expectedUrl = $this->url($employee, $type, $history->id);
            if ($type === 'status') {
                $show->assertSee($expectedUrl, false);
            } elseif ($type === 'discipline') {
                $this->assertStringContainsString(str_replace('/', '\\/', $expectedUrl), $show->getContent());
            } elseif (in_array($type, ['rank', 'position', 'salary', 'appointment'], true)) {
                $edit->assertSee($expectedUrl, false);
            }
        }
    }

    /** @return list<array{string, object, string}> */
    private function historyAttachments(Employee $employee): array
    {
        $status = RefStatusPegawai::where('nama', 'Aktif')->firstOrFail();
        $employee->update(['status_berkas_path' => 'status-snapshot.pdf']);

        return [
            ['rank', RankHistory::create(['employee_id' => $employee->id, 'golongan_id' => RefGolongan::firstOrFail()->id, 'tmt_pangkat' => '2026-01-01', 'file_sk' => 'rank.pdf', 'is_latest' => true]), 'rank.pdf'],
            ['position', PositionHistory::create(['employee_id' => $employee->id, 'nama_jabatan' => 'Analis', 'tmt_jabatan' => '2026-01-01', 'file_sk' => 'position.pdf', 'is_latest' => true]), 'position.pdf'],
            ['salary', SalaryHistory::create(['employee_id' => $employee->id, 'gaji_pokok' => 5000000, 'tmt_kgb' => '2026-01-01', 'file_sk' => 'salary.pdf', 'is_latest' => true]), 'salary.pdf'],
            ['appointment', Appointment::create(['employee_id' => $employee->id, 'jenis_pengangkatan' => 'PNS', 'tmt_pengangkatan' => '2020-01-01', 'file_sk' => 'appointment.pdf']), 'appointment.pdf'],
            ['discipline', DisciplineRecord::create(['employee_id' => $employee->id, 'jenis_hukuman' => 'Ringan', 'deskripsi' => 'Fixture attachment', 'tanggal_mulai' => '2026-01-01', 'no_sk' => 'SK-DIS-001', 'tanggal_sk' => '2025-12-31', 'file_sk' => 'discipline.pdf']), 'discipline.pdf'],
            ['education', EducationHistory::create(['employee_id' => $employee->id, 'jenjang_id' => RefJenjangPendidikan::firstOrFail()->id, 'nama_institusi' => 'Universitas', 'tahun_lulus' => 2020, 'file_ijazah' => 'education.pdf']), 'education.pdf'],
            ['status', EmployeeStatusHistory::create(['employee_id' => $employee->id, 'status_pegawai_id' => $status->id, 'status_nama' => $status->nama, 'tanggal_efektif' => '2026-01-01', 'file_sk' => 'status.pdf', 'is_latest' => true]), 'status.pdf'],
            ['status-snapshot', $employee, 'status-snapshot.pdf'],
        ];
    }

    private function url(Employee $employee, string $type, string $history): string
    {
        return '/pegawai/'.$employee->id.'/attachment-riwayat/'.$type.'/'.$history.'/unduh';
    }
}
