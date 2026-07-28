<?php

namespace Tests\Feature;

use App\Actions\Dashboards\BuildPimpinanDashboardAction;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PimpinanDashboardDataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Markup pemisah transisi golongan; diasersi sebagai markup, bukan glif, agar bebas soal encoding.
     */
    private const MARKUP_PANAH = '<span class="text-muted mx-1">';

    public function test_pimpinan_dashboard_renders_current_database_records_and_real_monitoring_links(): void
    {
        $this->seed(RbacSeeder::class);

        $pimpinanEmployee = Employee::factory()->create(['nama_lengkap' => 'Pimpinan LLDIKTI']);
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Eka Pramesti',
            'nip' => '198505052011052005',
            'golongan_terakhir' => 'III/d',
        ]);
        $rank = RefGolongan::create(['kode' => 'III/d', 'nama' => 'Penata Tingkat I']);
        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $rank->id,
            'tmt_pangkat' => now()->startOfMonth(),
            'no_sk' => 'SK-321/2026',
            'tanggal_sk' => now()->startOfMonth(),
            'is_latest' => true,
        ]);
        $leaveType = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'CUTI_TAHUNAN',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => now()->addWeek(),
            'tanggal_selesai' => now()->addWeeks(2),
            'jumlah_hari_kerja' => 5,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $pimpinanEmployee->id,
            'status' => 'active',
            'is_final' => true,
        ]);
        EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => now()->addDays(14),
            'interval_days' => 30,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        AuditLog::create([
            'user_name' => 'Admin Kepegawaian',
            'event' => 'UPDATE',
            'auditable_type' => Employee::class,
            'auditable_id' => $employee->id,
            'new_values' => ['nama_lengkap' => $employee->nama_lengkap],
        ]);

        $response = $this->actingAs(User::factory()->pimpinan()->create([
            'employee_id' => $pimpinanEmployee->id,
        ]))->get(route('pimpinan.dashboard'));

        $response->assertOk()
            ->assertSee('Eka Pramesti')
            ->assertSee('SK-321/2026')
            ->assertSee('Admin Kepegawaian')
            ->assertSee(route('pimpinan.cuti.show', $leave), false)
            ->assertSee(route('pimpinan.ews.index', ['event' => 'Kenaikan Pangkat']), false)
            ->assertSee(route('pimpinan.cuti.index', ['status' => 'menunggu']), false)
            ->assertSee('Pengajuan Cuti')
            ->assertSee('href="'.route('cuti').'"', false)
            ->assertSee('globalSearch()', false)
            ->assertDontSee('Ahmad Fauzi')
            ->assertDontSee('Nadia Kusuma')
            ->assertDontSee('Admin HR');
    }

    public function test_tren_pegawai_aktif_excludes_non_aktif_and_mutasi_employees(): void
    {
        $this->seed(RbacSeeder::class);
        $pimpinanUser = User::factory()->pimpinan()->create();

        // Pegawai Non-Aktif tanpa tanggal pensiun
        Employee::factory()->create([
            'status_aktif' => 'Non-Aktif',
            'tanggal_pensiun' => null,
            'created_at' => now()->subMonths(6),
        ]);

        // Pegawai Mutasi tanpa tanggal pensiun
        Employee::factory()->create([
            'status_aktif' => 'Mutasi',
            'tanggal_pensiun' => null,
            'created_at' => now()->subMonths(6),
        ]);

        // Pegawai Aktif
        $aktif = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => null,
            'created_at' => now()->subMonths(6),
        ]);

        $action = app(BuildPimpinanDashboardAction::class);
        $data = $action->execute($pimpinanUser);

        $lastTrendPoint = collect($data['trenPegawai'])->last();
        $this->assertEquals(1, $lastTrendPoint['jumlah']);
    }

    /**
     * Mengambil potongan HTML satu baris kenaikan pangkat.
     * Gagal keras bila penanda tidak ada supaya assertion tidak lolos pada irisan kosong.
     */
    private function irisBarisKenaikan(string $content, string $awal, string $akhir): string
    {
        $mulai = strpos($content, $awal);
        $selesai = strpos($content, $akhir);

        $this->assertNotFalse($mulai, "Baris pegawai {$awal} tidak dirender.");
        $this->assertNotFalse($selesai, "Penanda akhir {$akhir} tidak dirender.");
        $this->assertGreaterThan($mulai, $selesai);

        return substr($content, $mulai, $selesai - $mulai);
    }

    public function test_pegawai_dengan_satu_riwayat_pangkat_tidak_punya_golongan_asal(): void
    {
        $this->seed(RbacSeeder::class);

        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Pangkat Pertama']);
        $golongan = RefGolongan::create(['kode' => 'III/c', 'nama' => 'Penata']);
        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => now()->startOfMonth(),
            'no_sk' => 'SK-100/2026',
            'tanggal_sk' => now()->startOfMonth(),
            'is_latest' => true,
        ]);

        $payload = app(BuildPimpinanDashboardAction::class)
            ->execute(User::factory()->pimpinan()->create());
        $row = collect($payload['promotionRows'])->firstWhere('nip', $employee->nip);

        $this->assertNotNull($row);
        // Golongan asal wajib null, bukan '-', agar guard !empty() di Blade menolak render panah transisi.
        $this->assertNull($row['golongan_awal']);
        $this->assertSame('III/c', $row['golongan_tujuan']);
    }

    public function test_pegawai_dengan_riwayat_sebelumnya_menampilkan_transisi_golongan(): void
    {
        $this->seed(RbacSeeder::class);

        $pimpinanEmployee = Employee::factory()->create();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Naik Pangkat']);
        $lama = RefGolongan::create(['kode' => 'III/b', 'nama' => 'Penata Muda Tingkat I']);
        $baru = RefGolongan::create(['kode' => 'III/c', 'nama' => 'Penata']);
        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $lama->id,
            'tmt_pangkat' => now()->startOfMonth()->subYears(4),
            'no_sk' => 'SK-050/2022',
            'tanggal_sk' => now()->startOfMonth()->subYears(4),
            'is_latest' => false,
        ]);
        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $baru->id,
            'tmt_pangkat' => now()->startOfMonth(),
            'no_sk' => 'SK-200/2026',
            'tanggal_sk' => now()->startOfMonth(),
            'is_latest' => true,
        ]);

        $payload = app(BuildPimpinanDashboardAction::class)
            ->execute(User::factory()->pimpinan()->create());
        $row = collect($payload['promotionRows'])->firstWhere('nip', $employee->nip);

        $this->assertNotNull($row);
        $this->assertSame('III/b', $row['golongan_awal']);
        $this->assertSame('III/c', $row['golongan_tujuan']);

        $response = $this->actingAs(User::factory()->pimpinan()->create([
            'employee_id' => $pimpinanEmployee->id,
        ]))->get(route('pimpinan.dashboard'));

        $response->assertOk();
        $baris = $this->irisBarisKenaikan($response->getContent() ?: '', 'Pegawai Naik Pangkat', 'SK-200/2026');
        $this->assertStringContainsString(self::MARKUP_PANAH, $baris);
        $this->assertStringContainsString('III/b', $baris);
    }

    public function test_dashboard_menampilkan_golongan_tunggal_tanpa_panah_saat_tanpa_golongan_asal(): void
    {
        $this->seed(RbacSeeder::class);

        $pimpinanEmployee = Employee::factory()->create();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Pangkat Pertama']);
        $golongan = RefGolongan::create(['kode' => 'III/c', 'nama' => 'Penata']);
        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => now()->startOfMonth(),
            'no_sk' => 'SK-100/2026',
            'tanggal_sk' => now()->startOfMonth(),
            'is_latest' => true,
        ]);

        $response = $this->actingAs(User::factory()->pimpinan()->create([
            'employee_id' => $pimpinanEmployee->id,
        ]))->get(route('pimpinan.dashboard'));

        $response->assertOk()->assertSee('III/c');
        $content = $response->getContent() ?: '';
        $baris = $this->irisBarisKenaikan($content, 'Pegawai Pangkat Pertama', 'SK-100/2026');

        // Panah transisi hanya boleh muncul bila golongan asal benar-benar ada.
        $this->assertStringNotContainsString(self::MARKUP_PANAH, $baris);
        $this->assertStringContainsString('III/c', $baris);
    }
}
