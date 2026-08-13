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
use Illuminate\Support\Str;
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

    public function test_pimpinan_dapat_mengunduh_lima_tipe_riwayat_legacy_yang_diizinkan(): void
    {
        $employee = Employee::factory()->create();
        $allAttachments = $this->historyAttachments($employee);
        $attachments = [$allAttachments[0], $allAttachments[1], $allAttachments[2], $allAttachments[3], $allAttachments[5]];

        foreach ($attachments as [$type, $history, $path]) {
            Storage::disk(Document::STORAGE_DISK)->put($path, 'attachment pimpinan '.$type);

            $this->actingAs(User::factory()->pimpinan()->create())
                ->get($this->pimpinanUrl($employee, $type, $history->id))
                ->assertOk()
                ->assertDownload();
        }
    }

    public function test_attachment_riwayat_pimpinan_memerlukan_permission_employees_read(): void
    {
        $employee = Employee::factory()->create();
        [$type, $history, $path] = $this->historyAttachments($employee)[5];
        Storage::disk(Document::STORAGE_DISK)->put($path, 'attachment pimpinan');
        $role = Role::where('name', 'pimpinan')->firstOrFail();
        $permission = Permission::where('name', 'employees.read')->firstOrFail();
        $role->permissions()->detach($permission->id);

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get($this->pimpinanUrl($employee, $type, $history->id))
            ->assertForbidden();
    }

    public function test_attachment_riwayat_pimpinan_menolak_history_milik_pegawai_lain(): void
    {
        $employee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        [$type, $history] = $this->historyAttachments($otherEmployee)[5];

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get($this->pimpinanUrl($employee, $type, $history->id))
            ->assertNotFound();
    }

    public function test_attachment_riwayat_pimpinan_menolak_tipe_uuid_dan_history_yang_tidak_valid(): void
    {
        $employee = Employee::factory()->create();
        [$type, $history] = $this->historyAttachments($employee)[5];
        $pimpinan = User::factory()->pimpinan()->create();

        $this->actingAs($pimpinan)
            ->get($this->pimpinanUrl($employee, 'unknown', $history->id))
            ->assertNotFound();
        $this->actingAs($pimpinan)
            ->get($this->pimpinanUrl($employee, $type, 'not-a-uuid'))
            ->assertNotFound();
        $this->actingAs($pimpinan)
            ->get($this->pimpinanUrl($employee, $type, (string) Str::uuid()))
            ->assertNotFound();
    }

    public function test_attachment_riwayat_pimpinan_menolak_file_privat_yang_hilang(): void
    {
        $employee = Employee::factory()->create();
        [$type, $history] = $this->historyAttachments($employee)[5];

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get($this->pimpinanUrl($employee, $type, $history->id))
            ->assertNotFound();
    }

    public function test_attachment_riwayat_menolak_path_yang_terdaftar_untuk_pegawai_atau_kategori_lain(): void
    {
        $employee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $golongan = RefGolongan::firstOrFail();
        $crossOwnerPath = 'sk/rank-cross-owner.pdf';
        $wrongCategoryPath = 'sk/rank-wrong-category.pdf';
        $crossOwner = RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => '2026-01-01',
            'file_sk' => $crossOwnerPath,
            'is_latest' => true,
        ]);
        $wrongCategory = RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => '2025-01-01',
            'file_sk' => $wrongCategoryPath,
            'is_latest' => false,
        ]);
        Document::create([
            'employee_id' => $otherEmployee->id,
            'jenis_dokumen' => 'sk_pangkat',
            'nama_dokumen' => 'SK pegawai lain',
            'file_path' => $crossOwnerPath,
        ]);
        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Kategori salah',
            'file_path' => $wrongCategoryPath,
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($crossOwnerPath, 'pegawai lain');
        Storage::disk(Document::STORAGE_DISK)->put($wrongCategoryPath, 'kategori lain');
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($admin)->get($this->url($employee, 'rank', $crossOwner->id))->assertNotFound();
        $this->actingAs($admin)->get($this->url($employee, 'rank', $wrongCategory->id))->assertNotFound();
    }

    public function test_attachment_status_dan_snapshot_menolak_path_dokumen_lintas_pegawai_atau_kategori(): void
    {
        $employee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $statusPath = 'sk/status-cross-owner.pdf';
        $snapshotPath = 'sk/status-snapshot-wrong-category.pdf';
        $nonPensionPath = 'sk/status-active-with-pension-category.pdf';
        $history = EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_nama' => 'Aktif',
            'tanggal_efektif' => '2026-08-14',
            'file_sk' => $statusPath,
            'is_latest' => true,
        ]);
        $nonPensionHistory = EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_nama' => 'Aktif',
            'tanggal_efektif' => '2026-08-13',
            'file_sk' => $nonPensionPath,
            'is_latest' => false,
        ]);
        $employee->update(['status_berkas_path' => $snapshotPath]);
        Document::create([
            'employee_id' => $otherEmployee->id,
            'jenis_dokumen' => 'sk_status_pegawai',
            'nama_dokumen' => 'SK status pegawai lain',
            'file_path' => $statusPath,
        ]);
        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'ktp_kk',
            'nama_dokumen' => 'Identitas bukan SK status',
            'file_path' => $snapshotPath,
        ]);
        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pensiun',
            'nama_dokumen' => 'SK pensiun bukan untuk status aktif',
            'file_path' => $nonPensionPath,
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($statusPath, 'status pegawai lain');
        Storage::disk(Document::STORAGE_DISK)->put($snapshotPath, 'identitas sensitif');
        Storage::disk(Document::STORAGE_DISK)->put($nonPensionPath, 'SK pensiun salah konteks');
        $admin = User::factory()->adminKepegawaian()->create();
        $pimpinan = User::factory()->pimpinan()->create();
        $adminStatusUrl = $this->url($employee, 'status', $history->id);
        $adminSnapshotUrl = $this->url($employee, 'status-snapshot', $employee->id);
        $pimpinanStatusUrl = route('pimpinan.pegawai.status-attachments.download', [
            'employee' => $employee,
            'history' => $history,
        ]);

        $this->actingAs($admin)->get($adminStatusUrl)->assertNotFound();
        $this->actingAs($admin)->get($adminSnapshotUrl)->assertNotFound();
        $this->actingAs($admin)->get($this->url($employee, 'status', $nonPensionHistory->id))->assertNotFound();
        $this->actingAs($pimpinan)->get($pimpinanStatusUrl)->assertNotFound();
        $this->actingAs($pimpinan)
            ->get(route('pimpinan.pegawai.status-attachments.download', [
                'employee' => $employee,
                'history' => $nonPensionHistory,
            ]))
            ->assertNotFound();
        $this->actingAs($pimpinan)
            ->get(route('pimpinan.pegawai.show', $employee))
            ->assertOk()
            ->assertDontSee($pimpinanStatusUrl, false);
    }

    public function test_edit_admin_tidak_merender_tautan_attachment_riwayat_yang_file_privatnya_hilang(): void
    {
        $employee = Employee::factory()->create();
        $attachments = $this->historyAttachments($employee);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('pegawai.edit', $employee))
            ->assertOk();

        foreach ($attachments as [$type, $history]) {
            if (in_array($type, ['rank', 'position', 'salary', 'appointment'], true)) {
                $response->assertDontSee($this->url($employee, $type, $history->id), false);
            }
        }
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

    private function pimpinanUrl(Employee $employee, string $type, string $history): string
    {
        return '/pimpinan/pegawai/'.$employee->id.'/attachment-riwayat/'.$type.'/'.$history.'/unduh';
    }
}
