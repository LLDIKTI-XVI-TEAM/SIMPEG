<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\SimpegNotification;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceService;
use App\Services\EmployeeFileStorageService;
use App\Services\LeaveApprovalService;
use App\Services\NotificationService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private function makePemohon(string $jenisPegawai = 'PNS', string $role = 'pegawai'): array
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

        $user = User::factory()->state(['role' => $role])->create(['employee_id' => $employee->id]);

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
            'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 1, Manado',
            'nomor_telepon' => '+62 (431) 123-456',
        ], $override);
    }

    public static function rolePemohonProvider(): array
    {
        return [
            'admin kepegawaian' => ['admin_kepegawaian'],
            'pimpinan' => ['pimpinan'],
            'kepala bagian' => ['kepala_bagian'],
            'pegawai' => ['pegawai'],
        ];
    }

    #[DataProvider('rolePemohonProvider')]
    public function test_role_self_service_mengajukan_cuti_hanya_untuk_pegawai_tertambat(string $role): void
    {
        $aktor = $this->makePemohon(role: $role);
        $pegawaiLain = Employee::factory()->create();
        $jenis = $this->jenisCuti('Cuti Sakit '.$role);

        $response = $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
            'employee_id' => $pegawaiLain->id,
        ]));

        $response->assertRedirect(route('cuti'));
        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $aktor['employee']->id,
            'jenis_cuti_id' => $jenis->id,
        ]);
        $this->assertDatabaseMissing('leave_requests', ['employee_id' => $pegawaiLain->id]);
    }

    #[DataProvider('rolePemohonProvider')]
    public function test_role_self_service_tanpa_link_pegawai_gagal_tertutup(string $role): void
    {
        $jenis = $this->jenisCuti('Cuti Tanpa Link '.$role);
        $user = User::factory()->state(['role' => $role])->create(['employee_id' => null]);

        $this->actingAs($user)->get(route('cuti.create'))->assertForbidden();
        $this->actingAs($user)->postJson(route(self::ROUTE), $this->payload($jenis))->assertUnprocessable();
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_super_admin_tidak_bisa_membuka_form_atau_mengajukan_cuti(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->superAdmin()->create(['employee_id' => $employee->id]);
        $jenis = $this->jenisCuti('Cuti Super Admin');

        $this->actingAs($user)->get(route('cuti.create'))->assertForbidden();
        $this->actingAs($user)->post(route(self::ROUTE), $this->payload($jenis))->assertForbidden();
        $this->assertDatabaseCount('leave_requests', 0);
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

    public function test_future_assignment_preserves_existing_snapshot_and_post_effective_submission_uses_new_kepala_bagian(): void
    {
        Carbon::setTestNow('2026-07-23 08:00:00');

        try {
            $aktor = $this->makePemohon();
            $kepalaBagianBaru = Employee::factory()->create();
            $admin = User::factory()->superAdmin()->create();
            $jenis = $this->jenisCuti('Cuti Sakit Efektif');

            $assignmentResponse = $this->actingAs($admin)
                ->withSession(['_token' => 'test-token'])
                ->postJson("/api/v1/pegawai/{$aktor['employee']->id}/assign-atasan", [
                    'kepala_bagian_id' => $kepalaBagianBaru->id,
                    'effective_date' => '2026-08-01',
                ], ['X-CSRF-TOKEN' => 'test-token']);
            $assignmentResponse->assertOk();

            $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
                'tanggal_mulai' => '2026-07-27',
                'tanggal_selesai' => '2026-07-28',
                'alasan' => 'Pengajuan sebelum tanggal efektif.',
            ]))->assertRedirect(route('cuti'));

            $pengajuanSebelum = LeaveRequest::query()
                ->where('alasan', 'Pengajuan sebelum tanggal efektif.')
                ->firstOrFail();
            $this->assertDatabaseHas('leave_request_steps', [
                'leave_request_id' => $pengajuanSebelum->id,
                'step_order' => 1,
                'approver_employee_id' => $aktor['supervisor']->id,
            ]);

            Carbon::setTestNow('2026-08-01 08:00:00');
            $this->flushSession();
            $this->actingAs($aktor['user']);
            $this->post(route(self::ROUTE), $this->payload($jenis, [
                'tanggal_mulai' => '2026-08-03',
                'tanggal_selesai' => '2026-08-04',
                'alasan' => 'Pengajuan setelah tanggal efektif.',
            ]))->assertRedirect(route('cuti'));

            $pengajuanSesudah = LeaveRequest::query()
                ->where('alasan', 'Pengajuan setelah tanggal efektif.')
                ->firstOrFail();
            $this->assertDatabaseHas('leave_request_steps', [
                'leave_request_id' => $pengajuanSesudah->id,
                'step_order' => 1,
                'approver_employee_id' => $kepalaBagianBaru->id,
            ]);
            $this->assertDatabaseHas('leave_request_steps', [
                'leave_request_id' => $pengajuanSesudah->id,
                'step_order' => 2,
                'approver_employee_id' => $aktor['pybmc']->id,
            ]);
            $this->assertDatabaseHas('leave_request_steps', [
                'leave_request_id' => $pengajuanSebelum->id,
                'step_order' => 1,
                'approver_employee_id' => $aktor['supervisor']->id,
            ]);
            $this->assertDatabaseMissing('leave_request_steps', [
                'leave_request_id' => $pengajuanSebelum->id,
                'approver_employee_id' => $kepalaBagianBaru->id,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_submission_uses_effective_supervisor_when_pointer_is_null_and_stored_chain_is_stale(): void
    {
        Carbon::setTestNow('2026-07-23 08:00:00');

        try {
            $aktor = $this->makePemohon();
            $kepalaBagianTersimpan = $aktor['supervisor'];
            $kepalaBagianEfektif = Employee::factory()->create();
            $aktor['employee']->supervisorAssignments()->delete();
            $aktor['employee']->update(['kepala_bagian_id' => null]);
            SupervisorAssignment::create([
                'employee_id' => $aktor['employee']->id,
                'kepala_bagian_id' => $kepalaBagianEfektif->id,
                'tanggal_mulai' => '2026-08-01',
                'tanggal_berakhir' => null,
            ]);
            $jenis = $this->jenisCuti('Cuti Sakit Pointer Kosong');

            Carbon::setTestNow('2026-08-01 08:00:00');
            $this->flushSession();
            $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
                'tanggal_mulai' => '2026-08-03',
                'tanggal_selesai' => '2026-08-04',
                'alasan' => 'Pengajuan dengan pointer Kepala Bagian kosong.',
            ]))->assertRedirect(route('cuti'));

            $leave = LeaveRequest::query()->where('alasan', 'Pengajuan dengan pointer Kepala Bagian kosong.')->firstOrFail();
            $this->assertDatabaseHas('leave_request_steps', [
                'leave_request_id' => $leave->id,
                'step_order' => 1,
                'approver_employee_id' => $kepalaBagianEfektif->id,
            ]);
            $this->assertSame(
                $kepalaBagianTersimpan->id,
                LeaveApprovalChain::query()
                    ->where('employee_id', $aktor['employee']->id)
                    ->firstOrFail()
                    ->steps()
                    ->where('step_type', 'kepala_bagian')
                    ->firstOrFail()
                    ->approver_employee_id,
            );
            $this->assertNull($aktor['employee']->fresh()->kepala_bagian_id);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_submission_fails_closed_when_no_effective_kepala_bagian_and_stored_chain_is_stale(): void
    {
        Carbon::setTestNow('2026-08-01 08:00:00');

        try {
            $aktor = $this->makePemohon();
            // Pegawai lain memvalidasi scope per-pegawai: pengosongan penugasan pemohon tidak boleh menyentuh mereka.
            $lain = $this->makePemohon();

            // Kosongkan penugasan efektif pemohon; chain aktif tetap menyimpan approver Kepala Bagian lama.
            $aktor['employee']->supervisorAssignments()->delete();
            $aktor['employee']->update(['kepala_bagian_id' => null]);

            $storedKepalaBagianId = LeaveApprovalChain::query()
                ->where('employee_id', $aktor['employee']->id)
                ->firstOrFail()
                ->steps()
                ->where('step_type', 'kepala_bagian')
                ->firstOrFail()
                ->approver_employee_id;
            // Prasyarat: approver tersimpan adalah pihak lain (bukan pemohon), sehingga jalur konflik kepentingan tidak berlaku.
            $this->assertSame($aktor['supervisor']->id, $storedKepalaBagianId);

            $jenis = $this->jenisCuti('Cuti Sakit Tanpa Kabag Efektif');

            $this->actingAs($aktor['user']);
            $response = $this->postJson(route(self::ROUTE), $this->payload($jenis, [
                'alasan' => 'Uji fail-closed tanpa Kepala Bagian efektif.',
            ]));

            // Fail-closed: tanpa Kepala Bagian efektif pada hari server, pengajuan baru wajib ditolak sebagai validation error.
            $response->assertUnprocessable();
            $response->assertJsonValidationErrors(['jenis_cuti_id']);
            $this->assertDatabaseMissing('leave_requests', [
                'employee_id' => $aktor['employee']->id,
                'alasan' => 'Uji fail-closed tanpa Kepala Bagian efektif.',
            ]);
            $this->assertDatabaseCount('leave_request_steps', 0);

            // Chain lama tetap immutable: approver Kepala Bagian tersimpan tidak boleh berubah karena resolusi gagal.
            $this->assertSame(
                $storedKepalaBagianId,
                LeaveApprovalChain::query()
                    ->where('employee_id', $aktor['employee']->id)
                    ->firstOrFail()
                    ->steps()
                    ->where('step_type', 'kepala_bagian')
                    ->firstOrFail()
                    ->approver_employee_id,
            );

            // Scope per-pegawai: penugasan efektif pegawai lain tidak terpengaruh oleh pengosongan pemohon.
            $this->assertNotNull($lain['employee']->fresh()->currentSupervisor());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_submission_fails_closed_when_no_effective_supervisor_even_if_chain_kepala_bagian_is_applicant(): void
    {
        $aktor = $this->makePemohon();
        // Kosongkan penugasan efektif; chain justru menetapkan pemohon sendiri sebagai Kepala Bagian.
        $aktor['employee']->supervisorAssignments()->delete();
        $aktor['employee']->update(['kepala_bagian_id' => null]);
        $chain = LeaveApprovalChain::query()->where('employee_id', $aktor['employee']->id)->firstOrFail();
        $chain->steps()->delete();
        $chain->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $aktor['employee']->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $aktor['pybmc']->id,
                'is_final' => true,
            ],
        ]);
        $jenis = $this->jenisCuti('Cuti Sakit Pemohon Sebagai Kabag');

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE), $this->payload($jenis, [
            'alasan' => 'Uji fail-closed pemohon sebagai Kepala Bagian.',
        ]));

        // Invariant tanpa syarat: tanpa Kepala Bagian efektif, pengajuan ditolak sebelum penyaringan konflik kepentingan
        // sehingga approver PYBMC yang tersisa tidak boleh menjadi celah lolosnya pengajuan.
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['jenis_cuti_id']);
        $this->assertDatabaseMissing('leave_requests', [
            'employee_id' => $aktor['employee']->id,
            'alasan' => 'Uji fail-closed pemohon sebagai Kepala Bagian.',
        ]);
        $this->assertDatabaseCount('leave_request_steps', 0);
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

    public function test_pengajuan_cuti_tahunan_menggunakan_bucket_saldo_ledger_saat_submit(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Tahunan');
        LeaveBalance::create([
            'employee_id' => $aktor['employee']->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 0,
            'sisa_n2' => 2,
            'sisa_n1' => 3,
            'sisa_tahun_berjalan' => 7,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);

        $this->actingAs($aktor['user']);
        $response = $this->post(route(self::ROUTE), $this->payload($jenis));

        $response->assertRedirect(route('cuti'));
        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $aktor['employee']->id,
            'jenis_cuti_id' => $jenis->id,
            'jumlah_hari_kerja' => 5,
        ]);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
    }

    public function test_pegawai_eligible_mendapat_jatah_tahunan_lazily_saat_submit_pertama(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Tahunan');

        $this->actingAs($aktor['user']);
        $response = $this->post(route(self::ROUTE), $this->payload($jenis));

        $response->assertRedirect(route('cuti'));
        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $aktor['employee']->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'sisa_tahun_berjalan' => 12,
            'sisa' => 12,
        ]);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $aktor['employee']->id,
            'tahun' => 2026,
            'event_type' => 'annual_entitlement_granted',
            'amount' => 12,
            'dedup_key' => "{$aktor['employee']->id}:2026:annual_entitlement_granted",
        ]);
        $this->assertDatabaseCount('leave_requests', 1);
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

    public function test_form_pppk_tidak_menampilkan_jenis_cuti_khusus_pns(): void
    {
        $aktor = $this->makePemohon('PPPK');
        $umum = $this->jenisCuti('Cuti Sakit');
        $khususPns = $this->jenisCuti('Cuti Besar', khususPns: true);

        $response = $this->actingAs($aktor['user'])->get(route('cuti.create'));

        $response->assertOk();
        $response->assertViewHas('jenisCuti', function ($jenisCuti) use ($umum, $khususPns): bool {
            return $jenisCuti->contains('id', $umum->id)
                && ! $jenisCuti->contains('id', $khususPns->id);
        });
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

    public function test_menolak_pengajuan_tanpa_kepala_bagian(): void
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

    public function test_cuti_form_uses_lampiran_field_name_not_file_lampiran(): void
    {
        $aktor = $this->makePemohon();

        $this->actingAs($aktor['user']);
        $response = $this->get(route('cuti.create'));

        $response->assertOk();
        $response->assertSee('name="lampiran"', escape: false);
        $response->assertDontSee('name="file_lampiran"', escape: false);
    }

    public function test_pegawai_tanpa_linkage_pegawai_tidak_bisa_membuka_form_pengajuan_cuti(): void
    {
        $user = User::factory()->pegawai()->create(['employee_id' => null]);

        $this->actingAs($user);
        $response = $this->get(route('cuti.create'));

        $response->assertForbidden();
    }

    public function test_cuti_create_form_exposes_ledger_backed_available_balance(): void
    {
        $aktor = $this->makePemohon();
        $this->jenisCuti('Cuti Tahunan');

        // Saldo awal ditulis lewat service ledger agar angka tersedia yang dilihat pemohon
        // berasal dari sumber yang sama dengan validasi saldo saat submit (bukan kolom summary lama).
        app(LeaveBalanceService::class)->setOpeningBalance(
            $aktor['employee'],
            (int) now()->year,
            ['n2' => 0, 'n1' => 0, 'current' => 7],
            'Setup test saldo',
            $aktor['user'],
        );

        $this->actingAs($aktor['user']);
        $response = $this->get(route('cuti.create'));

        $response->assertOk();
        $response->assertViewHas('saldoCuti', function (array $saldoCuti): bool {
            return $saldoCuti['saldo_aktual'] === 7
                && $saldoCuti['saldo_dapat_diajukan'] === 7
                && $saldoCuti['dialokasikan_aktif'] === 0;
        });
        $response->assertSee('Saldo Tersedia Aktual', escape: false);
        $response->assertSee('Masih Dapat Diajukan', escape: false);
        $response->assertSee('data-mengurangi-saldo-tahunan="true"', escape: false);
    }

    public function test_menolak_lampiran_melebihi_batas(): void
    {

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

        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');

        $this->actingAs($aktor['user']);
        $this->post(route(self::ROUTE), $this->payload($jenis, [
            'lampiran' => UploadedFile::fake()->create('surat.pdf', 200, 'application/pdf'),
        ]));

        $leave = LeaveRequest::query()->first();
        $this->assertNotNull($leave);
        $this->assertNotNull($leave->lampiran_path);
        $this->assertTrue(Storage::disk('public')->exists($leave->lampiran_path));
    }

    public function test_submit_gagal_setelah_upload_membersihkan_lampiran_baru(): void
    {

        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit Gagal Notifikasi');
        $this->mock(NotificationService::class, function (MockInterface $mock): void {
            /** @var Expectation $expectation */
            $expectation = $mock->shouldReceive('createForEmployee');
            $expectation->once()
                ->andThrow(new \RuntimeException('Simulasi kegagalan notifikasi.'));
        });

        $this->actingAs($aktor['user']);
        $this->withoutExceptionHandling();

        try {
            $this->post(route(self::ROUTE), $this->payload($jenis, [
                'lampiran' => UploadedFile::fake()->create('orphan.pdf', 100, 'application/pdf'),
            ]));
            $this->fail('Submit harus meneruskan kegagalan transaksi.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi kegagalan notifikasi.', $exception->getMessage());
        }

        $this->assertDatabaseCount('leave_requests', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('cuti'));
    }

    public function test_kegagalan_hapus_file_publik_dicatat_tanpa_melempar(): void
    {
        Log::shouldReceive('warning')->once()
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'Gagal menghapus file publik')
                && ($context['path'] ?? null) === 'cuti/lama.pdf');
        $disk = \Mockery::mock(Filesystem::class);
        /** @var Expectation $deleteExpectation */
        $deleteExpectation = $disk->shouldReceive('delete');
        $deleteExpectation->with('cuti/lama.pdf')->andReturnFalse();
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        app(EmployeeFileStorageService::class)->deletePublicFile('cuti/lama.pdf');
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

    public static function jenisNonTahunanProvider(): array
    {
        return [
            'cuti sakit' => ['Cuti Sakit', false],
            'cuti melahirkan' => ['Cuti Melahirkan', false],
            'cuti alasan penting' => ['Cuti Karena Alasan Penting', false],
            'cuti besar' => ['Cuti Besar', true],
            'cltn' => ['Cuti Luar Tanggungan Negara (CLTN)', true],
        ];
    }

    #[DataProvider('jenisNonTahunanProvider')]
    public function test_menolak_semua_jenis_cuti_lintas_tahun(string $namaJenis, bool $khususPns): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti($namaJenis, $khususPns);

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE), $this->payload($jenis, [
            'tanggal_mulai' => '2026-12-30',
            'tanggal_selesai' => '2027-01-05',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['tanggal_selesai']);
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_menolak_cuti_tahunan_lintas_tahun_meski_tmt_pengangkatan_kosong(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Tahunan');
        // TMT dihapus untuk memastikan cek tahun kalender berjalan lebih dulu daripada cek TMT.
        Appointment::query()->where('employee_id', $aktor['employee']->id)->delete();

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE), $this->payload($jenis, [
            'tanggal_mulai' => '2026-12-30',
            'tanggal_selesai' => '2027-01-05',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonPath(
            'errors.tanggal_selesai.0',
            'Pengajuan cuti tidak boleh melewati tahun kalender. Pisahkan menjadi dua pengajuan terpisah untuk tiap tahun.',
        );
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_menolak_resubmit_lintas_tahun_untuk_jenis_non_tahunan(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');

        $this->actingAs($aktor['user']);
        $this->post(route(self::ROUTE), $this->payload($jenis));

        $leave = LeaveRequest::firstOrFail();
        app(LeaveApprovalService::class)->requestChanges($leave, $aktor['supervisor'], 'Tanggal harus diperbaiki.');

        $response = $this->patchJson(route('cuti.resubmit', $leave), [
            'tanggal_mulai' => '2026-12-30',
            'tanggal_selesai' => '2027-01-05',
            'alasan' => 'Revisi tanggal sesuai arahan approver.',
            'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 2, Manado',
            'nomor_telepon' => '+62 (431) 123-457',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['tanggal_selesai']);

        $leave->refresh();
        $this->assertSame('perlu_perubahan', $leave->status);
        $this->assertSame('2026-07-06', $leave->tanggal_mulai->toDateString());
        $this->assertSame('2026-07-10', $leave->tanggal_selesai->toDateString());
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

    public function test_kepala_lembaga_sees_information_state_instead_of_submit_form(): void
    {
        // Cuti Kepala Lembaga diproses melalui kementerian, bukan lewat SIMPEG.
        // Halaman create harus menampilkan panel informasi, bukan form pengajuan.
        $aktor = $this->makePemohon();
        $aktor['employee']->forceFill(['is_kepala_lembaga' => true])->save();

        $this->actingAs($aktor['user']);
        $response = $this->get(route('cuti.create'));

        $response->assertOk();
        $response->assertViewHas('isKepalaLembaga', true);
        // Panel informasi harus menyatakan bahwa pengajuan diproses melalui kementerian.
        $response->assertSee('diproses melalui kementerian', escape: false);
        // Membuktikan "instead of form": aksi form submit ke cuti.store tidak boleh dirender.
        $response->assertDontSee('action="'.route('cuti.store').'"', escape: false);
    }

    public function test_kepala_lembaga_post_submit_is_rejected(): void
    {
        // Guard fail-closed di server: meski UI disembunyikan, POST langsung tetap harus ditolak
        // sebelum perhitungan hari kerja atau penyimpanan apa pun terjadi.
        $aktor = $this->makePemohon();
        $aktor['employee']->forceFill(['is_kepala_lembaga' => true])->save();
        $jenis = $this->jenisCuti('Cuti Tahunan');

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE), $this->payload($jenis, [
            'alasan' => 'Uji tolak Kepala Lembaga',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['jenis_cuti_id']);
        // Tidak boleh ada baris pengajuan tersimpan untuk pegawai/alasan ini.
        $this->assertDatabaseMissing('leave_requests', [
            'employee_id' => $aktor['employee']->id,
            'alasan' => 'Uji tolak Kepala Lembaga',
        ]);
    }

    #[DataProvider('rolePemohonProvider')]
    public function test_semua_role_self_service_yang_berstatus_kepala_lembaga_ditolak(string $role): void
    {
        $aktor = $this->makePemohon(role: $role);
        $aktor['employee']->forceFill(['is_kepala_lembaga' => true])->save();
        $jenis = $this->jenisCuti('Cuti Kepala Lembaga '.$role);

        $this->actingAs($aktor['user'])->get(route('cuti.create'))
            ->assertOk()
            ->assertViewHas('isKepalaLembaga', true)
            ->assertDontSee('action="'.route('cuti.store').'"', false);
        $this->actingAs($aktor['user'])->postJson(route(self::ROUTE), $this->payload($jenis))
            ->assertUnprocessable();
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_snapshot_menghapus_pemohon_dan_menormalkan_urutan_approver_tersisa(): void
    {
        $aktor = $this->makePemohon();
        // Kepala Bagian efektif kebetulan pemohon sendiri; resolver menuliskannya ke step lalu langkah pemohon disaring.
        $aktor['employee']->supervisorAssignments()->delete();
        SupervisorAssignment::create([
            'employee_id' => $aktor['employee']->id,
            'kepala_bagian_id' => $aktor['employee']->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);
        $chain = LeaveApprovalChain::query()->where('employee_id', $aktor['employee']->id)->firstOrFail();
        $chain->steps()->delete();
        $chain->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Pemohon sebagai Kepala Bagian',
                'approver_employee_id' => $aktor['employee']->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'verifikator',
                'role_label' => 'Verifikator',
                'approver_employee_id' => $aktor['supervisor']->id,
                'is_final' => false,
            ],
            [
                'step_order' => 3,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $aktor['pybmc']->id,
                'is_final' => true,
            ],
        ]);
        $jenis = $this->jenisCuti('Cuti Konflik Kepentingan');

        $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis))->assertRedirect(route('cuti'));

        $leave = LeaveRequest::query()->firstOrFail();
        $this->assertSame([1, 2], $leave->steps()->orderBy('step_order')->pluck('step_order')->all());
        $this->assertSame(
            [$aktor['supervisor']->id, $aktor['pybmc']->id],
            $leave->steps()->orderBy('step_order')->pluck('approver_employee_id')->all(),
        );
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'approver_employee_id' => $aktor['supervisor']->id,
            'status' => 'active',
        ]);
    }

    public function test_submission_tanpa_approver_non_pemohon_tidak_menyimpan_efek_samping(): void
    {

        $aktor = $this->makePemohon();
        $aktor['employee']->supervisorAssignments()->delete();
        $chain = LeaveApprovalChain::query()->where('employee_id', $aktor['employee']->id)->firstOrFail();
        $chain->steps()->delete();
        $chain->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $aktor['employee']->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $aktor['employee']->id,
                'is_final' => true,
            ],
        ]);
        $jenis = $this->jenisCuti('Cuti Tanpa Approver Valid');

        $response = $this->actingAs($aktor['user'])->postJson(route(self::ROUTE), $this->payload($jenis, [
            'lampiran' => UploadedFile::fake()->create('surat.pdf', 200, 'application/pdf'),
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors(['jenis_cuti_id']);
        $this->assertDatabaseCount('leave_requests', 0);
        $this->assertDatabaseCount('leave_request_steps', 0);
        $this->assertSame(0, SimpegNotification::count());
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('cuti'));
    }

    public function test_pemohon_bisa_mengirim_ulang_pengajuan_perlu_perubahan_dengan_snapshot_yang_sama(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');

        $this->actingAs($aktor['user']);
        $this->post(route(self::ROUTE), $this->payload($jenis));

        $leave = LeaveRequest::with('steps')->firstOrFail();
        app(LeaveApprovalService::class)->requestChanges($leave, $aktor['supervisor'], 'Tanggal harus diperbaiki.');
        $stepIdsBefore = $leave->steps()->orderBy('step_order')->pluck('id')->all();

        $response = $this->patch(route('cuti.resubmit', $leave), [
            'tanggal_mulai' => '2026-07-13',
            'tanggal_selesai' => '2026-07-15',
            'alasan' => 'Revisi tanggal sesuai arahan approver.',
            'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 2, Manado',
            'nomor_telepon' => '+62 (431) 123-457',
        ]);

        $response->assertRedirect(route('cuti.show', $leave));
        $leave->refresh();

        $this->assertSame('menunggu_approval', $leave->status);
        $this->assertSame('2026-07-13', $leave->tanggal_mulai->toDateString());
        $this->assertSame('2026-07-15', $leave->tanggal_selesai->toDateString());
        $this->assertSame(3, $leave->jumlah_hari_kerja);
        $this->assertSame($stepIdsBefore, $leave->steps()->orderBy('step_order')->pluck('id')->all());
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'status' => 'active',
        ]);
    }

    public function test_resubmit_rollover_hanya_menerima_tahun_target_memindahkan_reservasi_dan_memberi_tahu_approver_snapshot(): void
    {
        $aktor = $this->makePemohon();
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan Rollover',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        LeaveBalance::create([
            'employee_id' => $aktor['employee']->id,
            'tahun' => 2027,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $aktor['employee']->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-12-28',
            'tanggal_selesai' => '2026-12-30',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Pengajuan yang dikembalikan saat rollover.',
            'alamat_selama_cuti' => 'Jl. Sumber Tahun 2026',
            'nomor_telepon' => '+62 431 123456',
            'status' => 'dikembalikan_karena_rollover',
            'rollover_source_year' => 2026,
            'rollover_target_year' => 2027,
        ]);
        $leave->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $aktor['supervisor']->id,
                'status' => 'active',
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $aktor['pybmc']->id,
                'status' => 'pending',
                'is_final' => true,
            ],
        ]);
        $stepIdsBefore = $leave->steps()->orderBy('step_order')->pluck('id')->all();

        $this->actingAs($aktor['user'])
            ->patchJson(route('cuti.resubmit', $leave), [
                'tanggal_mulai' => '2026-12-28',
                'tanggal_selesai' => '2026-12-30',
                'alasan' => 'Tidak boleh kembali ke tahun sumber.',
                'alamat_selama_cuti' => 'Jl. Ditolak',
                'nomor_telepon' => '+62 431 123457',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tanggal_mulai']);

        $this->assertSame('dikembalikan_karena_rollover', $leave->fresh()->status);
        $this->assertSame(0, LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leave->id)
            ->count());

        $response = $this->actingAs($aktor['user'])
            ->patch(route('cuti.resubmit', $leave), [
                'tanggal_mulai' => '2027-01-11',
                'tanggal_selesai' => '2027-01-13',
                'alasan' => 'Tanggal dipindahkan ke tahun target.',
                'alamat_selama_cuti' => 'Jl. Tahun Target',
                'nomor_telepon' => '+62 431 123458',
            ]);

        $response->assertRedirect(route('cuti.show', $leave));
        $leave->refresh();
        $this->assertSame('menunggu_approval', $leave->status);
        $this->assertSame('2027-01-11', $leave->tanggal_mulai->toDateString());
        $this->assertNull($leave->rollover_source_year);
        $this->assertNull($leave->rollover_target_year);
        $this->assertSame($stepIdsBefore, $leave->steps()->orderBy('step_order')->pluck('id')->all());
        $this->assertSame('active', $leave->steps()->orderBy('step_order')->value('status'));
        $this->assertSame(3, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leave->id)
            ->where('tahun', 2027)
            ->sum('amount'));
        $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leave->id)
            ->where('tahun', 2026)
            ->sum('amount'));
        $this->assertDatabaseHas('notifications', [
            'user_id' => $aktor['supervisor']->id,
            'type' => 'cuti.pengajuan_baru',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $aktor['pybmc']->id,
            'type' => 'cuti.pengajuan_baru',
        ]);
    }

    public function test_resubmit_rollover_rolls_back_request_and_reservation_when_approver_notification_fails(): void
    {
        $aktor = $this->makePemohon();
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan Rollover Notifikasi Gagal',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        LeaveBalance::create([
            'employee_id' => $aktor['employee']->id,
            'tahun' => 2027,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $aktor['employee']->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-12-28',
            'tanggal_selesai' => '2026-12-30',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Pengajuan rollover yang notifikasinya gagal.',
            'alamat_selama_cuti' => 'Jl. Sumber Tahun 2026',
            'nomor_telepon' => '+62 431 123456',
            'status' => LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER,
            'rollover_source_year' => 2026,
            'rollover_target_year' => 2027,
        ]);
        $leave->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $aktor['supervisor']->id,
            'status' => 'active',
            'is_final' => true,
        ]);
        $this->mock(NotificationService::class, function (MockInterface $mock): void {
            /** @var Expectation $expectation */
            $expectation = $mock->shouldReceive('createForEmployee');
            $expectation->once()->andThrow(new \RuntimeException('Simulasi kegagalan notifikasi resubmit.'));
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($aktor['user'])->patch(route('cuti.resubmit', $leave), [
                'tanggal_mulai' => '2027-01-11',
                'tanggal_selesai' => '2027-01-13',
                'alasan' => 'Resubmit yang harus rollback.',
                'alamat_selama_cuti' => 'Jl. Tahun Target',
                'nomor_telepon' => '+62 431 123458',
            ]);
            $this->fail('Kegagalan notifikasi approver harus membatalkan resubmit rollover.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi kegagalan notifikasi resubmit.', $exception->getMessage());
        }

        $leave->refresh();
        $this->assertSame(LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER, $leave->status);
        $this->assertSame('2026-12-28', $leave->tanggal_mulai->toDateString());
        $this->assertSame(2026, $leave->rollover_source_year);
        $this->assertSame(2027, $leave->rollover_target_year);
        $this->assertSame(0, LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leave->id)
            ->sum('amount'));
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $aktor['supervisor']->id,
            'type' => 'cuti.pengajuan_baru',
        ]);
    }

    public function test_resubmit_gagal_setelah_upload_mempertahankan_lampiran_lama_dan_membersihkan_yang_baru(): void
    {

        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit Resubmit Gagal');
        $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
            'lampiran' => UploadedFile::fake()->create('lama.pdf', 100, 'application/pdf'),
        ]));
        $leave = LeaveRequest::firstOrFail();
        app(LeaveApprovalService::class)->requestChanges($leave, $aktor['supervisor'], 'Perlu revisi.');
        $oldPath = $leave->fresh()->lampiran_path;
        $oldValues = $leave->fresh()->only(['tanggal_mulai', 'tanggal_selesai', 'alasan', 'status', 'lampiran_path']);
        LeaveRequest::saving(function (LeaveRequest $saving): void {
            if ($saving->isDirty('lampiran_path')) {
                throw new \RuntimeException('Simulasi kegagalan penyimpanan resubmit.');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->patch(route('cuti.resubmit', $leave), [
                'tanggal_mulai' => '2026-07-13',
                'tanggal_selesai' => '2026-07-15',
                'alasan' => 'Revisi gagal disimpan.',
                'alamat_selama_cuti' => 'Jl. Revisi',
                'nomor_telepon' => '+62 123',
                'lampiran' => UploadedFile::fake()->create('baru.pdf', 100, 'application/pdf'),
            ]);
            $this->fail('Resubmit harus meneruskan kegagalan transaksi.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi kegagalan penyimpanan resubmit.', $exception->getMessage());
        }

        $this->assertEquals($oldValues, $leave->fresh()->only(array_keys($oldValues)));
        $this->assertTrue(Storage::disk('public')->exists($oldPath));
        $this->assertSame([$oldPath], Storage::disk('public')->allFiles('cuti'));
    }

    public function test_resubmit_berhasil_mengganti_lampiran_lalu_menghapus_file_lama(): void
    {

        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit Ganti Lampiran');
        $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
            'lampiran' => UploadedFile::fake()->create('lama.pdf', 100, 'application/pdf'),
        ]));
        $leave = LeaveRequest::firstOrFail();
        app(LeaveApprovalService::class)->requestChanges($leave, $aktor['supervisor'], 'Ganti lampiran.');
        $oldPath = $leave->fresh()->lampiran_path;

        $response = $this->patch(route('cuti.resubmit', $leave), [
            'tanggal_mulai' => '2026-07-13',
            'tanggal_selesai' => '2026-07-15',
            'alasan' => 'Lampiran sudah diganti.',
            'alamat_selama_cuti' => 'Jl. Revisi',
            'nomor_telepon' => '+62 123',
            'lampiran' => UploadedFile::fake()->create('baru.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect(route('cuti.show', $leave));
        $newPath = $leave->fresh()->lampiran_path;
        $this->assertNotSame($oldPath, $newPath);
        $this->assertTrue(Storage::disk('public')->exists($newPath));
        $this->assertFalse(Storage::disk('public')->exists($oldPath));
    }

    public function test_resubmit_ditolak_jika_pemohon_sekarang_kepala_lembaga_tanpa_mutasi(): void
    {

        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit Resubmit Kepala Lembaga');
        $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis));
        $leave = LeaveRequest::with('steps')->firstOrFail();
        app(LeaveApprovalService::class)->requestChanges($leave, $aktor['supervisor'], 'Perlu revisi.');
        $aktor['employee']->forceFill(['is_kepala_lembaga' => true])->save();
        $before = $leave->fresh();
        $beforeValues = [
            'tanggal_mulai' => $before->tanggal_mulai->toDateString(),
            'tanggal_selesai' => $before->tanggal_selesai->toDateString(),
            'alasan' => $before->alasan,
            'status' => $before->status,
            'lampiran_path' => $before->lampiran_path,
        ];
        $stepsBefore = $leave->steps()->orderBy('step_order')->get()->map->only(['id', 'status', 'step_order'])->all();
        $auditCount = AuditLog::count();

        $response = $this->patchJson(route('cuti.resubmit', $leave), [
            'tanggal_mulai' => '2026-07-13',
            'tanggal_selesai' => '2026-07-15',
            'alasan' => 'Revisi yang harus ditolak.',
            'alamat_selama_cuti' => 'Jl. Ditolak',
            'nomor_telepon' => '+62 123',
            'lampiran' => UploadedFile::fake()->create('revisi.pdf', 100, 'application/pdf'),
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['jenis_cuti_id']);
        $after = $leave->fresh();
        $this->assertSame($beforeValues, [
            'tanggal_mulai' => $after->tanggal_mulai->toDateString(),
            'tanggal_selesai' => $after->tanggal_selesai->toDateString(),
            'alasan' => $after->alasan,
            'status' => $after->status,
            'lampiran_path' => $after->lampiran_path,
        ]);
        $this->assertSame($stepsBefore, $leave->steps()->orderBy('step_order')->get()->map->only(['id', 'status', 'step_order'])->all());
        $this->assertSame($auditCount, AuditLog::count());
        $this->assertSame([], Storage::disk('public')->allFiles('cuti'));
    }

    public function test_post_pengajuan_gagal_tertutup_tanpa_chain_approval_dan_tidak_menyimpan_apa_pun(): void
    {
        // Pesan resolver dipetakan FormRequest menjadi validation error sebelum persistensi.
        $jenisPegawai = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $jenisPegawai->id,
            'is_kepala_lembaga' => false,
        ]);
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $jenis = $this->jenisCuti('Cuti Sakit');

        $this->actingAs($user);
        $this->withoutExceptionHandling();
        $validationExceptionObserved = false;

        try {
            $this->post(route(self::ROUTE), $this->payload($jenis, [
                'alasan' => 'Uji fail-closed tanpa chain',
            ]));
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Konfigurasi approval cuti pegawai belum tersedia.'],
                $exception->errors()['jenis_cuti_id'] ?? [],
            );
            $validationExceptionObserved = true;
        }

        $this->assertTrue($validationExceptionObserved, 'POST harus gagal validasi saat chain approval tidak tersedia.');
        $this->assertDatabaseMissing('leave_requests', [
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'alasan' => 'Uji fail-closed tanpa chain',
        ]);
    }

    public function test_get_form_mengunci_saat_chain_approval_belum_tersedia(): void
    {
        // Tanpa chain approval, form GET harus mengunci kontrol dan menampilkan panduan pasti agar pemohon menghubungi admin.
        $jenisPegawai = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $jenisPegawai->id,
            'is_kepala_lembaga' => false,
            'kepala_bagian_id' => null,
        ]);
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->actingAs($user);
        $response = $this->get(route('cuti.create'));

        $response->assertOk();
        $response->assertViewHas('chainReady', false);
        $response->assertSee('Konfigurasi approval cuti belum tersedia.');

        $content = $response->getContent();

        // Regex membatasi disabled pada tag kontrol tepat, bukan elemen lain dalam form.
        $this->assertMatchesRegularExpression('/<select\\b(?=[^>]*\\bid="jenis_cuti_id")(?=[^>]*\\bname="jenis_cuti_id")[^>]*\\bdisabled\\b[^>]*>/', $content);
        $this->assertMatchesRegularExpression('/<input\\b(?=[^>]*\\bid="tanggal_mulai")(?=[^>]*\\bname="tanggal_mulai")[^>]*\\bdisabled\\b[^>]*>/', $content);
        $this->assertMatchesRegularExpression('/<input\\b(?=[^>]*\\bid="tanggal_selesai")(?=[^>]*\\bname="tanggal_selesai")[^>]*\\bdisabled\\b[^>]*>/', $content);
        $this->assertMatchesRegularExpression('/<textarea\\b(?=[^>]*\\bid="alasan")(?=[^>]*\\bname="alasan")[^>]*\\bdisabled\\b[^>]*>/', $content);
        $this->assertMatchesRegularExpression('/<textarea\\b(?=[^>]*\\bid="alamat_selama_cuti")(?=[^>]*\\bname="alamat_selama_cuti")[^>]*\\bdisabled\\b[^>]*>/', $content);
        $this->assertMatchesRegularExpression('/<input\\b(?=[^>]*\\bid="nomor_telepon")(?=[^>]*\\bname="nomor_telepon")[^>]*\\bdisabled\\b[^>]*>/', $content);
        $this->assertMatchesRegularExpression('/<input\\b(?=[^>]*\\bid="lampiran")(?=[^>]*\\bname="lampiran")[^>]*\\bdisabled\\b[^>]*>/', $content);
        $this->assertMatchesRegularExpression('/<button\\b(?=[^>]*\\btype="submit")(?=[^>]*:disabled="saldoError \\|\\| true")[^>]*>/', $content);
    }

    public function test_get_form_menampilkan_label_peran_chain_yang_sebenarnya(): void
    {
        // Chain dua langkah aktif harus dirender sebagai label peran nyata berurutan, bukan panduan tetap lama.
        $aktor = $this->makePemohon();

        $this->actingAs($aktor['user']);
        $response = $this->get(route('cuti.create'));

        $response->assertOk();
        $response->assertViewHas('chainReady', true);
        $response->assertSee('Kepala Bagian');
        $response->assertSee('PYBMC');
        // Label tetap lama tidak boleh tersisa setelah panduan menjadi dinamis dari chain.
        $response->assertDontSee('Verifikator / Kabag');
    }
}
