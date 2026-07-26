<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\RankHistory;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use App\Models\User;
use App\Services\Employees\TmtCalculatorService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
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
            'email_pribadi' => 'lama@example.com',
            'nip' => '198001012006041001',
        ]);

        $payload = $this->validPayload($employee, [
            'nama_lengkap' => 'Nama Baru',
            'email_pribadi' => 'baru@example.com',
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
            'email_pribadi' => 'baru@example.com',
            'jabatan_terakhir' => 'Analis SDM Aparatur',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_admin_kepegawaian_can_update_kepala_lembaga_marker(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['is_kepala_lembaga' => false]);

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf($this->endpoint($employee), $this->validPayload($employee, [
            'is_kepala_lembaga' => true,
        ]));

        $response->assertOk();
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'is_kepala_lembaga' => true,
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
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        $disk->assertExists($photoPath);
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
            'email_pribadi' => 'tetap@example.com',
            'nip' => '198001012006041001',
        ]);

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf($this->endpoint($employee), $this->validPayload($employee, [
            'nama_lengkap' => 'Nama Tetap Valid',
            'email_pribadi' => 'tetap@example.com',
            'nip' => '198001012006041001',
        ]));

        $response->assertOk();
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'nama_lengkap' => 'Nama Tetap Valid',
            'email_pribadi' => 'tetap@example.com',
            'nip' => '198001012006041001',
        ]);
    }

    public function test_duplicate_email_and_nip_are_rejected_for_other_employee(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        Employee::factory()->create([
            'email_pribadi' => 'duplikat@example.com',
            'nip' => '198001012006041001',
        ]);

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf($this->endpoint($employee), $this->validPayload($employee, [
            'email_pribadi' => 'duplikat@example.com',
            'nip' => '198001012006041001',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email_pribadi', 'nip']);
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

    public function test_web_edit_omits_and_ignores_manual_retirement_date(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['tanggal_pensiun' => '2042-05-15']);

        $this->actingAs($user)
            ->get(route('pegawai.edit', $employee->id))
            ->assertOk()
            ->assertDontSee('name="tanggal_pensiun"', false);

        $response = $this->actingAs($user)->post(
            route('pegawai.update', $employee->id),
            $this->validPayload($employee, ['tanggal_pensiun' => '2030-01-01'])
        );

        $response->assertRedirect(route('data-pegawai'));
        $this->assertSame('2042-05-15', $employee->fresh()->tanggal_pensiun?->toDateString());
    }

    public function test_pppk_contract_dates_are_shown_saved_and_reset_active_contract_alerts(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $pppk = RefJenisPegawai::where('nama', 'PPPK')->firstOrFail();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $pppk->id,
            'tanggal_akhir_kontrak' => '2028-01-01',
        ]);
        $appointment = Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PPPK',
            'tmt_pengangkatan' => '2024-01-01',
            'no_sk' => 'SK-PPPK-LAMA',
            'tanggal_sk' => '2024-01-01',
        ]);
        $alert = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KONTRAK_PPPK',
            'target_date' => '2028-01-01',
            'interval_days' => 180,
            'is_processed' => false,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('pegawai.edit', $employee->id))
            ->assertOk()
            ->assertSee('name="pppk_tmt_pengangkatan"', false)
            ->assertSee('name="tanggal_akhir_kontrak"', false);

        $response = $this->actingAs($user)->post(
            route('pegawai.update', $employee->id),
            $this->validPayload($employee, [
                'pppk_tmt_pengangkatan' => '2025-01-01',
                'tanggal_akhir_kontrak' => '2029-01-01',
            ])
        );

        $response->assertRedirect(route('data-pegawai'));
        $this->assertSame('2029-01-01', $employee->fresh()->tanggal_akhir_kontrak?->toDateString());
        $this->assertSame('2025-01-01', $appointment->fresh()->tmt_pengangkatan?->toDateString());
        $alert->refresh();
        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_EXPIRED, $alert->followup_status);
        $this->assertTrue($alert->is_processed);
    }

    public function test_non_pppk_employee_cannot_view_or_submit_contract_dates(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $pns = RefJenisPegawai::where('nama', 'PNS')->firstOrFail();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $pns->id,
            'tanggal_akhir_kontrak' => null,
        ]);

        $this->actingAs($user)
            ->get(route('pegawai.edit', $employee->id))
            ->assertOk()
            ->assertDontSee('name="pppk_tmt_pengangkatan"', false)
            ->assertDontSee('name="tanggal_akhir_kontrak"', false);

        $response = $this->actingAs($user)->from(route('pegawai.edit', $employee->id))->post(
            route('pegawai.update', $employee->id),
            $this->validPayload($employee, [
                'pppk_tmt_pengangkatan' => '2025-01-01',
                'tanggal_akhir_kontrak' => '2029-01-01',
            ])
        );

        $response->assertRedirect(route('pegawai.edit', $employee->id));
        $response->assertSessionHasErrors(['pppk_tmt_pengangkatan', 'tanggal_akhir_kontrak']);
        $this->assertNull($employee->fresh()->tanggal_akhir_kontrak);
    }

    public function test_pppk_contract_end_date_cannot_precede_pppk_tmt(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $pppk = RefJenisPegawai::where('nama', 'PPPK')->firstOrFail();
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pppk->id]);
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PPPK',
            'tmt_pengangkatan' => '2025-01-01',
            'no_sk' => 'SK-PPPK-VALIDASI',
            'tanggal_sk' => '2025-01-01',
        ]);

        $response = $this->actingAs($user)->from(route('pegawai.edit', $employee->id))->post(
            route('pegawai.update', $employee->id),
            $this->validPayload($employee, [
                'tanggal_akhir_kontrak' => '2024-12-31',
            ])
        );

        $response->assertRedirect(route('pegawai.edit', $employee->id));
        $response->assertSessionHasErrors('tanggal_akhir_kontrak');
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
            'email_pribadi' => $employee->email_pribadi,
            'golongan_terakhir' => $employee->golongan_terakhir,
            'jabatan_terakhir' => $employee->jabatan_terakhir,
            'kelas_jabatan_terakhir' => $employee->kelas_jabatan_terakhir,
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
        $jenisJabatan = RefJenisJabatan::where('nama', 'Struktural')->first()
            ?: RefJenisJabatan::create(['nama' => 'Struktural']);
        $eselon = RefEselon::first() ?: RefEselon::create(['nama' => 'Eselon I']);
        $unitKerja = RefUnitKerja::first() ?: RefUnitKerja::create(['nama' => 'LLDIKTI']);
        $jabatan = RefJabatan::firstOrCreate(
            ['nama' => 'Kepala Sub Bagian Web'],
            ['jenis_jabatan_id' => $jenisJabatan->id]
        );

        $realCalculator = new TmtCalculatorService;
        $this->mock(TmtCalculatorService::class, function (MockInterface $mock) use ($realCalculator): void {
            $mock->expects('syncForEmployee')
                ->withArgs(function (Employee $employee): bool {
                    $this->assertSame(1, $employee->rankHistories()->count());
                    $this->assertSame(1, $employee->positionHistories()->count());
                    $this->assertSame(1, $employee->salaryHistories()->count());

                    return true;
                })
                ->andReturnUsing(fn (Employee $employee) => $realCalculator->syncForEmployee($employee));
        });

        $payload = $this->validPayload($employee, [
            // Pangkat
            'pangkat_golongan_id' => $golongan->id,
            'pangkat_no_sk' => 'SK-PANGKAT-WEB-001',
            'pangkat_tanggal_sk' => '2026-01-01',
            'pangkat_tmt_pangkat' => '2026-01-02',
            'file_sk_pangkat' => UploadedFile::fake()->create('sk-pangkat.pdf', 500, 'application/pdf'),

            // Jabatan
            'jabatan_jabatan_id' => $jabatan->id,
            'jabatan_eselon_id' => $eselon->id,
            'jabatan_unit_kerja_id' => $unitKerja->id,
            'jabatan_kelas_jabatan' => '9',
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

        $employee->refresh();
        $this->assertSame('2030-01-02', $employee->tanggal_kenaikan_pangkat_berikutnya?->format('Y-m-d'));

        // Assert PositionHistory was created
        $this->assertDatabaseHas('position_histories', [
            'employee_id' => $employee->id,
            'jabatan_id' => $jabatan->id,
            'nama_jabatan' => 'Kepala Sub Bagian Web',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'eselon_id' => $eselon->id,
            'unit_kerja_id' => $unitKerja->id,
            'kelas_jabatan' => '9',
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

        $employee->refresh();
        $this->assertSame('2030-01-02', $employee->tanggal_kenaikan_pangkat_berikutnya?->format('Y-m-d'));
        $this->assertSame('2028-03-02', $employee->tanggal_kgb_berikutnya?->format('Y-m-d'));
    }

    public function test_unrelated_employee_update_does_not_sync_or_change_derived_snapshots(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Nama Sebelum',
            'tanggal_kenaikan_pangkat_berikutnya' => '2031-05-10',
            'tanggal_kgb_berikutnya' => '2029-07-15',
        ]);

        $this->mock(TmtCalculatorService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('syncForEmployee');
        });

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf($this->endpoint($employee), $this->validPayload($employee, [
            'nama_lengkap' => 'Nama Sesudah',
        ]));

        $response->assertOk();
        $employee->refresh();
        $this->assertSame('Nama Sesudah', $employee->nama_lengkap);
        $this->assertSame('2031-05-10', $employee->tanggal_kenaikan_pangkat_berikutnya?->format('Y-m-d'));
        $this->assertSame('2029-07-15', $employee->tanggal_kgb_berikutnya?->format('Y-m-d'));
    }

    public function test_source_update_rebuilds_latest_flags_deterministically_and_ignores_backdated_and_null_tmt(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'tanggal_kenaikan_pangkat_berikutnya' => '2040-01-01',
        ]);
        $golongan = RefGolongan::firstOrFail();
        $createdAt = '2026-06-01 08:00:00';

        $olderCreatedAt = new RankHistory([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => '2026-05-20',
            'is_latest' => true,
        ]);
        $olderCreatedAt->forceFill([
            'id' => 'ffffffff-ffff-4fff-bfff-ffffffffffff',
            'created_at' => '2026-05-31 08:00:00',
            'updated_at' => '2026-05-31 08:00:00',
        ])->save();

        $lowerId = new RankHistory([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => '2026-05-20',
            'is_latest' => true,
        ]);
        $lowerId->forceFill([
            'id' => '00000000-0000-4000-8000-000000000001',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        $higherId = new RankHistory([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => '2026-05-20',
            'is_latest' => false,
        ]);
        $higherId->forceFill([
            'id' => '00000000-0000-4000-8000-000000000002',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => null,
            'is_latest' => true,
        ]);

        $this->actingAs($user);
        $response = $this->withSession(['_token' => 'test-token'])->post("/pegawai/{$employee->id}", $this->validPayload($employee, [
            'pangkat_history_id' => 'new',
            'pangkat_golongan_id' => $golongan->id,
            'pangkat_no_sk' => 'SK-BACKDATE-001',
            'pangkat_tanggal_sk' => '2020-01-02',
            'pangkat_tmt_pangkat' => '2020-01-01',
        ]), ['X-CSRF-TOKEN' => 'test-token']);

        $response->assertRedirect(route('data-pegawai'));
        $this->assertTrue($higherId->fresh()->is_latest);
        $this->assertFalse($lowerId->fresh()->is_latest);
        $this->assertFalse($olderCreatedAt->fresh()->is_latest);
        $this->assertSame(1, $employee->rankHistories()->where('is_latest', true)->count());
        $this->assertSame('2030-05-20', $employee->fresh()->tanggal_kenaikan_pangkat_berikutnya?->format('Y-m-d'));
    }

    public function test_update_locks_employee_before_rebuilding_latest_history(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $golongan = RefGolongan::firstOrFail();
        $queries = collect();

        DB::listen(function ($query) use ($queries): void {
            $queries->push(strtolower($query->sql));
        });

        $this->actingAs($user);
        $response = $this->withSession(['_token' => 'test-token'])->post("/pegawai/{$employee->id}", $this->validPayload($employee, [
            'pangkat_history_id' => 'new',
            'pangkat_golongan_id' => $golongan->id,
            'pangkat_no_sk' => 'SK-RANK-LOCK',
            'pangkat_tanggal_sk' => '2026-02-10',
            'pangkat_tmt_pangkat' => '2026-02-01',
        ]), ['X-CSRF-TOKEN' => 'test-token']);

        $response->assertRedirect(route('data-pegawai'));

        $clearLatestIndex = $queries->search(fn (string $query): bool => str_contains($query, 'update "rank_histories"')
            && str_contains($query, '"is_latest"'));
        $employeeSelectsBeforeRebuild = $queries->take($clearLatestIndex)->filter(fn (string $query): bool => str_contains($query, 'from "employees"')
            && str_contains($query, 'where "employees"."id"'));
        $employeeLockIndex = $employeeSelectsBeforeRebuild->keys()->last();

        $this->assertIsInt($clearLatestIndex);
        $this->assertGreaterThanOrEqual(3, $employeeSelectsBeforeRebuild->count());
        $this->assertIsInt($employeeLockIndex);
        $this->assertLessThan($clearLatestIndex, $employeeLockIndex);
        if (DB::getDriverName() !== 'sqlite') {
            $this->assertStringContainsString('for update', $queries->get($employeeLockIndex));
        }
    }

    public function test_web_rank_history_sets_next_promotion_date_from_tmt(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $golongan = RefGolongan::where('kode', 'III/a')->firstOrFail();

        $this->actingAs($user);
        $response = $this->withSession(['_token' => 'test-token'])
            ->post("/pegawai/{$employee->id}", $this->validPayload($employee, [
                'pangkat_history_id' => 'new',
                'pangkat_golongan_id' => $golongan->id,
                'pangkat_no_sk' => 'SK-PANGKAT-EWS-001',
                'pangkat_tanggal_sk' => '2022-07-01',
                'pangkat_tmt_pangkat' => '2022-07-22',
            ]), ['X-CSRF-TOKEN' => 'test-token']);

        $response->assertRedirect(route('data-pegawai'));
        $this->assertDatabaseHas('rank_histories', [
            'employee_id' => $employee->id,
            'no_sk' => 'SK-PANGKAT-EWS-001',
            'is_latest' => true,
        ]);
        $this->assertSame('2026-07-22', $employee->fresh()->tanggal_kenaikan_pangkat_berikutnya?->format('Y-m-d'));
    }

    public function test_web_backdated_rank_history_keeps_latest_snapshot_and_ews_target(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $oldGolongan = RefGolongan::where('kode', 'III/a')->firstOrFail();
        $latestGolongan = RefGolongan::where('kode', 'III/b')->firstOrFail();
        $latest = $employee->rankHistories()->create([
            'golongan_id' => $latestGolongan->id,
            'no_sk' => 'SK-PANGKAT-LATEST',
            'tanggal_sk' => '2022-07-01',
            'tmt_pangkat' => '2022-07-22',
            'is_latest' => true,
        ]);

        $this->actingAs($user);
        $response = $this->withSession(['_token' => 'test-token'])
            ->post("/pegawai/{$employee->id}", $this->validPayload($employee, [
                'pangkat_history_id' => 'new',
                'pangkat_golongan_id' => $oldGolongan->id,
                'pangkat_no_sk' => 'SK-PANGKAT-LAMA',
                'pangkat_tanggal_sk' => '2020-07-01',
                'pangkat_tmt_pangkat' => '2020-07-22',
            ]), ['X-CSRF-TOKEN' => 'test-token']);

        $response->assertRedirect(route('data-pegawai'));
        $this->assertTrue($latest->fresh()->is_latest);
        $this->assertDatabaseHas('rank_histories', [
            'employee_id' => $employee->id,
            'no_sk' => 'SK-PANGKAT-LAMA',
            'is_latest' => false,
        ]);
        $employee->refresh();
        $this->assertSame('III/b', $employee->golongan_terakhir);
        $this->assertSame('2026-07-22', $employee->tanggal_kenaikan_pangkat_berikutnya?->format('Y-m-d'));
    }

    public function test_berkas_lainnya_preset_ktp_is_saved_as_document_on_employee_update(): void
    {
        Storage::fake('public');
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $payload = $this->validPayload($employee, [
            'berkas_lainnya_jenis' => 'KTP',
            'berkas_lainnya_nomor' => 'KTP-1234-07-2026',
            'berkas_lainnya_deskripsi' => 'KTP pegawai terbaru',
            'berkas_lainnya_tanggal' => '2026-07-01',
            'file_berkas_lainnya' => UploadedFile::fake()->create('ktp.pdf', 300, 'application/pdf'),
        ]);

        $this->actingAs($user);
        $response = $this->withSession(['_token' => 'test-token'])
            ->post("/pegawai/{$employee->id}", $payload, ['X-CSRF-TOKEN' => 'test-token']);

        $response->assertRedirect(route('data-pegawai'));

        // KTP & KK dikategorikan sebagai ktp_kk; nama dokumen memakai label jenis.
        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'ktp_kk',
            'nama_dokumen' => 'KTP',
            'nomor_dokumen' => 'KTP-1234-07-2026',
            'keterangan' => 'KTP pegawai terbaru',
        ]);

        $document = $employee->documents()->firstOrFail();
        $this->assertStringStartsWith("berkas/{$employee->id}/", $document->file_path);
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        $disk->assertExists($document->file_path);
    }

    public function test_berkas_lainnya_manual_jenis_is_saved_as_lainnya_category(): void
    {
        Storage::fake('public');
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $payload = $this->validPayload($employee, [
            'berkas_lainnya_jenis' => 'Lainnya',
            'berkas_lainnya_jenis_manual' => 'Sertifikat Pelatihan',
            'file_berkas_lainnya' => UploadedFile::fake()->create('sertifikat.pdf', 300, 'application/pdf'),
        ]);

        $this->actingAs($user);
        $response = $this->withSession(['_token' => 'test-token'])
            ->post("/pegawai/{$employee->id}", $payload, ['X-CSRF-TOKEN' => 'test-token']);

        $response->assertRedirect(route('data-pegawai'));
        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Sertifikat Pelatihan',
        ]);
    }

    public function test_berkas_lainnya_is_not_saved_when_no_file_uploaded(): void
    {
        Storage::fake('public');
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        // Jenis dipilih tetapi tanpa file → tidak boleh membuat dokumen.
        $payload = $this->validPayload($employee, [
            'berkas_lainnya_jenis' => 'KTP',
        ]);

        $this->actingAs($user);
        $response = $this->from("/pegawai/{$employee->id}/edit")
            ->withSession(['_token' => 'test-token'])
            ->post("/pegawai/{$employee->id}", $payload, ['X-CSRF-TOKEN' => 'test-token']);

        // file_berkas_lainnya required_with:berkas_lainnya_jenis → validasi gagal.
        $response->assertSessionHasErrors('file_berkas_lainnya');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_upload_sk_mutasi_changes_employee_status_to_mutasi(): void
    {
        Storage::fake('public');
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $statusMutasi = RefStatusPegawai::where('nama', 'Mutasi')->firstOrFail();

        $payload = $this->validPayload($employee, [
            'berkas_lainnya_jenis' => 'SK Mutasi',
            'berkas_lainnya_nomor' => 'SK-MUT-001',
            'file_berkas_lainnya' => UploadedFile::fake()->create('mutasi.pdf', 300, 'application/pdf'),
        ]);

        $this->actingAs($user);
        $response = $this->withSession(['_token' => 'test-token'])
            ->post("/pegawai/{$employee->id}", $payload, ['X-CSRF-TOKEN' => 'test-token']);

        $response->assertRedirect(route('data-pegawai'));
        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_mutasi',
            'nama_dokumen' => 'SK Mutasi',
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'status_pegawai_id' => $statusMutasi->id,
            'status_aktif' => 'Mutasi',
        ]);
    }

    public function test_upload_sk_pensiun_changes_employee_status_to_pensiun(): void
    {
        Storage::fake('public');
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $statusPensiun = RefStatusPegawai::where('nama', 'Pensiun')->firstOrFail();

        $payload = $this->validPayload($employee, [
            'berkas_lainnya_jenis' => 'SK Pensiun',
            'file_berkas_lainnya' => UploadedFile::fake()->create('pensiun.pdf', 300, 'application/pdf'),
        ]);

        $this->actingAs($user);
        $response = $this->withSession(['_token' => 'test-token'])
            ->post("/pegawai/{$employee->id}", $payload, ['X-CSRF-TOKEN' => 'test-token']);

        $response->assertRedirect(route('data-pegawai'));
        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pensiun',
            'nama_dokumen' => 'SK Pensiun',
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'status_pegawai_id' => $statusPensiun->id,
            'status_aktif' => 'Pensiun',
        ]);
    }

    public function test_upload_ktp_does_not_change_employee_status(): void
    {
        Storage::fake('public');
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);

        $payload = $this->validPayload($employee, [
            'berkas_lainnya_jenis' => 'KTP',
            'file_berkas_lainnya' => UploadedFile::fake()->create('ktp.pdf', 300, 'application/pdf'),
        ]);

        $this->actingAs($user);
        $response = $this->withSession(['_token' => 'test-token'])
            ->post("/pegawai/{$employee->id}", $payload, ['X-CSRF-TOKEN' => 'test-token']);

        $response->assertRedirect(route('data-pegawai'));
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'status_aktif' => 'Aktif',
        ]);
    }
}
