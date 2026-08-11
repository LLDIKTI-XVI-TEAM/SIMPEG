<?php

namespace Tests\Feature;

use App\Actions\Employees\CreateEmployeeAction;
use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\RefAgama;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPerkawinan;
use App\Models\RefUnitKerja;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeCreateIntegrationTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'ReferenceSeeder']);
        $this->artisan('db:seed', ['--class' => 'RbacSeeder']);
    }

    public function test_can_create_employee_via_ui_form()
    {

        // Arrange
        $user = User::factory()->create([
            'role' => 'admin_kepegawaian',
        ]);

        $agamaId = RefAgama::first()->id;
        $jenisPegawaiId = RefJenisPegawai::first()->id;
        $statusKawinId = RefStatusPerkawinan::first()->id;
        $golongan = RefGolongan::firstOrFail();

        $postData = [
            'nama_lengkap' => 'Budi Santoso Uji',
            'nip' => '199001012024011001',
            'nik' => '3273100101900001',
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'L',
            'agama_id' => $agamaId,
            'status_kawin_id' => $statusKawinId,
            'jenis_pegawai_id' => $jenisPegawaiId,
            'golongan_terakhir' => 'III/a',
            'pangkat_terakhir' => 'Penata Muda',
            'jabatan_terakhir' => 'Analis Sistem Informasi',
            'kelas_jabatan' => '7',
            'pangkat_golongan_id' => $golongan->id,
            'pangkat_no_sk' => 'SK-PANGKAT-UJI-001',
            'pangkat_tanggal_sk' => '2025-12-01',
            'pangkat_tmt_pangkat' => '2026-01-02',
            'file_sk_pangkat' => UploadedFile::fake()->create('sk-pangkat.pdf', 500, 'application/pdf'),
            'kgb_gaji_pokok' => '4500000',
            'kgb_no_sk' => 'SK-KGB-UJI-001',
            'kgb_tanggal_sk' => '2026-03-01',
            'kgb_tmt_kgb' => '2026-03-02',
            'file_sk_kgb' => UploadedFile::fake()->create('sk-kgb.pdf', 500, 'application/pdf'),
            'pengangkatan_tmt_pengangkatan' => '2024-01-01',
            'pengangkatan_jenis_pengangkatan' => 'PNS',
            'pengangkatan_no_sk' => 'SK-UJI-001',
            'pengangkatan_tanggal_sk' => '2023-12-01',
            'file_sk_pengangkatan' => UploadedFile::fake()->create('sk-pengangkatan.pdf', 500, 'application/pdf'),
            'is_kepala_lembaga' => true,
        ];

        // Act
        $response = $this->actingAs($user)->post(route('pegawai.store'), $postData);

        // Assert
        if (session()->has('error')) {
            dump(session('error'));
        }
        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('data-pegawai'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('employees', [
            'nama_lengkap' => 'Budi Santoso Uji',
            'nip' => '199001012024011001',
            'is_kepala_lembaga' => true,
        ]);

        $employee = Employee::where('nip', '199001012024011001')->first();

        $this->assertDatabaseHas('rank_histories', [
            'employee_id' => $employee->id,
            'no_sk' => 'SK-PANGKAT-UJI-001',
            'tmt_pangkat' => '2026-01-02 00:00:00',
            'is_latest' => true,
        ]);
        $this->assertSame('2030-01-02', $employee->tanggal_kenaikan_pangkat_berikutnya?->format('Y-m-d'));
        $this->assertSame('2028-03-02', $employee->tanggal_kgb_berikutnya?->format('Y-m-d'));

        $this->assertDatabaseHas('appointments', [
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2024-01-01 00:00:00',
            'no_sk' => 'SK-UJI-001',
            'tanggal_sk' => '2023-12-01 00:00:00',
        ]);

        $activeMilestones = EmployeeMilestone::query()
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->get();

        $this->assertSame(
            ['2028-03-02', '2030-01-02', '2034-01-01', '2044-01-01', '2054-01-01'],
            $activeMilestones
                ->sortBy('milestone_date')
                ->pluck('milestone_date')
                ->map(fn ($date): string => $date->toDateString())
                ->values()
                ->all(),
        );
        $this->assertSame(
            [10, 20, 30],
            $activeMilestones
                ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
                ->sortBy('milestone_date')
                ->map(fn (EmployeeMilestone $milestone): int => (int) $milestone->metadata['satyalancana_years'])
                ->values()
                ->all(),
        );

        $documentPath = $employee->documents()->where('jenis_dokumen', 'sk_pengangkatan')->value('file_path');
        $this->assertIsString($documentPath);
        $this->assertStringStartsWith('appointments/sk/', $documentPath);
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        $disk->assertExists($documentPath);

        // Check if detail page renders
        $detailResponse = $this->actingAs($user)->get(route('pegawai.show', $employee->id));
        $detailResponse->assertStatus(200);
        $detailResponse->assertSee('Budi Santoso Uji');
        $detailResponse->assertSee('Kepala Lembaga');
        $detailResponse->assertSee('Ya');

        $createResponse = $this->actingAs($user)->get(route('pegawai.create'));
        $createResponse->assertStatus(200);
        $createResponse->assertSee('Kepala Lembaga');
        $createResponse->assertSee('name="is_kepala_lembaga"', false);

        $editResponse = $this->actingAs($user)->get(route('pegawai.edit', $employee->id));
        $editResponse->assertStatus(200);
        $editResponse->assertSee('Kepala Lembaga');
        $editResponse->assertSee('name="is_kepala_lembaga"', false);
    }

    /** Pengangkatan pertama pada jalur form publik langsung menghasilkan tiga milestone Satyalancana aktif. */
    public function test_form_create_with_only_appointment_persists_satyalancana_milestones(): void
    {
        $user = User::factory()->create(['role' => 'admin_kepegawaian']);

        $this->actingAs($user)
            ->post(route('pegawai.store'), [
                'nama_lengkap' => 'Pegawai Pengangkatan Pertama',
                'nip' => '199101012024011091',
                'tanggal_lahir' => '1991-01-01',
                'pengangkatan_jenis_pengangkatan' => 'PNS',
                'pengangkatan_tmt_pengangkatan' => '2014-02-03',
                'pengangkatan_no_sk' => 'SK-PENGANGKATAN-ONLY-001',
                'pengangkatan_tanggal_sk' => '2014-01-15',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('data-pegawai'));

        $employee = Employee::query()->where('nip', '199101012024011091')->firstOrFail();
        $milestones = EmployeeMilestone::query()
            ->where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
            ->where('is_active', true)
            ->orderBy('milestone_date')
            ->get();

        $this->assertSame(['10', '20', '30'], $milestones->pluck('milestone_key')->all());
        $this->assertSame(
            ['2024-02-03', '2034-02-03', '2044-02-03'],
            $milestones->pluck('milestone_date')->map(fn ($date): string => $date->toDateString())->all(),
        );
        $this->assertSame(
            [
                ['tmt_pengangkatan' => '2014-02-03', 'satyalancana_years' => 10, 'years_of_service' => 10],
                ['tmt_pengangkatan' => '2014-02-03', 'satyalancana_years' => 20, 'years_of_service' => 20],
                ['tmt_pengangkatan' => '2014-02-03', 'satyalancana_years' => 30, 'years_of_service' => 30],
            ],
            $milestones->pluck('metadata')->all(),
        );
    }

    /** Tanpa riwayat pengangkatan, jalur create tidak membuat milestone Satyalancana semu. */
    public function test_form_create_without_appointment_does_not_persist_satyalancana_milestones(): void
    {
        $user = User::factory()->create(['role' => 'admin_kepegawaian']);

        $this->actingAs($user)
            ->post(route('pegawai.store'), [
                'nama_lengkap' => 'Pegawai Tanpa Pengangkatan',
                'nip' => '199101012024011092',
                'tanggal_lahir' => '1991-01-01',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('data-pegawai'));

        $employee = Employee::query()->where('nip', '199101012024011092')->firstOrFail();

        $this->assertSame(0, $employee->appointments()->count());
        $this->assertSame(
            0,
            $employee->milestones()
                ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
                ->where('is_active', true)
                ->count(),
        );
    }

    /** Sinkronisasi setelah pengangkatan tetap mempertahankan tanggal pensiun resmi dari form. */
    public function test_form_create_with_appointment_preserves_authoritative_pension_date(): void
    {
        $user = User::factory()->create(['role' => 'admin_kepegawaian']);

        $this->actingAs($user)
            ->post(route('pegawai.store'), [
                'nama_lengkap' => 'Pegawai Pensiun Resmi dan Pengangkatan',
                'nip' => '199101012024011093',
                'tanggal_lahir' => '1991-01-01',
                'tanggal_pensiun' => '2049-12-31',
                'pengangkatan_jenis_pengangkatan' => 'PNS',
                'pengangkatan_tmt_pengangkatan' => '2014-02-03',
                'pengangkatan_no_sk' => 'SK-PENGANGKATAN-PENSIUN-001',
                'pengangkatan_tanggal_sk' => '2014-01-15',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('data-pegawai'));

        $employee = Employee::query()->where('nip', '199101012024011093')->firstOrFail();
        $pensionMilestone = EmployeeMilestone::query()
            ->where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
            ->where('is_active', true)
            ->sole();

        $this->assertSame('2049-12-31', $employee->tanggal_pensiun?->toDateString());
        $this->assertSame('2049-12-31', $pensionMilestone->milestone_date->toDateString());
        $this->assertSame('employees.tanggal_pensiun', $pensionMilestone->metadata['source']);
        $this->assertTrue($pensionMilestone->metadata['is_manual']);
        $this->assertSame(3, $employee->milestones()
            ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
            ->where('is_active', true)
            ->count());
    }

    /** Tanggal pensiun dari form adalah data resmi walau pegawai belum memiliki riwayat sumber. */
    public function test_form_create_preserves_explicit_pension_date_during_legacy_recalculation(): void
    {
        $user = User::factory()->create(['role' => 'admin_kepegawaian']);

        $this->actingAs($user)
            ->post(route('pegawai.store'), [
                'nama_lengkap' => 'Pegawai Pensiun Resmi Baru',
                'nip' => '199001012024011099',
                'tanggal_lahir' => '1990-01-01',
                'tanggal_pensiun' => '2048-12-31',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('data-pegawai'));

        $employee = Employee::query()->where('nip', '199001012024011099')->firstOrFail();
        $this->assertSame(1, $employee->milestones()->count());
        $milestone = EmployeeMilestone::query()
            ->where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
            ->sole();

        $this->assertSame('2048-12-31', $employee->tanggal_pensiun?->toDateString());
        $this->assertSame('employees.tanggal_pensiun', $milestone->metadata['source']);
        $this->assertTrue($milestone->metadata['is_manual']);
        $this->assertSame(1, $employee->milestones()->count());

        $this->artisan('milestone:backfill', [
            '--recalculate-legacy-pension' => true,
            '--no-interaction' => true,
        ])
            ->expectsQuestion('Do you want to proceed with the backfill?', true)
            ->assertSuccessful();

        $employee->refresh();
        $milestone->refresh();
        $this->assertSame('2048-12-31', $employee->tanggal_pensiun?->toDateString());
        $this->assertSame('2048-12-31', $milestone->milestone_date->toDateString());
        $this->assertNotSame('legacy_unverified', $milestone->metadata['source']);
        $this->assertSame(1, $employee->milestones()->count());
    }

    public function test_form_penugasan_baru_tidak_menawarkan_jabatan_nonaktif(): void
    {
        $user = User::factory()->create([
            'role' => 'admin_kepegawaian',
        ]);
        $employee = Employee::factory()->create();
        $jabatanAktif = RefJabatan::create([
            'nama' => 'Jabatan Aktif Untuk Penugasan Baru',
        ]);
        $jabatanNonaktif = RefJabatan::create([
            'nama' => 'Jabatan Nonaktif Tidak Boleh Dipilih',
            'is_active' => false,
        ]);

        foreach ([
            route('pegawai.create'),
            route('pegawai.edit', $employee),
            route('pegawai.show', $employee),
        ] as $url) {
            $this->actingAs($user)
                ->get($url)
                ->assertOk()
                ->assertSee($jabatanAktif->nama)
                ->assertDontSee($jabatanNonaktif->nama);
        }
    }

    public function test_null_tmt_source_histories_are_not_latest_and_leave_derived_dates_null(): void
    {
        $user = User::factory()->create(['role' => 'admin_kepegawaian']);
        $golongan = RefGolongan::firstOrFail();
        $jabatan = RefJabatan::firstOrFail();
        $unitKerja = RefUnitKerja::firstOrFail();

        $data = [
            'nama_lengkap' => 'Pegawai TMT Kosong',
            'nip' => '199201012024011001',
            'tanggal_lahir' => '1992-01-01',
            'jenis_kelamin' => 'L',
            'agama_id' => RefAgama::firstOrFail()->id,
            'status_kawin_id' => RefStatusPerkawinan::firstOrFail()->id,
            'jenis_pegawai_id' => RefJenisPegawai::firstOrFail()->id,
            'pangkat_golongan_id' => $golongan->id,
            'pangkat_no_sk' => 'SK-PANGKAT-TANPA-TMT',
            'pangkat_tanggal_sk' => '2026-01-01',
            'pangkat_tmt_pangkat' => null,
            'jabatan_jabatan_id' => $jabatan->id,
            'jabatan_unit_kerja_id' => $unitKerja->id,
            'jabatan_no_sk' => 'SK-JABATAN-TANPA-TMT',
            'jabatan_tanggal_sk' => '2026-02-01',
            'jabatan_tmt_jabatan' => null,
            'kgb_gaji_pokok' => '4500000',
            'kgb_no_sk' => 'SK-KGB-TANPA-TMT',
            'kgb_tanggal_sk' => '2026-03-01',
            'kgb_tmt_kgb' => null,
        ];

        $this->actingAs($user);
        app(CreateEmployeeAction::class)->execute($data, Request::create('/pegawai', 'POST', $data));

        $employee = Employee::where('nip', '199201012024011001')->firstOrFail();
        $this->assertFalse($employee->rankHistories()->firstOrFail()->is_latest);
        $this->assertFalse($employee->positionHistories()->firstOrFail()->is_latest);
        $this->assertFalse($employee->salaryHistories()->firstOrFail()->is_latest);
        $this->assertNull($employee->tanggal_kenaikan_pangkat_berikutnya);
        $this->assertNull($employee->tanggal_kgb_berikutnya);
    }
}
