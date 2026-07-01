<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefUnitKerja;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_guest_cannot_update_employee(): void
    {
        $employee = Employee::factory()->create();

        $response = $this->putJsonWithCsrf($this->endpoint($employee), $this->validPayload($employee));

        $response->assertRedirect('/login');
    }

    public function test_admin_kepegawaian_can_update_employee(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Nama Lama',
            'email' => 'lama@example.com',
            'nip' => '198001012006041001',
        ]);

        $payload = $this->validPayload($employee, [
            'nama_lengkap' => 'Nama Baru',
            'email' => 'baru@example.com',
            'jabatan_terakhir' => 'Analis SDM Aparatur',
        ]);

        $this->actingAs($user);
        $this->withoutExceptionHandling();
        $response = $this->putJsonWithCsrf($this->endpoint($employee), $payload);

        $response->assertOk();
        $response->assertJsonPath('message', 'Data pegawai berhasil diperbarui.');
        $response->assertJsonPath('employee.nama_lengkap', 'Nama Baru');
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'nama_lengkap' => 'Nama Baru',
            'email' => 'baru@example.com',
            'jabatan_terakhir' => 'Analis SDM Aparatur',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_super_admin_can_update_employee(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf($this->endpoint($employee), $this->validPayload($employee, [
            'nama_lengkap' => 'Diperbarui Super Admin',
        ]));

        $response->assertOk();
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'nama_lengkap' => 'Diperbarui Super Admin',
        ]);
    }

    public function test_admin_can_replace_employee_photo_upload(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('photos/foto-lama.jpg', 'old-photo');
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'foto' => 'photos/foto-lama.jpg',
        ]);

        $this->actingAs($user);
        $response = $this->putWithCsrf($this->endpoint($employee), $this->validPayload($employee, [
            'foto' => UploadedFile::fake()->image('foto-baru.png', 640, 640)->size(512),
        ]));

        $response->assertOk();
        $photoPath = $response->json('employee.foto');
        $this->assertIsString($photoPath);
        $this->assertStringStartsWith('employees/photos/', $photoPath);
        $this->assertStringEndsWith('.png', $photoPath);
        Storage::disk('public')->assertExists($photoPath);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'foto' => $photoPath,
        ]);
    }

    public function test_pegawai_cannot_update_employee(): void
    {
        $user = User::factory()->pegawai()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf($this->endpoint($employee), $this->validPayload($employee));

        $response->assertForbidden();
    }

    public function test_same_nip_and_email_are_allowed_for_current_employee(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'email' => 'tetap@example.com',
            'nip' => '198001012006041001',
        ]);

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf($this->endpoint($employee), $this->validPayload($employee, [
            'nama_lengkap' => 'Nama Tetap Valid',
            'email' => 'tetap@example.com',
            'nip' => '198001012006041001',
        ]));

        $response->assertOk();
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'nama_lengkap' => 'Nama Tetap Valid',
            'email' => 'tetap@example.com',
            'nip' => '198001012006041001',
        ]);
    }

    public function test_duplicate_email_and_nip_are_rejected_for_other_employee(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        Employee::factory()->create([
            'email' => 'duplikat@example.com',
            'nip' => '198001012006041001',
        ]);

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf($this->endpoint($employee), $this->validPayload($employee, [
            'email' => 'duplikat@example.com',
            'nip' => '198001012006041001',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email', 'nip']);
    }

    public function test_future_birth_date_is_rejected(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf($this->endpoint($employee), $this->validPayload($employee, [
            'tanggal_lahir' => now()->addDay()->format('Y-m-d'),
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('tanggal_lahir');
    }

    private function endpoint(Employee $employee): string
    {
        return "/api/v1/pegawai/{$employee->id}";
    }

    public function test_old_employees_update_endpoint_is_not_available(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf("/api/v1/employees/{$employee->id}", $this->validPayload($employee));

        $response->assertNotFound();
    }

    private function putJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->putJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function putWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->put($uri, $data, ['X-CSRF-TOKEN' => 'test-token', 'Accept' => 'application/json']);
    }

    private function validPayload(Employee $employee, array $overrides = []): array
    {
        return array_merge([
            'nama_lengkap' => $employee->nama_lengkap,
            'email' => $employee->email,
            'golongan_terakhir' => $employee->golongan_terakhir,
            'jabatan_terakhir' => $employee->jabatan_terakhir,
            'kelas_jabatan' => $employee->kelas_jabatan,
            'nip' => $employee->nip,
            'no_hp' => $employee->no_hp,
            'pangkat_terakhir' => $employee->pangkat_terakhir,
            'pendidikan_terakhir' => $employee->pendidikan_terakhir,
            'tanggal_pensiun' => $employee->tanggal_pensiun ? Carbon::parse($employee->tanggal_pensiun)->format('Y-m-d') : null,
            'prodi_pendidikan_terakhir' => $employee->prodi_pendidikan_terakhir,
            'jenis_pegawai_id' => $employee->jenis_pegawai_id ?: RefJenisPegawai::where('nama', 'PNS')->firstOrFail()->id,
            'tanggal_lahir' => Carbon::parse($employee->tanggal_lahir)->format('Y-m-d'),
        ], $overrides);
    }

    public function test_admin_kepegawaian_can_update_employee_with_histories_via_web_form(): void
    {
        Storage::fake('public');
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $golongan = RefGolongan::first() ?: RefGolongan::create(['kode' => 'III/a', 'nama' => 'Penata Muda']);
        $jenisJabatan = RefJenisJabatan::first() ?: RefJenisJabatan::create(['nama' => 'Fungsional']);
        $eselon = RefEselon::first() ?: RefEselon::create(['nama' => 'Eselon I']);
        $unitKerja = RefUnitKerja::first() ?: RefUnitKerja::create(['nama' => 'LLDIKTI']);

        $payload = $this->validPayload($employee, [
            // Pangkat
            'pangkat_golongan_id' => $golongan->id,
            'pangkat_no_sk' => 'SK-PANGKAT-WEB-001',
            'pangkat_tanggal_sk' => '2026-01-01',
            'pangkat_tmt_pangkat' => '2026-01-02',
            'file_sk_pangkat' => UploadedFile::fake()->create('sk-pangkat.pdf', 500, 'application/pdf'),

            // Jabatan
            'jabatan_nama_jabatan' => 'Kepala Sub Bagian Web',
            'jabatan_jenis_jabatan_id' => $jenisJabatan->id,
            'jabatan_eselon_id' => $eselon->id,
            'jabatan_unit_kerja_id' => $unitKerja->id,
            'jabatan_no_sk' => 'SK-JABATAN-WEB-001',
            'jabatan_tanggal_sk' => '2026-02-01',
            'jabatan_tmt_jabatan' => '2026-02-02',
            'file_sk_jabatan' => UploadedFile::fake()->create('sk-jabatan.pdf', 500, 'application/pdf'),

            // KGB
            'kgb_gaji_pokok' => '4500000',
            'kgb_no_sk' => 'SK-KGB-WEB-001',
            'kgb_tanggal_sk' => '2026-03-01',
            'kgb_tmt_kgb' => '2026-03-02',
            'file_sk_kgb' => UploadedFile::fake()->create('sk-kgb.pdf', 500, 'application/pdf'),

            // Pengangkatan
            'pengangkatan_jenis_pengangkatan' => 'PNS',
            'pengangkatan_tmt_pengangkatan' => '2026-04-01',
            'pengangkatan_no_sk' => 'SK-PENGANGKATAN-WEB-001',
            'pengangkatan_tanggal_sk' => '2026-04-02',
            'file_sk_pengangkatan' => UploadedFile::fake()->create('sk-pengangkatan.pdf', 500, 'application/pdf'),
        ]);

        $this->actingAs($user);
        $response = $this->withSession(['_token' => 'test-token'])
            ->post("/pegawai/{$employee->id}", $payload, ['X-CSRF-TOKEN' => 'test-token']);

        $response->assertRedirect(route('data-pegawai'));

        // Assert RankHistory was created
        $this->assertDatabaseHas('rank_histories', [
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'no_sk' => 'SK-PANGKAT-WEB-001',
            'tmt_pangkat' => '2026-01-02 00:00:00',
            'is_latest' => 1,
        ]);

        // Assert PositionHistory was created
        $this->assertDatabaseHas('position_histories', [
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Kepala Sub Bagian Web',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'eselon_id' => $eselon->id,
            'unit_kerja_id' => $unitKerja->id,
            'no_sk' => 'SK-JABATAN-WEB-001',
            'tmt_jabatan' => '2026-02-02 00:00:00',
            'is_latest' => 1,
        ]);

        // Assert SalaryHistory was created
        $this->assertDatabaseHas('salary_histories', [
            'employee_id' => $employee->id,
            'gaji_pokok' => '4500000',
            'no_sk' => 'SK-KGB-WEB-001',
            'tmt_kgb' => '2026-03-02 00:00:00',
            'is_latest' => 1,
        ]);

        // Assert Appointment was created
        $this->assertDatabaseHas('appointments', [
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2026-04-01 00:00:00',
            'no_sk' => 'SK-PENGANGKATAN-WEB-001',
        ]);
    }
}
