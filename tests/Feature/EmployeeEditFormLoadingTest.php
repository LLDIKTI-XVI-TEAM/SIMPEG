<?php

namespace Tests\Feature;

use App\Actions\Employees\PrepareEmployeeEditFormDataAction;
use App\Models\Appointment;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJenisPegawai;
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

    public function test_pilihan_arsip_tetap_lengkap_tanpa_duplikasi_relasi_atau_path_internal(): void
    {
        $employee = Employee::factory()->create();
        $other = Employee::factory()->create();
        $expectedIds = [];
        foreach ($this->archiveCategories() as $key => $category) {
            $old = $this->documentFixture($employee, $category, '2020-01-01');
            $new = $this->documentFixture($employee, $category, '2026-01-01');
            $this->documentFixture($other, $category, '2026-02-01');
            $expectedIds[$key] = [$new->id, $old->id];
        }
        $this->documentFixture($employee, 'ktp_kk', '2026-03-01');

        $data = app(PrepareEmployeeEditFormDataAction::class)->execute($employee->id, true);
        foreach ($expectedIds as $key => $ids) {
            $this->assertSame($ids, $data[$key]->pluck('id')->all());
            foreach ($data[$key] as $document) {
                $this->assertArrayNotHasKey('file_path', $document);
                $this->assertSame(['id', 'label', 'nomor_dokumen', 'tanggal_dokumen', 'nama_dokumen'], array_keys($document));
            }
        }
        $this->assertFalse($data['p']->relationLoaded('documents'), 'Dokumen yang sudah menjadi pilihan arsip tidak dimuat lagi sebagai relasi penuh.');
    }

    public function test_hak_dokumen_tidak_memuat_riwayat_ketika_permission_histori_dicabut(): void
    {
        [$employee] = $this->historyFixture();
        $document = $this->documentFixture($employee, 'sk_pangkat', '2026-01-01');
        $this->revoke('employee_histories.read');

        $data = app(PrepareEmployeeEditFormDataAction::class)->execute($employee->id, true);
        foreach (['latestRank', 'latestPosition', 'latestSalary'] as $key) {
            $this->assertNull($data[$key]);
        }
        foreach (['rankHistories', 'positionHistories', 'salaryHistories', 'appointments'] as $relation) {
            $this->assertCount(0, $data['p']->getRelation($relation));
        }
        $this->assertNull($data['p']->appointment);
        $this->assertSame([$document->id], $data['arsipPangkat']->pluck('id')->all());
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
        foreach (array_keys($this->archiveCategories()) as $key) {
            $this->assertCount(0, $data[$key]);
        }
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
