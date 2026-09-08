<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Document;
use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefUnitKerja;
use App\Models\SalaryHistory;
use App\Models\SkRequirement;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\SkRequirementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeProfileDocumentMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(SkRequirementSeeder::class);
        $this->seed(RbacSeeder::class);
        Storage::fake(Document::STORAGE_DISK);
    }

    public function test_profile_shows_all_four_missing_required_sk_for_default_pns_matrix(): void
    {
        $pns = RefJenisPegawai::query()->where('nama', 'PNS')->firstOrFail();
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);

        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee).'?tab=docs');

        $response
            ->assertOk()
            ->assertSee('data-document-status="belum_ada"', false)
            ->assertSee('data-document-is-dinilai="true"', false)
            ->assertSee('data-document-tersedia="0"', false)
            ->assertSee('data-document-total-wajib="4"', false)
            ->assertSee('0 dari 4 SK tersedia dan valid.')
            ->assertSee('data-required-sk="sk_pengangkatan"', false)
            ->assertSee('data-required-sk="sk_pangkat"', false)
            ->assertSee('data-required-sk="sk_jabatan"', false)
            ->assertSee('data-required-sk="sk_kgb"', false)
            ->assertSee('data-required-sk-status="belum_ada"', false);
        $this->assertSame(
            4,
            substr_count($response->getContent(), 'data-required-sk-status="belum_ada"'),
        );
    }

    public function test_profile_only_shows_categories_from_active_custom_matrix(): void
    {
        $pns = RefJenisPegawai::query()->where('nama', 'PNS')->firstOrFail();
        SkRequirement::query()
            ->where('jenis_pegawai_id', $pns->id)
            ->update(['is_wajib' => false]);
        SkRequirement::query()
            ->where('jenis_pegawai_id', $pns->id)
            ->whereIn('sk_key', ['sk_pengangkatan', 'sk_pangkat', 'sk_jabatan'])
            ->update(['is_wajib' => true]);

        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);
        $kgbPath = 'documents/sk-kgb/'.$employee->id.'.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($kgbPath, 'SK KGB nonwajib');
        Document::query()->create([
            'employee_id' => $employee->id,
            'nama_dokumen' => 'SK KGB Arsip',
            'jenis_dokumen' => 'sk_kgb',
            'file_path' => $kgbPath,
        ]);

        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee).'?tab=docs');

        $response
            ->assertOk()
            ->assertSee('data-document-status="belum_ada"', false)
            ->assertSee('data-document-total-wajib="3"', false)
            ->assertSee('data-required-sk="sk_pengangkatan"', false)
            ->assertSee('data-required-sk="sk_pangkat"', false)
            ->assertSee('data-required-sk="sk_jabatan"', false)
            ->assertDontSee('data-required-sk="sk_kgb"', false);
    }

    public function test_profile_keeps_sk_archives_visible_without_counting_them_as_active_requirements(): void
    {
        $pns = RefJenisPegawai::query()->where('nama', 'PNS')->firstOrFail();
        SkRequirement::query()
            ->where('jenis_pegawai_id', $pns->id)
            ->update(['is_wajib' => false]);
        SkRequirement::query()
            ->where('jenis_pegawai_id', $pns->id)
            ->whereIn('sk_key', ['sk_pengangkatan', 'sk_pangkat', 'sk_jabatan'])
            ->update(['is_wajib' => true]);

        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);
        $archives = collect([
            ['jenis_dokumen' => 'sk_kgb', 'nama_dokumen' => 'SK KGB Lama', 'file_path' => 'documents/archive/kgb-lama.pdf'],
            ['jenis_dokumen' => 'sk_hukuman_disiplin', 'nama_dokumen' => 'SK Hukuman Disiplin Lama', 'file_path' => 'documents/archive/hukuman-lama.pdf'],
            ['jenis_dokumen' => 'sk_mutasi', 'nama_dokumen' => 'SK Mutasi Lama', 'file_path' => 'documents/archive/mutasi-lama.pdf'],
            ['jenis_dokumen' => 'SK', 'nama_dokumen' => 'SK Legacy', 'file_path' => 'documents/archive/sk-legacy.pdf'],
        ])->map(function (array $attributes) use ($employee): Document {
            Storage::disk(Document::STORAGE_DISK)->put($attributes['file_path'], 'arsip SK');

            return Document::query()->create([
                'employee_id' => $employee->id,
                ...$attributes,
            ]);
        });

        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee).'?tab=docs');

        $response
            ->assertOk()
            ->assertSee('data-document-status="belum_ada"', false)
            ->assertSee('data-document-tersedia="0"', false)
            ->assertSee('data-document-total-wajib="3"', false)
            ->assertDontSee('data-required-sk="sk_kgb"', false)
            ->assertSee('data-employee-detail-table="arsip-sk"', false);

        foreach ($archives as $archive) {
            $response
                ->assertSee('data-archived-sk="'.$archive->id.'"', false)
                ->assertSee($archive->nama_dokumen)
                ->assertSee(route('dokumen.show', $archive), false)
                ->assertSee(route('dokumen.download', $archive), false);
        }
    }

    public function test_profile_keeps_sk_archives_visible_when_employee_is_not_evaluated(): void
    {
        $pppk = RefJenisPegawai::query()->where('nama', 'PPPK')->firstOrFail();
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pppk->id]);
        $filePath = 'documents/archive/pppk-mutasi.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($filePath, 'arsip mutasi PPPK');
        $archive = Document::query()->create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_mutasi',
            'nama_dokumen' => 'SK Mutasi PPPK',
            'file_path' => $filePath,
        ]);

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee).'?tab=docs')
            ->assertOk()
            ->assertSee('data-document-status="tidak_dinilai"', false)
            ->assertSee('data-document-total-wajib="0"', false)
            ->assertSee('data-archived-sk="'.$archive->id.'"', false)
            ->assertSee('SK Mutasi PPPK')
            ->assertSee(route('dokumen.download', $archive), false);
    }

    public function test_profile_marks_pppk_without_active_matrix_as_not_evaluated(): void
    {
        $pppk = RefJenisPegawai::query()->where('nama', 'PPPK')->firstOrFail();
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pppk->id]);

        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee).'?tab=docs');

        $response
            ->assertOk()
            ->assertSee('data-document-status="tidak_dinilai"', false)
            ->assertSee('data-document-is-dinilai="false"', false)
            ->assertSee('data-document-total-wajib="0"', false)
            ->assertSee('Tidak Dinilai')
            ->assertSee('Matriks SK wajib belum dikonfigurasi')
            ->assertDontSee('data-required-sk=', false);
    }

    public function test_profile_evaluates_pppk_after_custom_matrix_is_activated(): void
    {
        $pppk = RefJenisPegawai::query()->where('nama', 'PPPK')->firstOrFail();
        SkRequirement::query()->create([
            'jenis_pegawai_id' => $pppk->id,
            'sk_key' => 'sk_pengangkatan',
            'is_wajib' => true,
        ]);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pppk->id]);
        $filePath = 'appointments/sk/'.$employee->id.'.pdf';
        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PPPK',
            'tmt_pengangkatan' => '2026-01-01',
            'no_sk' => 'SK-PPPK-PROFIL-001',
            'tanggal_sk' => '2025-12-20',
            'file_sk' => $filePath,
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($filePath, 'SK pengangkatan PPPK');

        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee).'?tab=docs');

        $response
            ->assertOk()
            ->assertSee('data-document-status="lengkap"', false)
            ->assertSee('data-document-is-dinilai="true"', false)
            ->assertSee('data-document-tersedia="1"', false)
            ->assertSee('data-document-total-wajib="1"', false)
            ->assertSee('data-required-sk="sk_pengangkatan"', false)
            ->assertSee('data-required-sk-status="tersedia"', false)
            ->assertSee('SK-PPPK-PROFIL-001');
    }

    public function test_document_status_uses_first_compatible_appointment(): void
    {
        $pns = RefJenisPegawai::query()->where('nama', 'PNS')->firstOrFail();
        SkRequirement::query()
            ->where('jenis_pegawai_id', $pns->id)
            ->update(['is_wajib' => false]);
        SkRequirement::query()
            ->where('jenis_pegawai_id', $pns->id)
            ->where('sk_key', 'sk_pengangkatan')
            ->update(['is_wajib' => true]);

        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);
        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2010-01-01',
            'no_sk' => 'SK-PENGANGKATAN-PERTAMA-PNS',
            'tanggal_sk' => '2009-12-20',
            'file_sk' => null,
        ]);
        $latestPath = 'appointments/sk/'.$employee->id.'-terbaru.pdf';
        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-PENGANGKATAN-TERBARU-PNS',
            'tanggal_sk' => '2019-12-20',
            'file_sk' => $latestPath,
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($latestPath, 'SK pengangkatan terbaru');

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->getJson("/api/v1/pegawai/{$employee->id}/status-dokumen")
            ->assertOk()
            ->assertJsonPath('document_status.status_kelengkapan', 'perlu_perbaikan')
            ->assertJsonPath('document_status.tersedia_count', 0)
            ->assertJsonPath('document_status.required_sks.0.nomor_sk', 'SK-PENGANGKATAN-PERTAMA-PNS')
            ->assertJsonPath('document_status.required_sks.0.status', 'perlu_perbaikan')
            ->assertJsonPath('document_status.required_sks.0.file_url', null);
    }

    public function test_list_table_row_and_status_dokumen_agree_on_appointment_compatibility(): void
    {
        $pns = RefJenisPegawai::query()->where('nama', 'PNS')->firstOrFail();
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);
        $this->createCompleteHistories($employee, 'PNS', 'SK-PENGANGKATAN-KONSISTEN');

        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->getJson("/api/v1/pegawai/{$employee->id}/status-dokumen")
            ->assertOk()
            ->assertJsonPath('document_status.required_sks.0.nomor_sk', 'SK-PENGANGKATAN-KONSISTEN');

        $this->actingAs($user)
            ->getJson("/api/v1/pegawai/{$employee->id}/table-row")
            ->assertOk()
            ->assertJsonPath('employee.is_lengkap', 'lengkap');

        $this->actingAs($user)
            ->getJson('/api/v1/pegawai?search='.urlencode($employee->nip))
            ->assertOk()
            ->assertJsonPath('employees.data.0.is_lengkap', 'lengkap');
    }

    private function createCompleteHistories(Employee $employee, string $appointmentType, string $appointmentNoSk): void
    {
        $paths = [
            'appointment' => "appointments/sk/{$employee->id}-konsisten.pdf",
            'rank' => "ranks/sk/{$employee->id}-konsisten.pdf",
            'position' => "positions/sk/{$employee->id}-konsisten.pdf",
            'salary' => "salaries/sk/{$employee->id}-konsisten.pdf",
        ];

        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => $appointmentType,
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => $appointmentNoSk,
            'tanggal_sk' => '2019-12-20',
            'file_sk' => $paths['appointment'],
        ]);
        RankHistory::query()->create([
            'employee_id' => $employee->id,
            'golongan_id' => RefGolongan::where('kode', 'III/a')->firstOrFail()->id,
            'tmt_pangkat' => '2026-01-01',
            'no_sk' => 'SK-PANGKAT-KONSISTEN',
            'tanggal_sk' => '2025-12-20',
            'file_sk' => $paths['rank'],
            'is_latest' => true,
        ]);
        PositionHistory::query()->create([
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Analis Kepegawaian',
            'jenis_jabatan_id' => RefJenisJabatan::where('nama', 'Struktural')->firstOrFail()->id,
            'unit_kerja_id' => RefUnitKerja::firstOrFail()->id,
            'tmt_jabatan' => '2026-01-01',
            'no_sk' => 'SK-JABATAN-KONSISTEN',
            'tanggal_sk' => '2025-12-20',
            'file_sk' => $paths['position'],
            'is_latest' => true,
        ]);
        SalaryHistory::query()->create([
            'employee_id' => $employee->id,
            'tmt_kgb' => '2026-01-01',
            'gaji_pokok' => 5000000,
            'no_sk' => 'SK-KGB-KONSISTEN',
            'tanggal_sk' => '2025-12-20',
            'file_sk' => $paths['salary'],
            'is_latest' => true,
        ]);

        foreach ($paths as $path) {
            Storage::disk(Document::STORAGE_DISK)->put($path, 'SK tersedia');
        }
    }

    public function test_document_status_ignores_incompatible_appointment_type(): void
    {
        $pns = RefJenisPegawai::query()->where('nama', 'PNS')->firstOrFail();
        SkRequirement::query()
            ->where('jenis_pegawai_id', $pns->id)
            ->update(['is_wajib' => false]);
        SkRequirement::query()
            ->where('jenis_pegawai_id', $pns->id)
            ->where('sk_key', 'sk_pengangkatan')
            ->update(['is_wajib' => true]);

        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);
        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'CPNS',
            'tmt_pengangkatan' => '2010-01-01',
            'no_sk' => 'SK-PENGANGKATAN-CPNS-LAMA',
            'tanggal_sk' => '2009-12-20',
            'file_sk' => null,
        ]);
        $latestPath = 'appointments/sk/'.$employee->id.'-terbaru-pns.pdf';
        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-PENGANGKATAN-PNS-VALID',
            'tanggal_sk' => '2019-12-20',
            'file_sk' => $latestPath,
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($latestPath, 'SK pengangkatan terbaru');

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->getJson("/api/v1/pegawai/{$employee->id}/status-dokumen")
            ->assertOk()
            ->assertJsonPath('document_status.status_kelengkapan', 'lengkap')
            ->assertJsonPath('document_status.tersedia_count', 1)
            ->assertJsonPath('document_status.required_sks.0.nomor_sk', 'SK-PENGANGKATAN-PNS-VALID')
            ->assertJsonPath('document_status.required_sks.0.status', 'tersedia');
    }
}
