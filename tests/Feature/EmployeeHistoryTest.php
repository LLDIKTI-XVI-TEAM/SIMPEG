<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\RefUnitKerja;
use App\Models\Role;
use App\Models\SalaryHistory;
use App\Models\User;
use App\Services\EmployeeHistoryService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EmployeeHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_employees_table_has_computed_date_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('employees', 'tanggal_kenaikan_pangkat_berikutnya'));
        $this->assertTrue(Schema::hasColumn('employees', 'tanggal_kgb_berikutnya'));
    }

    public function test_employee_computed_dates_are_mass_assignable_and_cast_to_dates(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_kenaikan_pangkat_berikutnya' => '2028-04-01',
            'tanggal_kgb_berikutnya' => '2027-06-01',
        ]);

        $this->assertInstanceOf(Carbon::class, $employee->tanggal_kenaikan_pangkat_berikutnya);
        $this->assertInstanceOf(Carbon::class, $employee->tanggal_kgb_berikutnya);
        $this->assertSame('2028-04-01', $employee->tanggal_kenaikan_pangkat_berikutnya->format('Y-m-d'));
        $this->assertSame('2027-06-01', $employee->tanggal_kgb_berikutnya->format('Y-m-d'));
    }

    public function test_admin_can_create_rank_history_append_only_and_update_next_rank_date(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $oldGolongan = RefGolongan::where('kode', 'III/a')->firstOrFail();
        $newGolongan = RefGolongan::where('kode', 'III/b')->firstOrFail();

        $oldHistory = RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $oldGolongan->id,
            'tmt_pangkat' => '2020-01-01',
            'no_sk' => 'SK-OLD',
            'tanggal_sk' => '2020-01-10',
            'is_latest' => true,
        ]);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan", [
            'golongan_id' => $newGolongan->id,
            'tmt_pangkat' => '2026-02-01',
            'no_sk' => 'SK-RANK-001',
            'tanggal_sk' => '2026-02-10',
            'file_sk' => 'sk/rank-001.pdf',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('message', 'Riwayat kepangkatan berhasil ditambahkan.');
        $response->assertJsonPath('history.is_latest', true);

        $this->assertFalse($oldHistory->fresh()->is_latest);
        $this->assertDatabaseHas('rank_histories', [
            'employee_id' => $employee->id,
            'golongan_id' => $newGolongan->id,
            'no_sk' => 'SK-RANK-001',
            'is_latest' => true,
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'golongan_terakhir' => 'III/b',
            'pangkat_terakhir' => 'Penata Muda Tingkat 1',
        ]);
        $this->assertSame('2030-02-01', $employee->fresh()->tanggal_kenaikan_pangkat_berikutnya->format('Y-m-d'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CREATE',
            'auditable_type' => 'RankHistory',
        ]);
    }

    public function test_backdated_rank_history_does_not_replace_current_latest_snapshot(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'golongan_terakhir' => 'III/b',
            'pangkat_terakhir' => 'Penata Muda Tingkat 1',
            'tanggal_kenaikan_pangkat_berikutnya' => '2030-02-01',
        ]);
        $oldGolongan = RefGolongan::where('kode', 'III/a')->firstOrFail();
        $latestGolongan = RefGolongan::where('kode', 'III/b')->firstOrFail();

        $latestHistory = RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $latestGolongan->id,
            'tmt_pangkat' => '2026-02-01',
            'no_sk' => 'SK-RANK-LATEST',
            'tanggal_sk' => '2026-02-10',
            'is_latest' => true,
        ]);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan", [
            'golongan_id' => $oldGolongan->id,
            'tmt_pangkat' => '2024-01-01',
            'no_sk' => 'SK-RANK-BACKDATED',
            'tanggal_sk' => '2024-01-10',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('history.is_latest', false);
        $this->assertTrue($latestHistory->fresh()->is_latest);
        $this->assertDatabaseHas('rank_histories', [
            'employee_id' => $employee->id,
            'no_sk' => 'SK-RANK-BACKDATED',
            'is_latest' => false,
        ]);
        $employee->refresh();
        $this->assertSame('III/b', $employee->golongan_terakhir);
        $this->assertSame('Penata Muda Tingkat 1', $employee->pangkat_terakhir);
        $this->assertSame('2030-02-01', $employee->tanggal_kenaikan_pangkat_berikutnya->format('Y-m-d'));
    }

    public function test_service_reloads_employee_inside_transaction_before_latest_rank_update(): void
    {
        $employee = Employee::factory()->create();
        $golongan = RefGolongan::where('kode', 'III/b')->firstOrFail();

        DB::enableQueryLog();

        app(EmployeeHistoryService::class)->createRankHistory($employee, [
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => '2026-02-01',
            'no_sk' => 'SK-RANK-LOCK',
            'tanggal_sk' => '2026-02-10',
        ]);

        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $query): string => strtolower($query));

        $employeeReloadIndex = $queries->search(fn (string $query): bool => str_contains($query, 'from "employees"') && str_contains($query, 'where "employees"."id"'));
        $clearLatestIndex = $queries->search(fn (string $query): bool => str_contains($query, 'update "rank_histories"') && str_contains($query, '"is_latest"'));

        $this->assertIsInt($employeeReloadIndex);
        $this->assertIsInt($clearLatestIndex);
        $this->assertLessThan($clearLatestIndex, $employeeReloadIndex);
    }

    public function test_admin_can_list_rank_histories_ordered_with_golongan_data(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $golonganA = RefGolongan::where('kode', 'III/a')->firstOrFail();
        $golonganB = RefGolongan::where('kode', 'III/b')->firstOrFail();
        $golonganC = RefGolongan::where('kode', 'III/c')->firstOrFail();

        $oldHistory = RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golonganA->id,
            'tmt_pangkat' => '2024-01-01',
            'no_sk' => 'SK-RANK-OLD',
            'tanggal_sk' => '2024-01-10',
            'is_latest' => false,
        ]);
        $oldHistory->forceFill(['created_at' => '2024-01-10 08:00:00'])->save();

        $olderSameTmtHistory = RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golonganB->id,
            'tmt_pangkat' => '2026-01-01',
            'no_sk' => 'SK-RANK-SAME-TMT-OLDER',
            'tanggal_sk' => '2026-01-10',
            'is_latest' => false,
        ]);
        $olderSameTmtHistory->forceFill(['created_at' => '2026-01-10 08:00:00'])->save();

        $newerSameTmtHistory = RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golonganC->id,
            'tmt_pangkat' => '2026-01-01',
            'no_sk' => 'SK-RANK-SAME-TMT-NEWER',
            'tanggal_sk' => '2026-01-11',
            'is_latest' => true,
        ]);
        $newerSameTmtHistory->forceFill(['created_at' => '2026-01-11 08:00:00'])->save();

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan");

        $response->assertOk();
        $response->assertJsonPath('employee_id', $employee->id);
        $response->assertJsonCount(3, 'histories');
        $response->assertJsonPath('histories.0.no_sk', 'SK-RANK-SAME-TMT-NEWER');
        $response->assertJsonPath('histories.0.golongan.kode', 'III/c');
        $response->assertJsonPath('histories.1.no_sk', 'SK-RANK-SAME-TMT-OLDER');
        $response->assertJsonPath('histories.1.golongan.kode', 'III/b');
        $response->assertJsonPath('histories.2.no_sk', 'SK-RANK-OLD');
        $response->assertJsonPath('histories.2.golongan.kode', 'III/a');
    }

    public function test_unauthenticated_request_cannot_list_rank_histories(): void
    {
        $employee = Employee::factory()->create();

        $response = $this->getJson("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan");

        $response->assertRedirect('/login');
    }

    public function test_pegawai_cannot_list_rank_histories(): void
    {
        $user = User::factory()->pegawai()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan");

        $response->assertForbidden();
    }

    public function test_admin_without_employee_histories_read_permission_cannot_list_rank_histories(): void
    {
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employee_histories.read')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);

        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan");

        $response->assertForbidden();
    }

    public function test_rank_history_validation_rejects_missing_required_fields(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan", []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['golongan_id', 'tmt_pangkat', 'no_sk', 'tanggal_sk']);
    }

    public function test_history_file_sk_must_be_controlled_relative_pdf_path(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $jenisJabatan = RefJenisJabatan::where('nama', 'Struktural')->firstOrFail();
        $unitKerja = RefUnitKerja::firstOrFail();

        $this->actingAs($user);

        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan", [
            ...$this->validRankHistoryPayload(),
            'file_sk' => '../secret.pdf',
        ])->assertJsonValidationErrors(['file_sk']);

        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/riwayat-jabatan", [
            'nama_jabatan' => 'Kepala Bagian Umum',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'unit_kerja_id' => $unitKerja->id,
            'tmt_jabatan' => '2026-03-01',
            'no_sk' => 'SK-POS-INVALID',
            'tanggal_sk' => '2026-03-10',
            'file_sk' => 'sk/jabatan.docx',
        ])->assertJsonValidationErrors(['file_sk']);

        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/riwayat-kgb", [
            'tmt_kgb' => '2026-04-01',
            'gaji_pokok' => 4500000,
            'no_sk' => 'SK-KGB-INVALID',
            'tanggal_sk' => '2026-04-10',
            'file_sk' => '/sk/kgb.pdf',
        ])->assertJsonValidationErrors(['file_sk']);

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\TrimStrings::class)
            ->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan", [
                ...$this->validRankHistoryPayload(),
                'file_sk' => "sk/rank.pdf\n",
            ])->assertJsonValidationErrors(['file_sk']);

        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan", [
            ...$this->validRankHistoryPayload(),
            'file_sk' => 'sk/rank-aman.pdf',
        ])->assertCreated();
    }

    public function test_unauthenticated_request_cannot_create_rank_history(): void
    {
        $employee = Employee::factory()->create();

        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan", $this->validRankHistoryPayload());

        $response->assertRedirect('/login');
    }

    public function test_pegawai_cannot_create_rank_history(): void
    {
        $user = User::factory()->pegawai()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan", $this->validRankHistoryPayload());

        $response->assertForbidden();
    }

    public function test_admin_without_employee_histories_create_permission_cannot_create_rank_history(): void
    {
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employee_histories.create')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);

        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan", $this->validRankHistoryPayload());

        $response->assertForbidden();
    }

    public function test_admin_can_create_position_history_append_only_and_update_pension_date(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['tanggal_lahir' => '1980-06-15']);
        $jenisJabatan = RefJenisJabatan::where('nama', 'Struktural')->firstOrFail();
        $unitKerja = RefUnitKerja::firstOrFail();

        $oldHistory = PositionHistory::create([
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Jabatan Lama',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'unit_kerja_id' => $unitKerja->id,
            'tmt_jabatan' => '2020-01-01',
            'no_sk' => 'SK-POS-OLD',
            'tanggal_sk' => '2020-01-10',
            'is_latest' => true,
        ]);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/riwayat-jabatan", [
            'nama_jabatan' => 'Kepala Bagian Umum',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'unit_kerja_id' => $unitKerja->id,
            'tmt_jabatan' => '2026-03-01',
            'no_sk' => 'SK-POS-001',
            'tanggal_sk' => '2026-03-10',
            'file_sk' => 'sk/position-001.pdf',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('message', 'Riwayat jabatan berhasil ditambahkan.');
        $response->assertJsonPath('history.is_latest', true);
        $this->assertFalse($oldHistory->fresh()->is_latest);
        $this->assertDatabaseHas('position_histories', [
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Kepala Bagian Umum',
            'is_latest' => true,
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'jabatan_terakhir' => 'Kepala Bagian Umum',
        ]);
        $this->assertSame('2040-06-15', $employee->fresh()->tanggal_pensiun->format('Y-m-d'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CREATE',
            'auditable_type' => 'PositionHistory',
        ]);
    }

    public function test_backdated_position_history_does_not_replace_current_latest_snapshot(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $jenisJabatan = RefJenisJabatan::where('nama', 'Struktural')->firstOrFail();
        $unitKerja = RefUnitKerja::firstOrFail();
        $employee = Employee::factory()->create([
            'tanggal_lahir' => '1980-06-15',
            'jabatan_terakhir' => 'Kepala Bagian Umum',
            'tanggal_pensiun' => '2040-06-15',
        ]);

        $latestHistory = PositionHistory::create([
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Kepala Bagian Umum',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'unit_kerja_id' => $unitKerja->id,
            'tmt_jabatan' => '2026-03-01',
            'no_sk' => 'SK-POS-LATEST',
            'tanggal_sk' => '2026-03-10',
            'is_latest' => true,
        ]);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/riwayat-jabatan", [
            'nama_jabatan' => 'Jabatan Lama',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'unit_kerja_id' => $unitKerja->id,
            'tmt_jabatan' => '2024-01-01',
            'no_sk' => 'SK-POS-BACKDATED',
            'tanggal_sk' => '2024-01-10',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('history.is_latest', false);
        $this->assertTrue($latestHistory->fresh()->is_latest);
        $employee->refresh();
        $this->assertSame('Kepala Bagian Umum', $employee->jabatan_terakhir);
        $this->assertSame('2040-06-15', $employee->tanggal_pensiun->format('Y-m-d'));
    }

    public function test_admin_can_create_kgb_history_append_only_and_update_next_kgb_date(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $oldHistory = SalaryHistory::create([
            'employee_id' => $employee->id,
            'tmt_kgb' => '2022-01-01',
            'gaji_pokok' => 3500000,
            'no_sk' => 'SK-KGB-OLD',
            'tanggal_sk' => '2022-01-10',
            'is_latest' => true,
        ]);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/riwayat-kgb", [
            'tmt_kgb' => '2026-04-01',
            'gaji_pokok' => 4500000,
            'no_sk' => 'SK-KGB-001',
            'tanggal_sk' => '2026-04-10',
            'file_sk' => 'sk/kgb-001.pdf',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('message', 'Riwayat KGB berhasil ditambahkan.');
        $response->assertJsonPath('history.is_latest', true);
        $this->assertFalse($oldHistory->fresh()->is_latest);
        $this->assertDatabaseHas('salary_histories', [
            'employee_id' => $employee->id,
            'no_sk' => 'SK-KGB-001',
            'is_latest' => true,
        ]);
        $this->assertSame('2028-04-01', $employee->fresh()->tanggal_kgb_berikutnya->format('Y-m-d'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CREATE',
            'auditable_type' => 'SalaryHistory',
        ]);
    }

    public function test_backdated_kgb_history_does_not_replace_current_latest_date(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'tanggal_kgb_berikutnya' => '2028-04-01',
        ]);

        $latestHistory = SalaryHistory::create([
            'employee_id' => $employee->id,
            'tmt_kgb' => '2026-04-01',
            'gaji_pokok' => 4500000,
            'no_sk' => 'SK-KGB-LATEST',
            'tanggal_sk' => '2026-04-10',
            'is_latest' => true,
        ]);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/riwayat-kgb", [
            'tmt_kgb' => '2024-01-01',
            'gaji_pokok' => 4000000,
            'no_sk' => 'SK-KGB-BACKDATED',
            'tanggal_sk' => '2024-01-10',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('history.is_latest', false);
        $this->assertTrue($latestHistory->fresh()->is_latest);
        $this->assertDatabaseHas('salary_histories', [
            'employee_id' => $employee->id,
            'no_sk' => 'SK-KGB-BACKDATED',
            'is_latest' => false,
        ]);
        $this->assertSame('2028-04-01', $employee->fresh()->tanggal_kgb_berikutnya->format('Y-m-d'));
    }

    public function test_old_english_employee_history_urls_no_longer_resolve(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);

        $this->getJson("/api/v1/employees/{$employee->id}/rank-histories")->assertNotFound();
        $this->getJson("/api/v1/employees/{$employee->id}/position-histories")->assertNotFound();
        $this->getJson("/api/v1/employees/{$employee->id}/kgb-histories")->assertNotFound();
    }

    private function postJsonWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    /**
     * Payload riwayat pangkat minimal yang valid untuk uji otorisasi route.
     *
     * @return array<string, mixed>
     */
    private function validRankHistoryPayload(): array
    {
        return [
            'golongan_id' => RefGolongan::where('kode', 'III/b')->firstOrFail()->id,
            'tmt_pangkat' => '2026-02-01',
            'no_sk' => 'SK-RANK-001',
            'tanggal_sk' => '2026-02-10',
            'file_sk' => 'sk/rank-001.pdf',
        ];
    }
}
