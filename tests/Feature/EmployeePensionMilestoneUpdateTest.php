<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefUnitKerja;
use App\Models\User;
use App\Services\Employees\TmtCalculatorService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EmployeePensionMilestoneUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_official_pension_update_overrides_calculated_milestone_and_survives_backfill(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => '1967-03-20',
            'tanggal_pensiun' => null,
        ]);
        $this->createPositionWithBup($employee, 58);
        $employee->appointment()->create([
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-PENGANGKATAN-001',
            'tanggal_sk' => '2019-12-15',
        ]);

        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $calculatedMilestone = $this->activePensionMilestone($employee);
        $this->assertSame('2025-03-20', $calculatedMilestone->milestone_date->toDateString());
        $this->assertFalse($calculatedMilestone->metadata['is_manual']);
        $this->assertSame('calculated_from_bup', $calculatedMilestone->metadata['source']);

        $response = $this->updateEmployee($user, $employee, [
            'tanggal_pensiun' => '2033-12-31',
            'pengangkatan_jenis_pengangkatan' => 'PNS',
            'pengangkatan_tmt_pengangkatan' => '2019-06-01',
            'pengangkatan_no_sk' => 'SK-PENGANGKATAN-001',
            'pengangkatan_tanggal_sk' => '2019-05-15',
        ]);
        $response->assertSessionHasNoErrors()->assertRedirect();

        $employee->refresh();
        $calculatedMilestone->refresh();

        $this->assertSame('2033-12-31', $employee->tanggal_pensiun?->toDateString());
        $this->assertSame('2033-12-31', $calculatedMilestone->milestone_date->toDateString());
        $this->assertTrue($calculatedMilestone->metadata['is_manual']);
        $this->assertSame('employees.tanggal_pensiun', $calculatedMilestone->metadata['source']);

        $this->artisan('milestone:backfill', [
            '--only-active' => true,
            '--no-interaction' => true,
        ])
            ->expectsQuestion('Do you want to proceed with the backfill?', true)
            ->assertSuccessful();

        $employee->refresh();
        $calculatedMilestone->refresh();

        $this->assertSame('2033-12-31', $employee->tanggal_pensiun?->toDateString());
        $this->assertSame('2033-12-31', $calculatedMilestone->milestone_date->toDateString());
        $this->assertSame(1, EmployeeMilestone::query()
            ->where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
            ->count());
    }

    public function test_clearing_official_pension_date_returns_to_calculated_provenance(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => '1967-03-20',
            'tanggal_pensiun' => '2033-12-31',
        ]);
        $this->createPositionWithBup($employee, 58);

        app(TmtCalculatorService::class)->syncForEmployee($employee);
        $manualMilestone = $this->activePensionMilestone($employee);

        $response = $this->updateEmployee($user, $employee, [
            'tanggal_pensiun' => null,
        ]);
        $response->assertSessionHasNoErrors()->assertRedirect();

        $employee->refresh();
        $manualMilestone->refresh();

        $this->assertSame('2025-03-20', $employee->tanggal_pensiun?->toDateString());
        $this->assertSame('2025-03-20', $manualMilestone->milestone_date->toDateString());
        $this->assertFalse($manualMilestone->metadata['is_manual']);
        $this->assertSame('calculated_from_bup', $manualMilestone->metadata['source']);
        $this->assertSame(1, EmployeeMilestone::query()
            ->where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
            ->where('is_active', true)
            ->count());
    }

    public function test_updating_birth_date_recalculates_calculated_pension_in_place(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => '1967-03-20',
            'tanggal_pensiun' => null,
        ]);
        $this->createPositionWithBup($employee, 58);

        app(TmtCalculatorService::class)->syncForEmployee($employee);
        $milestone = $this->activePensionMilestone($employee);
        $milestoneId = $milestone->id;

        $response = $this->updateEmployee($user, $employee, [
            'tanggal_lahir' => '1968-03-20',
        ]);
        $response->assertSessionHasNoErrors()->assertRedirect();

        $employee->refresh();
        $milestone->refresh();

        $this->assertSame($milestoneId, $milestone->id);
        $this->assertSame('2026-03-20', $employee->tanggal_pensiun?->toDateString());
        $this->assertSame('2026-03-20', $milestone->milestone_date->toDateString());
        $this->assertFalse($milestone->metadata['is_manual']);
    }

    public function test_updating_non_pension_fields_does_not_recalculate_pension_milestone(): void
    {
        $this->travelTo('2026-08-11 08:00:00');

        $user = User::factory()->create(['role' => 'super_admin']);
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'nama_lengkap' => 'Nama Lama',
            'email_pribadi' => 'lama@example.com',
            'tanggal_lahir' => '1967-03-20',
            'tanggal_pensiun' => '2033-12-31',
        ]);

        app(TmtCalculatorService::class)->syncForEmployee($employee);
        $milestone = $this->activePensionMilestone($employee);
        $calculatedAt = $milestone->calculated_at->toDateString();

        $this->travelTo('2026-08-12 08:00:00');

        $response = $this->updateEmployee($user, $employee, [
            'nama_lengkap' => 'Nama Baru',
            'email_pribadi' => 'baru@example.com',
        ]);
        $response->assertSessionHasNoErrors()->assertRedirect();

        $milestone->refresh();

        $this->assertSame($calculatedAt, $milestone->calculated_at->toDateString());
        $this->assertSame('2033-12-31', $milestone->milestone_date->toDateString());

        $this->travelBack();
    }

    private function createPositionWithBup(Employee $employee, int $bup): void
    {
        $jenisJabatan = RefJenisJabatan::create([
            'nama' => 'Jenis Jabatan Uji Pensiun',
            'maks_usia_pensiun' => 60,
            'is_active' => true,
        ]);
        $jabatan = RefJabatan::create([
            'nama' => 'Jabatan Uji Pensiun',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'default_bup' => $bup,
            'is_active' => true,
        ]);

        $employee->positionHistories()->create([
            'jabatan_id' => $jabatan->id,
            'jenis_jabatan_id' => $jenisJabatan->id,
            'nama_jabatan' => $jabatan->nama,
            'unit_kerja_id' => RefUnitKerja::query()->firstOrFail()->id,
            'tmt_jabatan' => '2020-01-01',
            'no_sk' => 'SK-PENSIUN-001',
            'tanggal_sk' => '2019-12-15',
            'is_latest' => true,
        ]);
    }

    private function updateEmployee(User $user, Employee $employee, array $overrides): TestResponse
    {
        $payload = array_merge([
            'nama_lengkap' => $employee->nama_lengkap,
            'nip' => $employee->nip,
            'email_pribadi' => $employee->email_pribadi,
            'tanggal_lahir' => $employee->tanggal_lahir?->toDateString(),
            'tanggal_pensiun' => $employee->tanggal_pensiun?->toDateString(),
        ], $overrides);
        $token = 'employee-pension-milestone-token';

        return $this->actingAs($user)
            ->withSession(['_token' => $token])
            ->post(route('pegawai.update', $employee->id), [...$payload, '_token' => $token]);
    }

    private function activePensionMilestone(Employee $employee): EmployeeMilestone
    {
        return EmployeeMilestone::query()
            ->where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
            ->where('is_active', true)
            ->sole();
    }
}
