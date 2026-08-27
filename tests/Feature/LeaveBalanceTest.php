<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_guest_cannot_view_leave_balance(): void
    {
        $response = $this->getJson('/api/v1/profil-saya/saldo-cuti');
        $response->assertRedirect('/login');
    }

    public function test_employee_can_view_own_leave_balance_via_api(): void
    {
        $employee = Employee::factory()->create();
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
        ]);
        $user = User::factory()->pegawai()->create([
            'employee_id' => $employee->id,
        ]);

        $balance = LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => now()->year,
            'jatah_awal' => 12,
            'carry_over' => 2,
            'terpakai' => 4,
            'sisa' => 10,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 10,
            'terpakai_tahun_berjalan' => 4,
            'hangus' => 0,
        ]);

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/profil-saya/saldo-cuti');

        $response->assertOk();
        $response->assertJsonStructure([
            'balance' => [
                'jatah_awal',
                'carry_over',
                'terpakai',
                'sisa',
                'sisa_efektif',
                'tahun',
            ],
            'history',
        ]);

        $response->assertJsonPath('balance.sisa', 10);
        $response->assertJsonPath('balance.sisa_efektif', 10);
    }

    public function test_employee_can_view_leave_history_via_api(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create([
            'employee_id' => $employee->id,
        ]);

        $jenisCuti = RefJenisCuti::firstOrCreate(['nama' => 'Cuti Tahunan']);

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => now()->toDateString(),
            'tanggal_selesai' => now()->addDays(2)->toDateString(),
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Liburan',
            'status' => 'disetujui',
        ]);

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/profil-saya/saldo-cuti');

        $response->assertOk();
        $response->assertJsonCount(1, 'history');
        $response->assertJsonPath('history.0.id', $leaveRequest->id);
        $response->assertJsonPath('history.0.jenis_cuti', 'Cuti Tahunan');
        $response->assertJsonPath('history.0.jumlah_hari_kerja', 3);
    }

    public function test_employee_can_view_own_leave_balance_via_web(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create([
            'employee_id' => $employee->id,
        ]);

        $this->actingAs($user);
        $response = $this->get('/dashboard/cuti/saldo');

        $response->assertOk();
        $response->assertViewIs('admin.cuti.personal-saldo');
        $response->assertViewHas('balance');
        $response->assertViewHas('history');
    }

    /**
     * Halaman saldo pegawai tidak boleh membocorkan token status internal. Semua status terminal
     * harus tampil dengan label resmi yang sama seperti daftar, detail, dan laporan.
     */
    public function test_personal_saldo_web_labels_terminal_statuses_without_leaking_internal_tokens(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => now()->year,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
        ]);
        $jenisCuti = RefJenisCuti::where('code', 'tahunan')->firstOrFail();

        foreach ([
            LeaveRequest::STATUS_DUTY_POSTPONED => 'Ditangguhkan karena Tugas Dinas',
            LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER => 'Dikembalikan karena Rollover',
        ] as $status => $label) {
            LeaveRequest::create([
                'employee_id' => $employee->id,
                'jenis_cuti_id' => $jenisCuti->id,
                'tanggal_mulai' => now()->toDateString(),
                'tanggal_selesai' => now()->toDateString(),
                'jumlah_hari_kerja' => 1,
                // Alasan sengaja tidak memuat token status agar assertion badge tidak tertukar dengan teks alasan.
                'alasan' => "Riwayat pengajuan berlabel {$label}.",
                'status' => $status,
            ]);
        }

        $this->actingAs($user)
            ->get('/dashboard/cuti/saldo')
            ->assertOk()
            ->assertSee('Ditangguhkan karena Tugas Dinas')
            ->assertSee('Dikembalikan karena Rollover')
            ->assertDontSee(LeaveRequest::STATUS_DUTY_POSTPONED)
            ->assertDontSee(LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER);
    }

    public function test_personal_saldo_web_tidak_membuat_saldo_saat_dibuka(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create([
            'employee_id' => $employee->id,
        ]);

        $response = $this->actingAs($user)->get('/dashboard/cuti/saldo');

        $response->assertOk();
        $response->assertSee('Saldo cuti tahunan belum tersedia', false);
        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => now()->year,
        ]);
    }

    public function test_saldo_pribadi_rule_5_menampilkan_sisa_efektif_tanpa_mengubah_saldo_tercatat(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
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
        $largeRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::query()->where('code', 'besar')->firstOrFail()->id,
            'tanggal_mulai' => now()->startOfYear()->addMonths(2)->toDateString(),
            'tanggal_selesai' => now()->startOfYear()->addMonths(2)->addDays(30)->toDateString(),
            'jumlah_hari_kerja' => 20,
            'alasan' => 'Cuti Besar final.',
            'status' => 'disetujui',
        ]);
        LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $largeRequest->jenis_cuti_id,
            'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
            'leave_request_id' => $largeRequest->id,
            'usage_year' => now()->year,
            'effective_date' => $largeRequest->tanggal_mulai,
            'start_date' => $largeRequest->tanggal_mulai,
            'end_date' => $largeRequest->tanggal_selesai,
            'workdays' => $largeRequest->jumlah_hari_kerja,
            'administrative_note' => 'Fixture fakta Cuti Besar final untuk Rule 5.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/profil-saya/saldo-cuti')
            ->assertOk()
            ->assertJsonStructure(['balance' => ['jatah_awal', 'carry_over', 'terpakai', 'sisa', 'sisa_efektif', 'tahun'], 'history'])
            ->assertJsonPath('balance.sisa', 18)
            ->assertJsonPath('balance.sisa_efektif', 0);

        $this->actingAs($user)
            ->get('/dashboard/cuti/saldo')
            ->assertOk()
            ->assertSee('Saldo tercatat, tidak dapat digunakan pada tahun Cuti Besar', false)
            ->assertSee('Hak efektif tahun ini adalah 0 karena Cuti Besar telah disetujui.', false)
            ->assertSee('Saldo Tercatat', false)
            ->assertSee('18', false);

        $this->assertSame(18, $balance->fresh()->sisa);
    }

    public function test_saldo_pribadi_cuti_besar_non_final_tidak_menampilkan_peringatan_rule_5(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        LeaveBalance::create([
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
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::query()->where('code', 'besar')->firstOrFail()->id,
            'tanggal_mulai' => now()->startOfYear()->addMonths(2)->toDateString(),
            'tanggal_selesai' => now()->startOfYear()->addMonths(2)->addDays(30)->toDateString(),
            'jumlah_hari_kerja' => 20,
            'alasan' => 'Cuti Besar belum final.',
            'status' => 'menunggu_approval',
        ]);

        $this->actingAs($user)
            ->get('/dashboard/cuti/saldo')
            ->assertOk()
            ->assertDontSee('Saldo tercatat, tidak dapat digunakan pada tahun Cuti Besar', false)
            ->assertSee('Saldo Tercatat', false)
            ->assertSee('18', false);
    }
}
