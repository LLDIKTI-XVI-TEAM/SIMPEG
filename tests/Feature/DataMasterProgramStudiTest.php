<?php

namespace Tests\Feature;

use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\RefJenjangPendidikan;
use App\Models\RefProgramStudi;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DataMasterProgramStudiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_can_manage_program_studi(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)->postWithCsrf(route('data-master.program-studi.store'), [
            'nama' => 'Teknik Informatika',
        ])->assertRedirect()->assertSessionHas('success');

        $programStudi = RefProgramStudi::firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['event' => 'CREATE', 'auditable_type' => 'RefProgramStudi']);

        $this->actingAs($user)->postWithCsrf(route('data-master.program-studi.update', $programStudi), [
            'nama' => 'Informatika',
        ])->assertRedirect();
        $this->assertDatabaseHas('ref_program_studi', ['id' => $programStudi->id, 'nama' => 'Informatika']);

        $this->actingAs($user)->postWithCsrf(route('data-master.program-studi.toggle', $programStudi), [])
            ->assertRedirect();
        $this->assertFalse($programStudi->refresh()->is_active);
    }

    public function test_program_studi_used_by_employee_cannot_be_deleted(): void
    {
        $programStudi = RefProgramStudi::create(['nama' => 'Hukum']);
        Employee::factory()->create(['program_studi_id' => $programStudi->id]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.program-studi.destroy', $programStudi), [])
            ->assertSessionHasErrors('referensi');

        $this->assertDatabaseHas('ref_program_studi', ['id' => $programStudi->id]);
    }

    public function test_renaming_program_studi_syncs_education_and_employee_snapshots(): void
    {
        $user = User::factory()->superAdmin()->create();
        $programStudi = RefProgramStudi::create(['nama' => 'Nama Lama']);
        $employee = Employee::factory()->create([
            'program_studi_id' => $programStudi->id,
            'prodi_pendidikan_terakhir' => 'Nama Lama',
        ]);
        $history = EducationHistory::create([
            'employee_id' => $employee->id,
            'jenjang_id' => RefJenjangPendidikan::create([
                'nama' => 'D4 / S1',
                'urutan' => 6,
            ])->id,
            'program_studi_id' => $programStudi->id,
            'nama_institusi' => 'Universitas Contoh',
            'jurusan' => 'Nama Lama',
            'tahun_lulus' => 2010,
            'no_ijazah' => 'IJZ-RENAME-001',
        ]);

        $this->actingAs($user)->postWithCsrf(route('data-master.program-studi.update', $programStudi), [
            'nama' => 'Nama Baru',
        ])->assertRedirect();

        $this->assertDatabaseHas('ref_program_studi', ['id' => $programStudi->id, 'nama' => 'Nama Baru']);
        $this->assertDatabaseHas('education_histories', ['id' => $history->id, 'jurusan' => 'Nama Baru']);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'prodi_pendidikan_terakhir' => 'Nama Baru',
        ]);
    }

    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
