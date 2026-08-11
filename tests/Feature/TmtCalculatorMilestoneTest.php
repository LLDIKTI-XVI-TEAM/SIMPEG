<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\EwsConfig;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\SalaryHistory;
use App\Services\Employees\TmtCalculatorService;
use Carbon\Carbon;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Menguji kalkulasi dan penyimpanan milestone Satyalancana. */
class TmtCalculatorMilestoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
    }

    public function test_calculator_stores_satyalancana_milestones(): void
    {
        // Setup employee dengan TMT pengangkatan
        $jenisPegawai = RefJenisPegawai::where('nama', 'PNS')->firstOrFail();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $jenisPegawai->id,
            'tanggal_lahir' => Carbon::create(1990, 5, 15),
        ]);

        // Buat appointment dengan TMT pengangkatan
        $tmtPengangkatan = Carbon::create(2006, 1, 1);
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => $tmtPengangkatan,
            'no_sk' => 'SK-001/2006',
            'tanggal_sk' => $tmtPengangkatan,
        ]);

        // Buat riwayat pangkat untuk trigger kalkulasi
        $golongan = RefGolongan::where('kode', 'III/a')->firstOrFail();
        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'no_sk' => 'SK-PANGKAT-001',
            'tanggal_sk' => Carbon::create(2020, 1, 1),
            'tmt_pangkat' => Carbon::create(2020, 1, 1),
            'gaji_pokok' => 3000000,
        ]);

        // Jalankan service
        $service = app(TmtCalculatorService::class);
        $service->syncForEmployee($employee);

        // Verifikasi 3 milestone Satyalancana tersimpan
        $allMilestones = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
            ->orderBy('milestone_date')
            ->get();

        $this->assertCount(3, $allMilestones, 'Harus ada 3 milestone Satyalancana');

        $satyalancana10 = $allMilestones->get(0);
        $satyalancana20 = $allMilestones->get(1);
        $satyalancana30 = $allMilestones->get(2);

        $this->assertEquals('2016-01-01', $satyalancana10->milestone_date->toDateString());
        $this->assertEquals('2026-01-01', $satyalancana20->milestone_date->toDateString());
        $this->assertEquals('2036-01-01', $satyalancana30->milestone_date->toDateString());

        $this->assertTrue($satyalancana10->is_active);
        $this->assertTrue($satyalancana20->is_active);
        $this->assertTrue($satyalancana30->is_active);

        // Verifikasi metadata tersimpan dengan benar
        $this->assertEquals('2006-01-01', $satyalancana10->metadata['tmt_pengangkatan']);
        $this->assertEquals(10, $satyalancana10->metadata['years_of_service']);
    }

    public function test_calculator_stores_all_milestone_types(): void
    {
        $jenisPegawai = RefJenisPegawai::where('nama', 'PNS')->firstOrFail();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $jenisPegawai->id,
            'tanggal_lahir' => Carbon::create(1980, 6, 10),
        ]);

        // Appointment
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => Carbon::create(2000, 1, 1),
            'no_sk' => 'SK-APP-001',
            'tanggal_sk' => Carbon::create(2000, 1, 1),
        ]);

        // Riwayat Pangkat
        $golongan = RefGolongan::where('kode', 'III/b')->firstOrFail();
        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'no_sk' => 'SK-PANGKAT-002',
            'tanggal_sk' => Carbon::create(2022, 1, 1),
            'tmt_pangkat' => Carbon::create(2022, 1, 1),
            'gaji_pokok' => 3500000,
        ]);

        // Riwayat KGB
        SalaryHistory::create([
            'employee_id' => $employee->id,
            'no_sk' => 'SK-KGB-001',
            'tanggal_sk' => Carbon::create(2024, 1, 1),
            'tmt_kgb' => Carbon::create(2024, 1, 1),
            'gaji_pokok' => 3600000,
        ]);

        // Riwayat Jabatan (untuk kalkulasi pensiun)
        $jenisJabatan = RefJenisJabatan::firstOrFail();
        $jabatan = RefJabatan::firstOrFail();

        PositionHistory::create([
            'employee_id' => $employee->id,
            'jabatan_id' => $jabatan->id,
            'jenis_jabatan_id' => $jenisJabatan->id,
            'no_sk' => 'SK-JAB-001',
            'tanggal_sk' => Carbon::create(2020, 1, 1),
            'tmt_jabatan' => Carbon::create(2020, 1, 1),
        ]);

        // Jalankan service
        $service = app(TmtCalculatorService::class);
        $service->syncForEmployee($employee);

        // Verifikasi semua tipe milestone tersimpan
        $milestones = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('is_active', true)
            ->get();

        $types = $milestones->pluck('type')->toArray();

        $this->assertContains(EmployeeMilestone::TYPE_KENAIKAN_PANGKAT, $types);
        $this->assertContains(EmployeeMilestone::TYPE_KGB, $types);
        $this->assertContains(EmployeeMilestone::TYPE_PENSIUN, $types);
        $this->assertContains(EmployeeMilestone::TYPE_SATYALANCANA, $types);

        // Verifikasi milestone kenaikan pangkat
        $pangkatMilestone = $milestones->where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)->first();
        $this->assertEquals('2026-01-01', $pangkatMilestone->milestone_date->toDateString());
        $this->assertEquals('2022-01-01', $pangkatMilestone->metadata['tmt_pangkat']);

        // Verifikasi milestone KGB
        $kgbMilestone = $milestones->where('type', EmployeeMilestone::TYPE_KGB)->first();
        $this->assertEquals('2026-01-01', $kgbMilestone->milestone_date->toDateString());
        $this->assertEquals('2024-01-01', $kgbMilestone->metadata['tmt_kgb']);

        // Verifikasi milestone pensiun
        $pensiunMilestone = $milestones->where('type', EmployeeMilestone::TYPE_PENSIUN)->first();
        $this->assertNotNull($pensiunMilestone, 'Milestone pensiun tidak tersimpan');
        // Pensiun calculation depends on BUP from jabatan/jenis_jabatan
    }

    public function test_calculator_updates_existing_milestones_when_history_changes(): void
    {
        $jenisPegawai = RefJenisPegawai::where('nama', 'PNS')->firstOrFail();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $jenisPegawai->id,
        ]);

        // Initial appointment
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => Carbon::create(2010, 1, 1),
            'no_sk' => 'SK-INIT-001',
            'tanggal_sk' => Carbon::create(2010, 1, 1),
        ]);

        // Riwayat pangkat awal
        $golongan = RefGolongan::where('kode', 'III/a')->firstOrFail();
        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'no_sk' => 'SK-OLD',
            'tanggal_sk' => Carbon::create(2020, 1, 1),
            'tmt_pangkat' => Carbon::create(2020, 1, 1),
            'gaji_pokok' => 3000000,
        ]);

        $service = app(TmtCalculatorService::class);
        $service->syncForEmployee($employee);

        // Verifikasi milestone awal
        $oldMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
            ->first();

        $this->assertEquals('2024-01-01', $oldMilestone->milestone_date->toDateString());

        // Tambah riwayat pangkat baru (lebih recent)
        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'no_sk' => 'SK-NEW',
            'tanggal_sk' => Carbon::create(2023, 6, 1),
            'tmt_pangkat' => Carbon::create(2023, 6, 1),
            'gaji_pokok' => 3200000,
        ]);

        // Jalankan service lagi
        $service->syncForEmployee($employee);

        // Verifikasi milestone di-update (bukan create baru)
        $updatedMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
            ->first();

        $this->assertEquals('2027-06-01', $updatedMilestone->milestone_date->toDateString());
        $this->assertEquals('2023-06-01', $updatedMilestone->metadata['tmt_pangkat']);

        // Verifikasi hanya ada 1 record kenaikan pangkat (updateOrCreate worked)
        $count = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_calculator_stores_pppk_contract_end_milestone(): void
    {
        $jenisPegawai = RefJenisPegawai::where('nama', 'PPPK')->firstOrFail();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $jenisPegawai->id,
            'tanggal_akhir_kontrak' => Carbon::create(2027, 12, 31),
        ]);

        // Buat appointment untuk trigger service
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PPPK',
            'tmt_pengangkatan' => Carbon::create(2024, 1, 1),
            'no_sk' => 'SK-PPPK-001',
            'tanggal_sk' => Carbon::create(2024, 1, 1),
        ]);

        $service = app(TmtCalculatorService::class);
        $service->syncForEmployee($employee);

        // Verifikasi milestone PPPK contract end tersimpan
        $milestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PPPK_CONTRACT_END)
            ->first();

        $this->assertNotNull($milestone, 'PPPK contract end milestone tidak tersimpan');
        $this->assertEquals('2027-12-31', $milestone->milestone_date->toDateString());
        $this->assertTrue($milestone->is_active);
        $this->assertEquals('PPPK', $milestone->metadata['contract_type']);
    }

    public function test_calculator_does_not_create_milestones_without_source_data(): void
    {
        // Employee tanpa riwayat apapun
        $employee = Employee::factory()->create([
            'tanggal_lahir' => null,
            'tanggal_pensiun' => null,
        ]);

        $service = app(TmtCalculatorService::class);
        $service->syncForEmployee($employee);

        // Verifikasi tidak ada milestone tersimpan
        $count = EmployeeMilestone::where('employee_id', $employee->id)->count();

        $this->assertEquals(0, $count);
    }

    /** Konfigurasi global hanya menjadi fallback terakhir ketika referensi BUP tidak tersedia. */
    public function test_calculator_persists_global_pension_fallback_with_provenance(): void
    {
        EwsConfig::setVal('pensiun_required_age_years', '60');

        $employee = Employee::factory()->create([
            'tanggal_lahir' => Carbon::create(1990, 5, 15),
            'tanggal_pensiun' => null,
        ]);

        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $this->assertSame('2050-05-15', $employee->refresh()->tanggal_pensiun?->toDateString());

        $milestone = EmployeeMilestone::query()
            ->where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
            ->where('is_active', true)
            ->sole();

        $this->assertSame('2050-05-15', $milestone->milestone_date->toDateString());
        $this->assertSame('calculated_from_global_config', $milestone->metadata['source']);
        $this->assertSame('pensiun_required_age_years', $milestone->metadata['config_key']);
        $this->assertSame(60, $milestone->metadata['bup']);
        $this->assertNull($milestone->metadata['jabatan']);
    }
}
