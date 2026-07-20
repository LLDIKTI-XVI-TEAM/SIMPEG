<?php

namespace Tests\Feature;

use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\RefJenjangPendidikan;
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
