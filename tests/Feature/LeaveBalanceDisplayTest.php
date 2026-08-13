<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Mengunci tampilan konteks saldo pemohon pada halaman keputusan cuti. */
class LeaveBalanceDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_leave_balance_displayed_on_annual_leave_detail_page(): void
    {
        $currentYear = now()->year;

        // Setup employee dengan saldo cuti
        $jenisPegawai = RefJenisPegawai::where('nama', 'PNS')->firstOrFail();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $jenisPegawai->id,
        ]);

        // Buat appointment agar eligible untuk jatah tahunan
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => Carbon::create($currentYear - 3, 1, 1)->toDateString(),
            'no_sk' => 'SK-TEST-001',
            'tanggal_sk' => Carbon::create($currentYear - 3, 1, 1)->toDateString(),
        ]);

        // Buat saldo tahun berjalan
        LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => $currentYear,
            'jatah_awal' => 12,
            'n2' => 0,
            'n1' => 6, // Carry over dari tahun lalu
            'current' => 12,
            'terpakai' => 5,
            'sisa' => 13, // 12 + 6 - 5
        ]);

        // Buat saldo N-1
        LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => $currentYear - 1,
            'jatah_awal' => 12,
            'n2' => 0,
            'n1' => 0,
            'current' => 12,
            'terpakai' => 6,
            'sisa' => 6,
        ]);

        // Buat saldo N-2
        LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => $currentYear - 2,
            'jatah_awal' => 12,
            'n2' => 0,
            'n1' => 0,
            'current' => 12,
            'terpakai' => 8,
            'sisa' => 4,
        ]);

        // Buat leave request cuti tahunan
        $jenisCuti = RefJenisCuti::where('code', 'tahunan')->firstOrFail();
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => now()->addDays(10),
            'tanggal_selesai' => now()->addDays(12),
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga',
            'status' => 'menunggu_approval',
            'alamat_selama_cuti' => 'Jl. Test No. 123',
            'nomor_telepon' => '081234567890',
        ]);

        // User dengan permission untuk melihat detail
        $user = User::factory()->adminKepegawaian()->create();

        // Akses halaman detail
        $response = $this->actingAs($user)->get(route('cuti.show', $leaveRequest->id));

        $response->assertOk();

        $response->assertSee('Informasi Saldo & Riwayat Cuti Pemohon', false);
        $response->assertSee("Saldo Dapat Diajukan (N={$currentYear})");
        $response->assertSee('Sisa Hak N-1 ('.($currentYear - 1).')');
        $response->assertSee('Sisa Hak N-2 ('.($currentYear - 2).')');
        $response->assertSee('Riwayat Cuti Tahunan Disetujui');
    }

    public function test_verifier_context_is_available_for_non_annual_leave(): void
    {
        // Setup employee
        $employee = Employee::factory()->create();

        // Buat leave request cuti sakit (bukan tahunan)
        $jenisCuti = RefJenisCuti::where('code', 'sakit')->firstOrFail();
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => now()->addDays(1),
            'tanggal_selesai' => now()->addDays(2),
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Sakit flu',
            'status' => 'menunggu_approval',
            'alamat_selama_cuti' => 'Jl. Test No. 456',
            'nomor_telepon' => '081234567891',
        ]);

        $user = User::factory()->adminKepegawaian()->create();

        // Akses halaman detail
        $response = $this->actingAs($user)->get(route('cuti.show', $leaveRequest->id));

        $response->assertOk();

        $response->assertSee('Informasi Saldo & Riwayat Cuti Pemohon', false);
    }

    public function test_approver_can_see_leave_balance_on_pending_request(): void
    {
        $currentYear = now()->year;

        // Setup employee pemohon dengan saldo
        $jenisPegawai = RefJenisPegawai::where('nama', 'PNS')->firstOrFail();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $jenisPegawai->id,
        ]);

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => Carbon::create($currentYear - 2, 1, 1)->toDateString(),
            'no_sk' => 'SK-TEST-002',
            'tanggal_sk' => Carbon::create($currentYear - 2, 1, 1)->toDateString(),
        ]);

        LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => $currentYear,
            'jatah_awal' => 12,
            'n2' => 0,
            'n1' => 3,
            'current' => 12,
            'terpakai' => 2,
            'sisa' => 13,
        ]);

        // Buat leave request
        $jenisCuti = RefJenisCuti::where('code', 'tahunan')->firstOrFail();
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => now()->addDays(5),
            'tanggal_selesai' => now()->addDays(7),
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Liburan keluarga',
            'status' => 'menunggu_approval',
            'alamat_selama_cuti' => 'Jl. Test No. 789',
            'nomor_telepon' => '081234567892',
        ]);

        // Setup approval step dengan user sebagai approver
        // Role 'kepala_bagian' sudah memiliki permission 'cuti.approve_as_supervisor' dari RbacSeeder
        $approver = Employee::factory()->create();
        $user = User::factory()->kepalaBagian()->create([
            'employee_id' => $approver->id,
        ]);

        $leaveRequest->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
        ]);

        // Akses halaman detail sebagai approver
        $response = $this->actingAs($user)->get(route('cuti.show', $leaveRequest->id));

        $response->assertOk();

        $response->assertSee('Informasi Saldo & Riwayat Cuti Pemohon', false);
        $response->assertSee('Saldo hak cuti tahun berjalan (N), sisa hak N-1/N-2');
    }
}
