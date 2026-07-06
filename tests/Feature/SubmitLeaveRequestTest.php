<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\SimpegNotification;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Menguji pengajuan cuti oleh pegawai (backend).
 * Memastikan jumlah hari kerja dihitung di server, aturan saldo/jenis pegawai ditegakkan,
 * pengajuan tersimpan dengan status awal yang benar, serta notifikasi dan audit tercatat.
 */
class SubmitLeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = 'cuti.store';

    protected function setUp(): void
    {
        parent::setUp();

        // Seed RBAC agar permission cuti.create tersedia bagi gerbang route dan FormRequest.
        $this->seed(RbacSeeder::class);

    }

    /**
     * Membuat pegawai pemohon lengkap dengan akun, jenis pegawai, dan atasan langsung aktif.
     *
     * @return array{user: User, employee: Employee, supervisor: Employee, pybmc: Employee}
     */
    private function makePemohon(string $jenisPegawai = 'PNS'): array
    {
        $jenis = RefJenisPegawai::firstOrCreate(['nama' => $jenisPegawai]);

        $employee = Employee::factory()->create(['jenis_pegawai_id' => $jenis->id]);
        $supervisor = Employee::factory()->create();
        $pybmc = Employee::factory()->create();

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => $jenisPegawai === 'PPPK' ? 'PPPK' : 'PNS',
            'tmt_pengangkatan' => '2024-01-01',
            'no_sk' => 'SK-TEST-001',
            'tanggal_sk' => '2024-01-01',
        ]);

        // Atasan langsung aktif: tanggal_berakhir null menandai penugasan masih berjalan.
        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'supervisor_id' => $supervisor->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);

        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->createApprovalChain($employee, $supervisor, $pybmc);

        return ['user' => $user, 'employee' => $employee, 'supervisor' => $supervisor, 'pybmc' => $pybmc];
    }

    private function createApprovalChain(Employee $employee, Employee $kepalaBagian, Employee $pybmc): LeaveApprovalChain
    {
        $chain = LeaveApprovalChain::create([
            'employee_id' => $employee->id,
            'name' => 'Chain cuti pegawai',
            'effective_from' => '2026-01-01',
            'change_reason' => 'Setup test chain.',
        ]);

        $chain->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $kepalaBagian->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $pybmc->id,
                'is_final' => true,
            ],
        ]);

        return $chain;
    }

    private function jenisCuti(string $nama, bool $khususPns = false): RefJenisCuti
    {
        return RefJenisCuti::create([
            'nama' => $nama,
            'code' => str($nama)->slug('_')->toString(),
            'mengurangi_saldo_tahunan' => $nama === 'Cuti Tahunan',
            'khusus_pns' => $khususPns,
        ]);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(RefJenisCuti $jenis, array $override = []): array
    {
        return array_merge([
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-10',
            'alasan' => 'Keperluan keluarga.',
        ], $override);
    }

    public function test_pegawai_berhasil_mengajukan_cuti(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Tahunan');
        LeaveBalance::create([
            'employee_id' => $aktor['employee']->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
        ]);

        $this->actingAs($aktor['user']);
        $response = $this->post(route(self::ROUTE), $this->payload($jenis));

        $response->assertRedirect(route('cuti'));
        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $aktor['employee']->id,
            'jenis_cuti_id' => $jenis->id,
            'status' => 'menunggu_approval',
            'jumlah_hari_kerja' => 5,
        ]);

        $leave = LeaveRequest::query()->firstOrFail();
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'approver_employee_id' => $aktor['supervisor']->id,
            'status' => 'active',
            'is_final' => false,
        ]);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 2,
            'step_type' => 'pybmc',
            'approver_employee_id' => $aktor['pybmc']->id,
            'status' => 'pending',
            'is_final' => true,
        ]);

        // Atasan langsung harus menerima notifikasi pengajuan baru.
        $this->assertTrue(
            SimpegNotification::query()->where('user_id', $aktor['supervisor']->id)->exists(),
        );

        // Pembuatan pengajuan harus terekam pada audit log.
        $this->assertTrue(
            AuditLog::query()->where('auditable_type', 'LeaveRequest')->where('event', 'CREATE')->exists(),
        );
    }

    public function test_jumlah_hari_kerja_dihitung_di_server(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');

        // Rentang Senin-Minggu (2026-07-06..2026-07-12) berisi akhir pekan; server harus menghitung 5 hari kerja.
        $this->actingAs($aktor['user']);
        $this->post(route(self::ROUTE), $this->payload($jenis, [
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-12',
            'jumlah_hari_kerja' => 999,
        ]));

        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $aktor['employee']->id,
            'jumlah_hari_kerja' => 5,
        ]);
    }

    public function test_menolak_saldo_cuti_tahunan_tidak_cukup(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Tahunan');
        LeaveBalance::create([
            'employee_id' => $aktor['employee']->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 10,
            'sisa' => 2,
        ]);

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE), $this->payload($jenis));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['tanggal_selesai']);
        // Cara A: pengajuan ditolak otomatis dan tidak tersimpan sama sekali.
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_tanpa_baris_saldo_dianggap_nol_untuk_cuti_tahunan(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Tahunan');

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE), $this->payload($jenis));

        $response->assertUnprocessable();
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_cuti_non_tahunan_tidak_mengecek_saldo(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');

        // Tanpa baris saldo pun cuti non-tahunan tetap boleh diajukan karena tidak memotong saldo tahunan.
        $this->actingAs($aktor['user']);
        $response = $this->post(route(self::ROUTE), $this->payload($jenis));

        $response->assertRedirect(route('cuti'));
        $this->assertDatabaseCount('leave_requests', 1);
    }

    public function test_pppk_tidak_boleh_jenis_cuti_khusus_pns(): void
    {
        $aktor = $this->makePemohon('PPPK');
        $jenis = $this->jenisCuti('Cuti Besar', khususPns: true);

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE), $this->payload($jenis));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['jenis_cuti_id']);
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_pns_boleh_jenis_cuti_khusus_pns(): void
    {
        $aktor = $this->makePemohon('PNS');
        $jenis = $this->jenisCuti('Cuti Besar', khususPns: true);

        $this->actingAs($aktor['user']);
        $response = $this->post(route(self::ROUTE), $this->payload($jenis));

        $response->assertRedirect(route('cuti'));
        $this->assertDatabaseCount('leave_requests', 1);
    }

    public function test_menolak_pengajuan_tanpa_atasan_langsung(): void
    {
        $jenis = $this->jenisCuti('Cuti Sakit');

        // Pegawai tanpa penugasan atasan langsung aktif tidak boleh mengajukan (tidak ada approver stage 1).
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->actingAs($user);
        $response = $this->postJson(route(self::ROUTE), $this->payload($jenis));

        $response->assertUnprocessable();
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_menolak_lampiran_melebihi_batas(): void
    {
        Storage::fake('public');
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');

        // 11 MB melebihi batas 10 MB (max:10240 KB).
        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE), $this->payload($jenis, [
            'lampiran' => UploadedFile::fake()->create('surat.pdf', 11000, 'application/pdf'),
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['lampiran']);
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_menyimpan_lampiran_saat_diunggah(): void
    {
        Storage::fake('public');
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');

        $this->actingAs($aktor['user']);
        $this->post(route(self::ROUTE), $this->payload($jenis, [
            'lampiran' => UploadedFile::fake()->create('surat.pdf', 200, 'application/pdf'),
        ]));

        $leave = LeaveRequest::query()->first();
        $this->assertNotNull($leave);
        $this->assertNotNull($leave->lampiran_path);
        Storage::disk('public')->assertExists($leave->lampiran_path);
    }

    public function test_admin_kepegawaian_tidak_bisa_mengajukan_cuti(): void
    {
        $jenis = $this->jenisCuti('Cuti Tahunan');
        $user = User::factory()->adminKepegawaian()->create();

        // Admin kepegawaian tidak memegang cuti.create sehingga ditolak gerbang permission.
        $this->actingAs($user);
        $response = $this->post(route(self::ROUTE), $this->payload($jenis));

        $response->assertForbidden();
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_tamu_diarahkan_ke_login(): void
    {
        $jenis = $this->jenisCuti('Cuti Tahunan');

        $response = $this->post(route(self::ROUTE), $this->payload($jenis));

        $response->assertRedirect('/login');
    }

    public function test_menolak_input_tidak_lengkap(): void
    {
        $aktor = $this->makePemohon();

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE), []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['jenis_cuti_id', 'tanggal_mulai', 'tanggal_selesai', 'alasan']);
    }

    public function test_gerbang_saldo_cocok_dengan_nama_jenis_cuti_terseed(): void
    {
        // Menguji terhadap baris referensi yang benar-benar di-seed agar pencocokan nama "Cuti Tahunan"
        // pada validasi saldo tidak diam-diam gagal bila penamaan seed berubah.
        $this->seed(ReferenceSeeder::class);
        $cutiTahunan = RefJenisCuti::query()->where('nama', 'Cuti Tahunan')->firstOrFail();

        $aktor = $this->makePemohon();
        LeaveBalance::create([
            'employee_id' => $aktor['employee']->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 11,
            'sisa' => 1,
        ]);

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE), $this->payload($cutiTahunan));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['tanggal_selesai']);
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_menolak_cuti_tahunan_lintas_tahun(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Tahunan');
        // Saldo dibuat sangat besar untuk memastikan penolakan murni karena rentang melintasi tahun, bukan saldo.
        LeaveBalance::create([
            'employee_id' => $aktor['employee']->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
        ]);

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE), $this->payload($jenis, [
            'tanggal_mulai' => '2026-12-30',
            'tanggal_selesai' => '2027-01-05',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['tanggal_selesai']);
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_menolak_pengajuan_saat_rantai_approval_belum_dikonfigurasi(): void
    {
        $aktor = $this->makePemohon();
        LeaveApprovalChain::query()->where('employee_id', $aktor['employee']->id)->delete();
        $jenis = $this->jenisCuti('Cuti Sakit');

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE), $this->payload($jenis));

        // Prasyarat operasional: tanpa chain aktif, pengajuan ditolak dengan pesan jelas dan tidak tersimpan.
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['jenis_cuti_id']);
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_menolak_cuti_tahunan_tanpa_tmt_pengangkatan(): void
    {
        $aktor = $this->makePemohon();
        $aktor['employee']->appointment()->delete();
        $jenis = $this->jenisCuti('Cuti Tahunan');
        LeaveBalance::create([
            'employee_id' => $aktor['employee']->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
        ]);

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE), $this->payload($jenis));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['tanggal_mulai']);
        $this->assertDatabaseCount('leave_requests', 0);
    }
}
