<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\Cuti\LeaveUsageRecordService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\RecordsHistoricalAnnualLeaveUsage;
use Tests\TestCase;

class PegawaiDashboardTest extends TestCase
{
    use RecordsHistoricalAnnualLeaveUsage;
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
        $this->setEmployeeAsPns($employee);

        $this->reconcileAnnualUsage($employee, usageN2: 12, usageN1: 9, usageCurrent: 4);

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $response->assertViewHas('saldoCuti', fn ($saldo): bool => $saldo !== null
            && $saldo['jatah_dasar'] === 12
            && $saldo['carry_over'] === 2
            && $saldo['terpakai_final'] === 4
            && $saldo['saldo_dapat_diajukan'] === 14);
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
            'code' => 'tahunan',
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
            'tanggal_mulai' => now()->addDays(14)->toDateString(),
            'tanggal_selesai' => now()->addDays(16)->toDateString(),
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Pengajuan sedang menunggu keputusan pembatalan.',
            'status' => LeaveRequest::STATUS_CANCELLATION_PENDING,
        ]);
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => now()->subDays(14)->toDateString(),
            'tanggal_selesai' => now()->subDays(12)->toDateString(),
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Pengajuan yang sudah dibatalkan.',
            'status' => LeaveRequest::STATUS_CANCELLED,
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

        $response->assertViewHas('cutiAktif', fn ($cuti): bool => $cuti->count() === 2
            && $cuti->pluck('status')->sort()->values()->all() === [
                'menunggu_approval',
                LeaveRequest::STATUS_CANCELLATION_PENDING,
            ])
            ->assertSee('Menunggu Keputusan Pembatalan')
            ->assertDontSee('Pengajuan yang sudah dibatalkan.');
    }

    public function test_dashboard_pegawai_tanpa_mapping_employee_dialihkan_ke_status_akun(): void
    {
        $user = User::factory()->pegawai()->create(['employee_id' => null]);

        // Mapping pegawai adalah bagian identitas akun; relasi yang belum lengkap
        // harus ditangani fail-closed tanpa membuat dashboard error.
        $this->actingAsUnmapped($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('status-akun'));
    }

    public function test_dashboard_pegawai_rule_5_menampilkan_sisa_efektif_nol_tanpa_mengubah_saldo_tercatat(): void
    {
        [$user, $employee] = $this->pegawaiWithEmployee();
        $this->setEmployeeAsPns($employee);
        $this->reconcileAnnualUsage($employee, usageN2: 12, usageN1: 6, usageCurrent: 0);
        $large = RefJenisCuti::create([
            'nama' => 'Cuti Besar',
            'code' => 'besar',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => true,
        ]);
        $largeRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $large->id,
            'tanggal_mulai' => now()->startOfYear()->addMonths(2)->toDateString(),
            'tanggal_selesai' => now()->startOfYear()->addMonths(2)->addDays(30)->toDateString(),
            'jumlah_hari_kerja' => 20,
            'alasan' => 'Cuti Besar final.',
            'status' => 'disetujui',
        ]);
        $approverEmployee = Employee::factory()->create();
        $approver = User::factory()->pimpinan()->create(['employee_id' => $approverEmployee->id]);
        app(LeaveUsageRecordService::class)->recordApprovedRequest(
            $largeRequest,
            $approver,
            $this->actorRequest($approver),
        );
        $balance = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', now()->year)
            ->firstOrFail();

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

    private function setEmployeeAsPns(Employee $employee): void
    {
        $pns = RefJenisPegawai::query()->firstOrCreate(['nama' => 'PNS']);
        $employee->forceFill(['jenis_pegawai_id' => $pns->id])->save();
    }

    private function reconcileAnnualUsage(
        Employee $employee,
        int $usageN2,
        int $usageN1,
        int $usageCurrent,
    ): void {
        Appointment::query()->updateOrCreate(
            ['employee_id' => $employee->id],
            [
                'jenis_pengangkatan' => 'PNS',
                'tmt_pengangkatan' => '2020-01-01',
            ],
        );
        RefJenisCuti::query()->firstOrCreate(
            ['code' => 'tahunan'],
            [
                'nama' => 'Cuti Tahunan',
                'mengurangi_saldo_tahunan' => true,
                'khusus_pns' => false,
            ],
        );
        $admin = User::factory()->adminKepegawaian()->create();
        $year = now()->year;
        $this->recordHistoricalAnnualUsage(
            $employee,
            [$year - 2 => $usageN2, $year - 1 => $usageN1, $year => $usageCurrent],
            $admin,
            'Fakta pemakaian eksternal fixture dashboard pegawai.',
        );
    }

    private function actorRequest(User $actor): Request
    {
        $request = Request::create('/cuti/usage', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        return $request;
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
