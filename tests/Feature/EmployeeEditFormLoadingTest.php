<?php

namespace Tests\Feature;

use App\Actions\Employees\PrepareEmployeeEditFormDataAction;
use App\Models\Appointment;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefUnitKerja;
use App\Models\Role;
use App\Models\SalaryHistory;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeEditFormLoadingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceSeeder::class, RbacSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => 'admin_kepegawaian']));
    }

    public function test_form_hanya_memuat_riwayat_terkini_dan_pengangkatan_yang_digunakan(): void
    {
        [$employee, $rank, $position, $salary, $firstAppointment, $latestPppk] = $this->historyFixture();

        $data = app(PrepareEmployeeEditFormDataAction::class)->execute($employee->id, true);
        $this->assertSame($rank->id, $data['latestRank']->id);
        $this->assertSame($position->id, $data['latestPosition']->id);
        $this->assertSame($salary->id, $data['latestSalary']->id);
        $this->assertSame($firstAppointment->id, $data['p']->appointment->id);
        foreach (['rankHistories', 'positionHistories', 'salaryHistories'] as $relation) {
            $this->assertCount(1, $data['p']->getRelation($relation), 'Riwayat lama tidak diperlukan untuk ringkasan form edit.');
        }
        $this->assertSame([$latestPppk->id], $data['p']->appointments->modelKeys());

        $response = $this->get(route('rbac.pegawai.edit', $employee))->assertOk();
        $response->assertSee('Jabatan Terkini Form')->assertSee('SK-PENGANGKATAN-AWAL');
        $this->assertMatchesRegularExpression('/id="pppk_tmt_pengangkatan"[^>]*value="2025-06-01"/s', $response->getContent());
    }

    public function test_form_tidak_memuat_seluruh_pilihan_arsip_sebelum_dicari(): void
    {
        $employee = Employee::factory()->create();
        foreach ($this->archiveCategories() as $category) {
            $this->documentFixture($employee, $category, '2020-01-01');
            $this->documentFixture($employee, $category, '2026-01-01');
        }

        $data = app(PrepareEmployeeEditFormDataAction::class)->execute($employee->id, true);
        $this->assertArrayHasKey('archiveSelections', $data);
        $this->assertTrue($data['canReadDocuments']);
        $this->assertSame([], $data['archiveSelections']);
        foreach (array_keys($this->archiveCategories()) as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
        $this->assertFalse($data['p']->relationLoaded('documents'), 'Daftar arsip hanya dimuat melalui pencarian berhalaman.');
    }

    public function test_form_memulihkan_hanya_pilihan_arsip_lama_dengan_pemilik_dan_kategori_sah(): void
    {
        $employee = Employee::factory()->create();
        $rank = $this->documentFixture($employee, 'sk_pangkat', '2020-01-01');
        $appointment = $this->documentFixture($employee, 'sk_pengangkatan', '2020-01-01');
        $foreign = $this->documentFixture(Employee::factory()->create(), 'sk_kgb', '2026-01-01');
        $this->withSession(['_old_input' => [
            'existing_document_id_pangkat' => $rank->id,
            'existing_document_id_jabatan' => $rank->id,
            'existing_document_id_kgb' => $foreign->id,
            'existing_document_id_pengangkatan' => $appointment->id,
        ]]);
        request()->setLaravelSession(app('session.store'));
        $data = app(PrepareEmployeeEditFormDataAction::class)->execute($employee->id, true);
        $this->assertSame(['sk_pangkat', 'sk_pengangkatan'], array_keys($data['archiveSelections']));
        $this->assertSame($rank->id, $data['archiveSelections']['sk_pangkat']['id']);
        $this->assertSame($appointment->id, $data['archiveSelections']['sk_pengangkatan']['id']);
        $this->assertSame(['id', 'label', 'nomor_dokumen', 'tanggal_dokumen', 'nama_dokumen'], array_keys($data['archiveSelections']['sk_pangkat']));

        $this->revoke('dokumen_sk.read');
        $this->assertSame([], app(PrepareEmployeeEditFormDataAction::class)->execute($employee->id, true)['archiveSelections']);
    }

    public function test_id_arsip_lama_tidak_valid_tidak_mencapai_query_uuid(): void
    {
        $this->withSession(['_old_input' => ['existing_document_id_pangkat' => 'bukan-uuid', 'existing_document_id_kgb' => ['array']]]);
        request()->setLaravelSession(app('session.store'));
        $data = app(PrepareEmployeeEditFormDataAction::class)->execute(Employee::factory()->create()->id, true);
        $this->assertSame([], $data['archiveSelections']);
    }

    public function test_form_memulihkan_isian_riwayat_bersama_pilihan_arsip_setelah_validasi_gagal(): void
    {
        [$employee] = $this->historyFixture();
        $forms = [
            'pangkat' => ['golongan_id' => RefGolongan::firstOrFail()->id, 'no_sk' => 'DRAFT-PANGKAT', 'tanggal_sk' => '2026-04-01', 'tmt_pangkat' => '2026-05-01'],
            'jabatan' => [
                'jabatan_id' => RefJabatan::firstOrFail()->id,
                'jenis_jabatan_id' => RefJenisJabatan::firstOrFail()->id,
                'eselon_id' => RefEselon::firstOrFail()->id,
                'unit_kerja_id' => RefUnitKerja::firstOrFail()->id,
                'kelas_jabatan' => '9', 'no_sk' => 'DRAFT-JABATAN', 'tanggal_sk' => '2026-06-01', 'tmt_jabatan' => '2026-07-01',
            ],
            'kgb' => ['gaji_pokok' => '3500000', 'no_sk' => 'DRAFT-KGB', 'tanggal_sk' => '2026-08-01', 'tmt_kgb' => '2026-09-01'],
        ];
        $oldInput = [
            'pengangkatan_jenis_pengangkatan' => 'PNS',
            'pengangkatan_no_sk' => 'DRAFT-PENGANGKATAN',
            'pengangkatan_tanggal_sk' => '2026-02-01',
            'pengangkatan_tmt_pengangkatan' => '2026-03-01',
        ];
        foreach ($forms as $prefix => $fields) {
            foreach ($fields as $field => $value) {
                $oldInput[$prefix.'_'.$field] = $value;
            }
        }
        foreach (['pangkat', 'jabatan', 'kgb', 'pengangkatan'] as $type) {
            $oldInput['existing_document_id_'.$type] = $this->documentFixture($employee, 'sk_'.$type, '2020-01-01')->id;
        }

        foreach ([[], $oldInput] as $submitted) {
            $response = $this->withSession(['_old_input' => $submitted])->get(route('rbac.pegawai.edit', $employee))->assertOk();
            $html = html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5);
            foreach ($forms as $prefix => $fields) {
                $this->assertSame(1, preg_match('/'.$prefix.'Form:\s*\{([^}]+)\}/s', $html, $matches));
                foreach ($fields as $field => $value) {
                    $expected = preg_quote((string) ($submitted[$prefix.'_'.$field] ?? ''), '/');
                    $this->assertMatchesRegularExpression('/\b'.$field.':\s*([\'"])'.$expected.'\1/', $matches[1], 'Isian '.$prefix.'_'.$field.' harus mengikuti draft, bukan riwayat tersimpan.');
                }
            }
            foreach (['no_sk' => 'SK-PENGANGKATAN-AWAL', 'tanggal_sk' => '', 'tmt_pengangkatan' => '2010-01-01'] as $field => $fallback) {
                $expected = preg_quote((string) ($submitted['pengangkatan_'.$field] ?? $fallback), '/');
                $this->assertMatchesRegularExpression('/id="pengangkatan_'.$field.'"[^>]*value="'.$expected.'"/s', $html);
            }
            $kind = $submitted['pengangkatan_jenis_pengangkatan'] ?? 'CPNS';
            $this->assertMatchesRegularExpression('/id="pengangkatan_jenis_pengangkatan"[^>]*>.*?<option value="'.$kind.'"\s+selected\b/s', $html);
        }
    }

    public function test_hak_dokumen_tidak_memuat_riwayat_ketika_permission_histori_dicabut(): void
    {
        [$employee] = $this->historyFixture();
        $this->documentFixture($employee, 'sk_pangkat', '2026-01-01');
        $this->revoke('employee_histories.read');

        $data = app(PrepareEmployeeEditFormDataAction::class)->execute($employee->id, true);
        foreach (['latestRank', 'latestPosition', 'latestSalary'] as $key) {
            $this->assertNull($data[$key]);
        }
        foreach (['rankHistories', 'positionHistories', 'salaryHistories', 'appointments'] as $relation) {
            $this->assertCount(0, $data['p']->getRelation($relation));
        }
        $this->assertNull($data['p']->appointment);
        $this->assertTrue($data['canReadDocuments']);
        $this->assertSame([], $data['archiveSelections']);
        $this->get(route('rbac.pegawai.edit', $employee))->assertOk()->assertDontSee('SK-PENGANGKATAN-AWAL');
    }

    public function test_pencabutan_hak_dokumen_mengosongkan_arsip_tanpa_menghilangkan_ringkasan_histori(): void
    {
        [$employee, $rank, $position, $salary] = $this->historyFixture();
        foreach ($this->archiveCategories() as $category) {
            $this->documentFixture($employee, $category, '2026-01-01');
        }
        $this->revoke('dokumen_sk.read');

        $data = app(PrepareEmployeeEditFormDataAction::class)->execute($employee->id, true);
        foreach (['latestRank' => $rank, 'latestPosition' => $position, 'latestSalary' => $salary] as $key => $history) {
            $this->assertSame($history->id, $data[$key]->id);
            $this->assertNull($data[$key]->admin_attachment_download_url);
        }
        $this->assertFalse($data['canReadDocuments']);
        $this->assertSame([], $data['archiveSelections']);
        $this->get(route('rbac.pegawai.edit', $employee))->assertOk()->assertSee('Jabatan Terkini Form');
    }

    /** Riwayat lama mencakup tanggal lebih baru agar pemilihan tetap mengikuti penanda resmi is_latest. */
    private function historyFixture(): array
    {
        $employee = Employee::factory()->create(['jenis_pegawai_id' => RefJenisPegawai::where('nama', 'PPPK')->firstOrFail()->id]);
        $golonganId = RefGolongan::firstOrFail()->id;
        foreach (['2020-01-01', '2028-01-01'] as $date) {
            RankHistory::create(['employee_id' => $employee->id, 'golongan_id' => $golonganId, 'tmt_pangkat' => $date, 'is_latest' => false]);
            PositionHistory::create(['employee_id' => $employee->id, 'nama_jabatan' => 'Jabatan Lama Form', 'tmt_jabatan' => $date, 'is_latest' => false]);
            SalaryHistory::create(['employee_id' => $employee->id, 'gaji_pokok' => 1000000, 'tmt_kgb' => $date, 'is_latest' => false]);
        }
        $rank = RankHistory::create(['employee_id' => $employee->id, 'golongan_id' => $golonganId, 'tmt_pangkat' => '2025-01-01', 'is_latest' => true]);
        $position = PositionHistory::create(['employee_id' => $employee->id, 'nama_jabatan' => 'Jabatan Terkini Form', 'tmt_jabatan' => '2025-01-01', 'is_latest' => true]);
        $salary = SalaryHistory::create(['employee_id' => $employee->id, 'gaji_pokok' => 2000000, 'tmt_kgb' => '2025-01-01', 'is_latest' => true]);
        $firstAppointment = Appointment::create(['employee_id' => $employee->id, 'jenis_pengangkatan' => 'CPNS', 'tmt_pengangkatan' => '2010-01-01', 'no_sk' => 'SK-PENGANGKATAN-AWAL']);
        Appointment::create(['employee_id' => $employee->id, 'jenis_pengangkatan' => 'PPPK', 'tmt_pengangkatan' => '2020-01-01']);
        $latestPppk = Appointment::create(['employee_id' => $employee->id, 'jenis_pengangkatan' => 'PPPK', 'tmt_pengangkatan' => '2025-06-01']);
        Appointment::create(['employee_id' => $employee->id, 'jenis_pengangkatan' => 'PPPK', 'tmt_pengangkatan' => null]);
        Appointment::create(['employee_id' => $employee->id, 'jenis_pengangkatan' => 'PNS', 'tmt_pengangkatan' => '2026-01-01']);

        return [$employee, $rank, $position, $salary, $firstAppointment, $latestPppk];
    }

    private function documentFixture(Employee $employee, string $category, string $date): Document
    {
        return Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => $category,
            'nama_dokumen' => 'Dokumen '.$category.' '.$date,
            'nomor_dokumen' => 'SK-'.$date,
            'tanggal_dokumen' => $date,
            'file_path' => 'pegawai/'.$employee->id.'/'.$category.'-'.$date.'.pdf',
        ]);
    }

    /** @return array<string, string> */
    private function archiveCategories(): array
    {
        return ['arsipPangkat' => 'sk_pangkat', 'arsipJabatan' => 'sk_jabatan', 'arsipKgb' => 'sk_kgb', 'arsipPengangkatan' => 'sk_pengangkatan'];
    }

    private function revoke(string $permission): void
    {
        Role::where('name', 'admin_kepegawaian')->firstOrFail()->permissions()
            ->detach(Permission::where('name', $permission)->firstOrFail()->id);
    }
}
