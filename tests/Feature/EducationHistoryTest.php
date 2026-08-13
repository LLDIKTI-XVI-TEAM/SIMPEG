<?php

namespace Tests\Feature;

use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\RefJenjangPendidikan;
use App\Models\RefProgramStudi;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EducationHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_admin_can_list_employee_education_histories(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $history = EducationHistory::create($this->educationPayload($employee));

        $response = $this->actingAs($user)->getJson($this->endpoint($employee));

        $response->assertOk();
        $response->assertJsonPath('employee_id', $employee->id);
        $response->assertJsonPath('histories.0.id', $history->id);
    }

    public function test_admin_can_create_employee_education_history(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $response = $this->actingAs($user)
            ->postJsonWithCsrf($this->endpoint($employee), $this->validPayload(['nama_institusi' => 'Universitas Baru']));

        $response->assertCreated();
        $response->assertJsonPath('history.employee_id', $employee->id);
        $this->assertDatabaseHas('education_histories', [
            'employee_id' => $employee->id,
            'nama_institusi' => 'Universitas Baru',
        ]);
    }

    public function test_education_history_uses_program_studi_reference(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $programStudi = RefProgramStudi::create(['nama' => 'Administrasi Publik']);

        $response = $this->actingAs($user)->postJsonWithCsrf($this->endpoint($employee), $this->validPayload([
            'program_studi_id' => $programStudi->id,
            'jurusan' => 'Data lama yang harus ditimpa',
        ]));

        $response->assertCreated()
            ->assertJsonPath('history.program_studi_id', $programStudi->id)
            ->assertJsonPath('history.program_studi', 'Administrasi Publik');
        $this->assertDatabaseHas('education_histories', [
            'employee_id' => $employee->id,
            'program_studi_id' => $programStudi->id,
            'jurusan' => 'Administrasi Publik',
        ]);
        $this->assertSame($programStudi->id, $employee->refresh()->program_studi_id);
    }

    public function test_admin_cannot_create_education_history_with_inactive_program_studi(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $programStudi = RefProgramStudi::create([
            'nama' => 'Program Studi Nonaktif',
            'is_active' => false,
        ]);

        $response = $this->actingAs($user)->postJsonWithCsrf($this->endpoint($employee), $this->validPayload([
            'program_studi_id' => $programStudi->id,
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors('program_studi_id');
        $this->assertDatabaseMissing('education_histories', [
            'employee_id' => $employee->id,
            'program_studi_id' => $programStudi->id,
        ]);
    }

    public function test_admin_can_preserve_inactive_program_studi_on_education_history_update(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $programStudi = RefProgramStudi::create([
            'nama' => 'Program Studi Lama Nonaktif',
            'is_active' => false,
        ]);
        $history = EducationHistory::create(array_merge($this->educationPayload($employee), [
            'program_studi_id' => $programStudi->id,
        ]));

        $response = $this->actingAs($user)->putJsonWithCsrf(
            $this->endpoint($employee)."/{$history->id}",
            $this->validPayload([
                'program_studi_id' => $programStudi->id,
                'nama_institusi' => 'Universitas Diperbarui',
            ]),
        );

        $response->assertOk()->assertJsonPath('history.program_studi_id', $programStudi->id);
        $this->assertDatabaseHas('education_histories', [
            'id' => $history->id,
            'program_studi_id' => $programStudi->id,
            'nama_institusi' => 'Universitas Diperbarui',
        ]);
    }

    public function test_admin_cannot_replace_education_history_program_studi_with_inactive_reference(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $currentProgramStudi = RefProgramStudi::create(['nama' => 'Program Studi Aktif']);
        $inactiveProgramStudi = RefProgramStudi::create([
            'nama' => 'Program Studi Pengganti Nonaktif',
            'is_active' => false,
        ]);
        $history = EducationHistory::create(array_merge($this->educationPayload($employee), [
            'program_studi_id' => $currentProgramStudi->id,
        ]));

        $response = $this->actingAs($user)->putJsonWithCsrf(
            $this->endpoint($employee)."/{$history->id}",
            $this->validPayload(['program_studi_id' => $inactiveProgramStudi->id]),
        );

        $response->assertUnprocessable()->assertJsonValidationErrors('program_studi_id');
        $this->assertSame($currentProgramStudi->id, $history->fresh()->program_studi_id);
    }

    public function test_admin_can_update_employee_education_history(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $history = EducationHistory::create($this->educationPayload($employee));

        $response = $this->actingAs($user)->putJsonWithCsrf(
            $this->endpoint($employee)."/{$history->id}",
            $this->validPayload(['nama_institusi' => 'Universitas Diperbarui']),
        );

        $response->assertOk();
        $response->assertJsonPath('history.nama_institusi', 'Universitas Diperbarui');
        $this->assertDatabaseHas('education_histories', [
            'id' => $history->id,
            'nama_institusi' => 'Universitas Diperbarui',
        ]);
    }

    public function test_admin_can_delete_employee_education_history(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $history = EducationHistory::create($this->educationPayload($employee));

        $response = $this->actingAs($user)
            ->deleteJsonWithCsrf($this->endpoint($employee)."/{$history->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('education_histories', ['id' => $history->id]);
    }

    private function endpoint(Employee $employee): string
    {
        return "/api/v1/pegawai/{$employee->id}/riwayat-pendidikan";
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'jenjang_id' => RefJenjangPendidikan::where('nama', 'D4 / S1')->firstOrFail()->id,
            'nama_institusi' => 'Universitas Contoh',
            'jurusan' => 'Administrasi Publik',
            'tahun_lulus' => 2010,
            'no_ijazah' => 'IJZ-001',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function educationPayload(Employee $employee): array
    {
        return array_merge($this->validPayload(), ['employee_id' => $employee->id]);
    }

    /** @param array<string, mixed> $data */
    private function postJsonWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    /** @param array<string, mixed> $data */
    private function putJsonWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->putJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function deleteJsonWithCsrf(string $uri): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->deleteJson($uri, [], ['X-CSRF-TOKEN' => 'test-token']);
    }
}
