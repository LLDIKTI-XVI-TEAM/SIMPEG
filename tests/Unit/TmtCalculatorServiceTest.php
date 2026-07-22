<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\SalaryHistory;
use App\Services\Employees\TmtCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionClass;
use Tests\TestCase;

class TmtCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    private TmtCalculatorService $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        $this->calculator = new TmtCalculatorService;
    }

    public function test_public_api_only_exposes_sync_for_employee(): void
    {
        $methods = collect((new ReflectionClass(TmtCalculatorService::class))->getMethods())
            ->filter(fn ($method): bool => $method->isPublic() && $method->getDeclaringClass()->getName() === TmtCalculatorService::class)
            ->map(fn ($method): string => $method->getName())
            ->values()
            ->all();

        $this->assertSame(['syncForEmployee'], $methods);
    }

    public function test_rank_and_kgb_snapshots_use_latest_dated_sources(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_pensiun' => null,
            'tanggal_kenaikan_pangkat_berikutnya' => null,
            'tanggal_kgb_berikutnya' => null,
        ]);

        $this->rankHistory($employee, '2024-01-15');
        $this->rankHistory($employee, '2026-04-01');
        $this->salaryHistory($employee, '2023-02-10');
        $this->salaryHistory($employee, '2025-08-31');

        $this->calculator->syncForEmployee($employee);

        $employee->refresh();
        $this->assertDateValue('2030-04-01', $employee->tanggal_kenaikan_pangkat_berikutnya);
        $this->assertDateValue('2027-08-31', $employee->tanggal_kgb_berikutnya);
    }

    public function test_backdated_and_null_sources_do_not_replace_latest_dated_sources(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_pensiun' => null,
            'tanggal_kenaikan_pangkat_berikutnya' => '2040-01-01',
            'tanggal_kgb_berikutnya' => '2040-01-01',
        ]);

        $this->rankHistory($employee, '2026-05-20');
        $this->rankHistory($employee, '2020-01-01');
        $this->rankHistory($employee, null);
        $this->salaryHistory($employee, '2026-07-10');
        $this->salaryHistory($employee, '2021-03-01');
        $this->salaryHistory($employee, null);

        $this->calculator->syncForEmployee($employee);

        $employee->refresh();
        $this->assertDateValue('2030-05-20', $employee->tanggal_kenaikan_pangkat_berikutnya);
        $this->assertDateValue('2028-07-10', $employee->tanggal_kgb_berikutnya);
    }

    public function test_equal_tmt_prefers_newer_created_at(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_lahir' => '1980-01-01',
            'tanggal_pensiun' => null,
        ]);
        $this->positionHistory($employee, 58, 58, '2026-01-01', '2026-06-01 08:00:00');
        $this->positionHistory($employee, 62, 58, '2026-01-01', '2026-06-02 08:00:00');

        $this->calculator->syncForEmployee($employee);

        $this->assertDateValue('2042-01-01', $employee->fresh()->tanggal_pensiun);
    }

    public function test_equal_tmt_and_created_at_use_id_desc_as_deterministic_fallback(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_lahir' => '1980-01-01',
            'tanggal_pensiun' => null,
        ]);
        $createdAt = '2026-06-01 08:00:00';
        $lowerId = $this->positionHistory($employee, 58, 58, '2026-01-01', $createdAt, '00000000-0000-4000-8000-000000000001');
        $higherId = $this->positionHistory($employee, 65, 58, '2026-01-01', $createdAt, 'ffffffff-ffff-4fff-bfff-ffffffffffff');

        $this->assertSame('00000000-0000-4000-8000-000000000001', $lowerId->id);
        $this->assertSame('ffffffff-ffff-4fff-bfff-ffffffffffff', $higherId->id);

        $this->calculator->syncForEmployee($employee);

        $this->assertDateValue('2045-01-01', $employee->fresh()->tanggal_pensiun);
    }

    public function test_existing_pension_date_is_preserved(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_lahir' => '1980-06-15',
            'tanggal_pensiun' => '2038-12-31',
        ]);
        $this->positionHistory($employee, 65, 60);

        $this->calculator->syncForEmployee($employee);

        $this->assertDateValue('2038-12-31', $employee->fresh()->tanggal_pensiun);
    }

    public function test_pension_uses_position_default_bup_before_position_type_bup(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_lahir' => '1980-06-15',
            'tanggal_pensiun' => null,
        ]);
        $this->positionHistory($employee, 62, 58);

        $this->calculator->syncForEmployee($employee);

        $this->assertDateValue('2042-06-15', $employee->fresh()->tanggal_pensiun);
    }

    public function test_pension_falls_back_to_position_type_bup(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_lahir' => '1980-06-15',
            'tanggal_pensiun' => null,
        ]);
        $this->positionHistory($employee, null, 60);

        $this->calculator->syncForEmployee($employee);

        $this->assertDateValue('2040-06-15', $employee->fresh()->tanggal_pensiun);
    }

    public function test_pension_type_bup_comes_from_latest_position_history_reference(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_lahir' => '1980-06-15',
            'tanggal_pensiun' => null,
        ]);
        $masterType = RefJenisJabatan::create([
            'nama' => 'Jenis Master',
            'maks_usia_pensiun' => 58,
        ]);
        $historyType = RefJenisJabatan::create([
            'nama' => 'Jenis History',
            'maks_usia_pensiun' => 60,
        ]);
        $position = RefJabatan::create([
            'nama' => 'Jabatan Berubah Jenis',
            'jenis_jabatan_id' => $masterType->id,
            'default_bup' => null,
        ]);
        PositionHistory::create([
            'employee_id' => $employee->id,
            'jabatan_id' => $position->id,
            'jenis_jabatan_id' => $historyType->id,
            'tmt_jabatan' => '2026-01-01',
        ]);

        $this->calculator->syncForEmployee($employee);

        $this->assertDateValue('2040-06-15', $employee->fresh()->tanggal_pensiun);
    }

    public function test_missing_position_reference_leaves_pension_null(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_lahir' => '1980-06-15',
            'tanggal_pensiun' => null,
        ]);
        PositionHistory::create([
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Jabatan Tanpa Referensi',
            'tmt_jabatan' => '2026-01-01',
        ]);

        $this->calculator->syncForEmployee($employee);

        $this->assertNull($employee->fresh()->tanggal_pensiun);
    }

    public function test_absent_dated_rank_and_kgb_sources_clear_derived_snapshots(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_pensiun' => null,
            'tanggal_kenaikan_pangkat_berikutnya' => '2030-01-01',
            'tanggal_kgb_berikutnya' => '2028-01-01',
        ]);
        $this->rankHistory($employee, null);
        $this->salaryHistory($employee, null);

        $this->calculator->syncForEmployee($employee);

        $employee->refresh();
        $this->assertNull($employee->tanggal_kenaikan_pangkat_berikutnya);
        $this->assertNull($employee->tanggal_kgb_berikutnya);
    }

    public function test_leap_day_calculations_do_not_overflow_into_march(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_lahir' => '1980-02-29',
            'tanggal_pensiun' => null,
        ]);
        $this->rankHistory($employee, '2024-02-29');
        $this->salaryHistory($employee, '2024-02-29');
        $this->positionHistory($employee, 61, 58);

        $this->calculator->syncForEmployee($employee);

        $employee->refresh();
        $this->assertDateValue('2028-02-29', $employee->tanggal_kenaikan_pangkat_berikutnya);
        $this->assertDateValue('2026-02-28', $employee->tanggal_kgb_berikutnya);
        $this->assertDateValue('2041-02-28', $employee->tanggal_pensiun);
    }

    private function rankHistory(
        Employee $employee,
        ?string $tmt,
        ?string $createdAt = null,
        ?string $id = null,
    ): RankHistory {
        $history = new RankHistory([
            'employee_id' => $employee->id,
            'tmt_pangkat' => $tmt,
            'is_latest' => false,
        ]);

        if ($id !== null) {
            $history->forceFill(['id' => $id]);
        }

        $history->save();

        if ($createdAt !== null) {
            $history->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        }

        return $history;
    }

    private function salaryHistory(Employee $employee, ?string $tmt): SalaryHistory
    {
        return SalaryHistory::create([
            'employee_id' => $employee->id,
            'tmt_kgb' => $tmt,
            'is_latest' => false,
        ]);
    }

    private function positionHistory(
        Employee $employee,
        ?int $defaultBup,
        int $typeBup,
        string $tmt = '2026-01-01',
        ?string $createdAt = null,
        ?string $id = null,
    ): PositionHistory {
        $type = RefJenisJabatan::create([
            'nama' => 'Jenis Jabatan '.fake()->unique()->word(),
            'maks_usia_pensiun' => $typeBup,
        ]);
        $position = RefJabatan::create([
            'nama' => 'Jabatan '.fake()->unique()->word(),
            'jenis_jabatan_id' => $type->id,
            'default_bup' => $defaultBup,
        ]);

        $history = new PositionHistory([
            'employee_id' => $employee->id,
            'jabatan_id' => $position->id,
            'nama_jabatan' => $position->nama,
            'jenis_jabatan_id' => $type->id,
            'tmt_jabatan' => $tmt,
            'is_latest' => false,
        ]);

        if ($id !== null) {
            $history->forceFill(['id' => $id]);
        }

        $history->save();

        if ($createdAt !== null) {
            $history->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        }

        return $history;
    }

    private function assertDateValue(string $expected, ?Carbon $actual): void
    {
        $this->assertNotNull($actual);
        $this->assertSame($expected, $actual->format('Y-m-d'));
    }
}
