<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\SimpegNotification;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PegawaiDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_dashboard_pegawai_menampilkan_notifikasi_miliknya_bukan_milik_pegawai_lain(): void
    {
        [$user, $employee] = $this->pegawaiWithEmployee();
        $otherEmployee = Employee::factory()->create();

        // Kolom user_id pada tabel notifications adalah FK ke employees, bukan users.
        // Test ini mengunci kontrak tersebut karena query dashboard pernah salah
        // memfilter dengan id user sehingga widget notifikasi selalu kosong.
        $this->notificationFor($employee, 'Notifikasi Milik Sendiri');
        $this->notificationFor($otherEmployee, 'Notifikasi Pegawai Lain');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Notifikasi Milik Sendiri', false)
            ->assertDontSee('Notifikasi Pegawai Lain', false);
    }

    public function test_dashboard_pegawai_membatasi_notifikasi_ke_lima_terbaru(): void
    {
        [$user, $employee] = $this->pegawaiWithEmployee();

        foreach (range(1, 6) as $index) {
            $this->notificationFor($employee, 'Notifikasi Ke-'.$index, [
                'created_at' => now()->subMinutes(10 - $index),
            ]);
        }

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Notifikasi Ke-6', false)
            ->assertSee('Notifikasi Ke-2', false)
            ->assertDontSee('Notifikasi Ke-1', false);
    }

    public function test_dashboard_pegawai_menampilkan_saldo_cuti_dari_database(): void
    {
        [$user, $employee] = $this->pegawaiWithEmployee();

        LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => (int) date('Y'),
            'jatah_awal' => 18,
            'carry_over' => 3,
            'terpakai' => 4,
            'sisa' => 17,
        ]);

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $response->assertViewHas('saldoCuti', fn ($saldo): bool => $saldo !== null
            && $saldo['jatah_dasar'] === 18
            && $saldo['carry_over'] === 3
            && $saldo['saldo_dapat_diajukan'] === 17);
    }

    public function test_dashboard_pegawai_tanpa_saldo_mengirim_saldo_null_ke_view(): void
    {
        [$user] = $this->pegawaiWithEmployee();

        // Kontrak untuk empty state di sisi tampilan: tanpa baris saldo, backend
        // mengirim null dan tidak mengarang angka jatah default.
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('saldoCuti', fn (mixed $saldo): bool => is_array($saldo)
                && $saldo['eligible'] === false
                && $saldo['saldo_dapat_diajukan'] === 0
                && $saldo['rule_5_active'] === false);
    }

    public function test_dashboard_pegawai_menampilkan_cuti_aktif_tanpa_cuti_yang_sudah_diputuskan(): void
    {
        [$user, $employee] = $this->pegawaiWithEmployee();
        $leaveType = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'CUTI_TAHUNAN',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => now()->addDays(7)->toDateString(),
            'tanggal_selesai' => now()->addDays(9)->toDateString(),
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Pengajuan masih menunggu approval.',
            'status' => 'menunggu_approval',
        ]);
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => now()->subDays(30)->toDateString(),
            'tanggal_selesai' => now()->subDays(28)->toDateString(),
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Cuti lama yang sudah disetujui.',
            'status' => 'disetujui',
        ]);

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $response->assertViewHas('cutiAktif', fn ($cuti): bool => $cuti->count() === 1
            && $cuti->first()->status === 'menunggu_approval');
    }

    public function test_dashboard_pegawai_tanpa_mapping_employee_tetap_dapat_membuka_dashboard(): void
    {
        $user = User::factory()->pegawai()->create(['employee_id' => null]);

        // Pegawai yang belum terpetakan ke data employee (mapping SSO belum
        // lengkap) tidak boleh membuat dashboard error.
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('saldoCuti', null)
            ->assertSee('Belum ada notifikasi', false);
    }

    public function test_dashboard_pegawai_rule_5_menampilkan_sisa_efektif_nol_tanpa_mengubah_saldo_tercatat(): void
    {
        [$user, $employee] = $this->pegawaiWithEmployee();
        $balance = LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => now()->year,
            'jatah_awal' => 12,
            'carry_over' => 6,
            'terpakai' => 0,
            'sisa' => 18,
            'sisa_n2' => 0,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);
        $large = RefJenisCuti::create([
            'nama' => 'Cuti Besar',
            'code' => 'besar',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => true,
        ]);
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $large->id,
            'tanggal_mulai' => now()->startOfYear()->addMonths(2)->toDateString(),
            'tanggal_selesai' => now()->startOfYear()->addMonths(2)->addDays(30)->toDateString(),
            'jumlah_hari_kerja' => 20,
            'alasan' => 'Cuti Besar final.',
            'status' => 'disetujui',
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertViewHas('rule5Active', true)
            ->assertViewHas('saldoCuti', fn (mixed $saldo): bool => is_array($saldo)
                && $saldo['rule_5_active'] === true
                && $saldo['saldo_dapat_diajukan'] === 0)
            ->assertSee('Hak Efektif Tahun Ini', false)
            ->assertSee('tidak dapat digunakan pada tahun Cuti Besar', false);
        $this->assertSame(18, $balance->fresh()->sisa);
    }

    /**
     * @return array{0: User, 1: Employee}
     */
    private function pegawaiWithEmployee(): array
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        return [$user, $employee];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function notificationFor(Employee $employee, string $title, array $overrides = []): SimpegNotification
    {
        return SimpegNotification::forceCreate(array_merge([
            'user_id' => $employee->id,
            'type' => 'cuti.disetujui',
            'title' => $title,
            'body' => 'Isi notifikasi '.$title,
            'is_read' => false,
        ], $overrides));
    }
}
