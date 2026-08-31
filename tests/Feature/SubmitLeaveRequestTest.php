<?php

namespace Tests\Feature;

use App\Actions\Cuti\ResubmitLeaveRequestAction;
use App\Actions\Cuti\SubmitLeaveRequestAction;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\SimpegNotification;
use App\Models\StorageRecoveryTask;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\Cuti\ApprovalChainResolver;
use App\Services\Cuti\LeaveUsageReconciliationService;
use App\Services\EmployeeFileStorageService;
use App\Services\LeaveApprovalService;
use App\Services\NotificationService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        // Lampiran cuti wajib berada di disk privat; disk publik tetap dipalsukan untuk
        // membuktikan bahwa tidak ada artefak yang dapat diakses melalui /storage.
        Storage::fake('local');
        Storage::fake('public');

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
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-TEST-001',
            'tanggal_sk' => '2020-01-01',
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
            'code' => $nama === 'Cuti Tahunan' ? 'tahunan' : str($nama)->slug('_')->toString(),
            'mengurangi_saldo_tahunan' => $nama === 'Cuti Tahunan',
            'khusus_pns' => $khususPns,
        ]);
    }

    /**
     * Membentuk projection lewat snapshot pemakaian authoritative; nilai default menghabiskan N-2/N-1.
     *
     * @param  array{employee: Employee, user: User}  $aktor
     * @param  array<int, int>|null  $usage
     */
    private function reconcileAnnualProjection(array $aktor, int $year, ?array $usage = null): void
    {
        $usage ??= [$year - 2 => 12, $year - 1 => 12, $year => 0];
        $testNow = Carbon::getTestNow();

        try {
            Carbon::setTestNow(Carbon::create($year, 2, 3, 10, 0, 0, config('app.timezone')));
            app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
                $aktor['employee'],
                $year,
                $usage,
                now(config('app.timezone')),
                'Fixture rekonsiliasi pengajuan cuti.',
                $aktor['user'],
            );
        } finally {
            Carbon::setTestNow($testNow);
        }
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

        $this->actingAsUnmapped($user)->get(route('cuti.create'))->assertRedirect(route('status-akun'));
        $this->actingAsUnmapped($user)->postJson(route(self::ROUTE), $this->payload($jenis))->assertRedirect(route('status-akun'));
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
        $this->reconcileAnnualProjection($aktor, 2026);

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

    /** Rollback request simulasi membersihkan lampiran privat melalui manifest recovery owner-scoped. */
    public function test_simulated_leave_attachment_upload_is_recovered_when_usage_audit_fails(): void
    {
        $aktor = $this->makePemohon(role: 'super_admin');
        $jenis = $this->jenisCuti('Cuti Sakit Simulasi');

        $this->actingAs($aktor['user'])
            ->post(route('switch-role'), ['target_role' => 'pegawai'])
            ->assertRedirect(route('dashboard'));
        $aktor['user']->refresh();
        $this->installUsageAuditFailureTrigger();

        try {
            $response = $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
                'alasan' => 'Pengajuan simulasi yang wajib rollback.',
                'lampiran' => UploadedFile::fake()->create('surat-dokter.pdf', 64, 'application/pdf'),
            ]));

            $response->assertServerError();
        } finally {
            $this->removeUsageAuditFailureTrigger();
        }

        $this->assertDatabaseMissing('leave_requests', [
            'employee_id' => $aktor['employee']->id,
            'alasan' => 'Pengajuan simulasi yang wajib rollback.',
        ]);
        $this->assertSame([], Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->allFiles());
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'operation' => StorageRecoveryTask::OPERATION_DELETE,
            'status' => StorageRecoveryTask::STATUS_COMPLETED,
            'category' => 'leave_attachment',
            'disk' => LeaveRequest::ATTACHMENT_STORAGE_DISK,
            'owner_id' => $aktor['employee']->id,
            'attempts' => 1,
        ]);
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
        $this->reconcileAnnualProjection($aktor, 2026, [2024 => 12, 2025 => 12, 2026 => 10]);

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE), $this->payload($jenis));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['tanggal_selesai']);
        // Cara A: pengajuan ditolak otomatis dan tidak tersimpan sama sekali.
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_pengajuan_cuti_tahunan_menggunakan_projection_fakta_saat_submit(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Tahunan');
        $this->reconcileAnnualProjection($aktor, 2026);

        $this->actingAs($aktor['user']);
        $response = $this->post(route(self::ROUTE), $this->payload($jenis));

        $response->assertRedirect(route('cuti'));
        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $aktor['employee']->id,
            'jenis_cuti_id' => $jenis->id,
            'jumlah_hari_kerja' => 5,
        ]);
        $this->assertDatabaseHas('leave_usage_records', [
            'employee_id' => $aktor['employee']->id,
            'source_type' => 'annual_reconciliation',
            'usage_year' => 2026,
            'record_status' => 'active',
        ]);
    }

    public function test_cuti_tahunan_tanpa_rekonsiliasi_gagal_tertutup_tanpa_projection_lazy(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Tahunan');

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE), $this->payload($jenis));

        $response->assertUnprocessable()->assertJsonValidationErrors(['tanggal_selesai']);
        $this->assertDatabaseCount('leave_balances', 0);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
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

        $this->actingAsUnmapped($user);
        $response = $this->get(route('cuti.create'));

        $response->assertRedirect(route('status-akun'));
    }

    public function test_cuti_create_form_exposes_fact_backed_available_balance(): void
    {
        $aktor = $this->makePemohon();
        $this->jenisCuti('Cuti Tahunan')->forceFill(['code' => 'tahunan'])->save();

        $year = (int) now(config('app.timezone'))->year;
        // Projection form harus dibentuk dari fakta pemakaian, bukan direct-write saldo legacy.
        app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $aktor['employee'],
            $year,
            [$year - 2 => 12, $year - 1 => 12, $year => 5],
            now(config('app.timezone')),
            'Setup fakta pemakaian untuk saldo tersedia tujuh hari.',
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
        $response->assertSee('data-code="tahunan"', escape: false);
        $response->assertDontSee('data-mengurangi-saldo-tahunan', escape: false);
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
            'lampiran' => $this->pdfUpload('surat.pdf'),
        ]));

        $leave = LeaveRequest::query()->first();
        $this->assertNotNull($leave);
        $this->assertNotNull($leave->lampiran_path);
        $this->assertMatchesRegularExpression('#^cuti/lampiran/'.$aktor['employee']->id.'/[0-9a-f-]{36}\\.pdf$#', $leave->lampiran_path);
        $this->assertTrue(Storage::disk('local')->exists($leave->lampiran_path));
        $this->assertFalse(Storage::disk('public')->exists($leave->lampiran_path));
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'operation' => StorageRecoveryTask::OPERATION_CREATION_TARGET,
            'status' => StorageRecoveryTask::STATUS_ADOPTED,
            'category' => 'leave_attachment',
            'path' => $leave->lampiran_path,
            'owner_id' => $aktor['employee']->id,
        ]);
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
        $this->assertSame([], Storage::disk('local')->allFiles('cuti/lampiran'));
        $this->assertSame([], Storage::disk('public')->allFiles('cuti'));
    }

    public function test_unduh_lampiran_cuti_memerlukan_scope_backend_dan_selalu_memakai_header_privat(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit Unduh Privat');
        $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
            'lampiran' => $this->pdfUpload('rahasia.pdf'),
        ]));
        $leave = LeaveRequest::query()->sole();
        $url = route('cuti.attachment.download', $leave);

        $download = $this->actingAs($aktor['user'])->get($url);
        $download
            ->assertOk()
            ->assertDownload('Lampiran_Cuti_'.strtoupper(substr($leave->id, 0, 8)).'.pdf')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        foreach (['private', 'no-store', 'max-age=0'] as $directive) {
            $this->assertStringContainsString($directive, (string) $download->headers->get('Cache-Control'));
        }
        $this->actingAs($aktor['user'])->get(route('cuti.show', $leave))
            ->assertOk()
            ->assertSee($url, false)
            ->assertDontSee('/storage/', false);

        $snapshotApprover = User::factory()->pegawai()->create(['employee_id' => $aktor['supervisor']->id]);
        $this->actingAs($snapshotApprover)->get($url)->assertOk();

        $other = $this->makePemohon();
        $this->actingAs($other['user'])->get($url)->assertForbidden();
        Auth::logout();
        $this->get($url)->assertRedirect('/login');

        $leave->forceFill(['lampiran_path' => 'cuti/lampiran/'.$other['employee']->id.'/00000000-0000-4000-8000-000000000888.pdf'])->save();
        $this->actingAs($aktor['user'])->get($url)->assertNotFound();
        $leave->forceFill(['lampiran_path' => 'cuti/lampiran/'.$aktor['employee']->id.'/../00000000-0000-4000-8000-000000000888.pdf'])->save();
        $this->actingAs($aktor['user'])->get($url)->assertNotFound();
        $nestedSameOwner = 'cuti/lampiran/'.$aktor['employee']->id.'/nested/00000000-0000-4000-8000-000000000889.pdf';
        Storage::disk('local')->put($nestedSameOwner, 'file nested yang tidak kanonis');
        $leave->forceFill(['lampiran_path' => $nestedSameOwner])->save();
        $this->actingAs($aktor['user'])->get($url)->assertNotFound();
        app(EmployeeFileStorageService::class)->deleteLeaveAttachment($nestedSameOwner, $aktor['employee']->id);
        Storage::disk('local')->assertExists($nestedSameOwner);
    }

    public function test_audit_submit_hanya_memuat_allow_list_scalar_tanpa_pii_relasi_atau_path_privat(): void
    {
        $aktor = $this->makePemohon();
        $aktor['employee']->forceFill(['nip' => 'NIP-RAHASIA-001'])->save();
        $jenis = $this->jenisCuti('Cuti Sakit Audit Aman');
        $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
            'alamat_selama_cuti' => 'Alamat Rahasia Sekali',
            'nomor_telepon' => '081234567890',
            'lampiran' => UploadedFile::fake()->create('rahasia.pdf', 100, 'application/pdf'),
        ]));

        $values = AuditLog::query()->where('auditable_type', 'LeaveRequest')->where('event', 'CREATE')->sole()->new_values;
        $serialized = json_encode($values, JSON_THROW_ON_ERROR);
        foreach (['employee', 'jenis_cuti', 'lampiran_path', 'alamat_selama_cuti', 'nomor_telepon'] as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $values);
        }
        foreach (['NIP-RAHASIA-001', 'Alamat Rahasia Sekali', '081234567890', 'cuti/lampiran/'] as $forbiddenValue) {
            $this->assertStringNotContainsString($forbiddenValue, $serialized);
        }
    }

    public function test_kegagalan_audit_submit_membatalkan_seluruh_domain_effect_dan_membersihkan_lampiran_privat(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit Audit Gagal');
        AuditLog::creating(function (): void {
            static $shouldFail = true;
            if ($shouldFail) {
                $shouldFail = false;
                throw new \RuntimeException('Simulasi kegagalan audit submit.');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
                'lampiran' => UploadedFile::fake()->create('audit-submit.pdf', 100, 'application/pdf'),
            ]));
            $this->fail('Kegagalan audit harus menggagalkan submit secara atomik.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi kegagalan audit submit.', $exception->getMessage());
        }

        $this->assertDatabaseCount('leave_requests', 0);
        $this->assertDatabaseCount('leave_request_steps', 0);
        $this->assertDatabaseCount('leave_balance_reservation_events', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('cuti/lampiran'));
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
        $this->reconcileAnnualProjection($aktor, 2026, [2024 => 12, 2025 => 12, 2026 => 11]);

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
        // Projection tersedia agar penolakan murni karena rentang melintasi tahun, bukan ketiadaan saldo.
        $this->reconcileAnnualProjection($aktor, 2026);

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
        $jenis = $this->jenisCuti('Cuti Tahunan');
        $this->reconcileAnnualProjection($aktor, 2026);
        $aktor['employee']->appointment()->delete();

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
        $jenis = $this->jenisCuti('Cuti Tahunan');
        $this->reconcileAnnualProjection($aktor, 2026);
        $aktor['employee']->forceFill(['is_kepala_lembaga' => true])->save();

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
                'step_type' => 'verifier',
                'role_label' => 'Verifikator',
                'approver_employee_id' => $aktor['supervisor']->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Pemohon sebagai Kepala Bagian',
                'approver_employee_id' => $aktor['employee']->id,
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
        $this->reconcileAnnualProjection($aktor, 2027);
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

        $resubmitNotification = SimpegNotification::query()
            ->where('user_id', $aktor['supervisor']->id)
            ->where('type', 'cuti.pengajuan_baru')
            ->latest('id')
            ->firstOrFail();
        $this->assertIsString($resubmitNotification->data['notification_cycle_id'] ?? null);
        $this->assertNotSame('', $resubmitNotification->data['notification_cycle_id']);
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
        $this->reconcileAnnualProjection($aktor, 2027);
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
        $this->assertTrue(Storage::disk('local')->exists($oldPath));
        $this->assertSame([$oldPath], Storage::disk('local')->allFiles('cuti/lampiran'));
    }

    public function test_resubmit_berhasil_mengganti_lampiran_lalu_menghapus_file_lama(): void
    {

        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit Ganti Lampiran');
        $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
            'lampiran' => $this->pdfUpload('lama.pdf'),
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
            'lampiran' => $this->pdfUpload('baru.pdf'),
        ]);

        $response->assertRedirect(route('cuti.show', $leave));
        $newPath = $leave->fresh()->lampiran_path;
        $this->assertNotSame($oldPath, $newPath);
        $this->assertTrue(Storage::disk('local')->exists($newPath));
        $this->assertFalse(Storage::disk('local')->exists($oldPath));
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'operation' => StorageRecoveryTask::OPERATION_CREATION_TARGET,
            'status' => StorageRecoveryTask::STATUS_ADOPTED,
            'category' => 'leave_attachment',
            'path' => $newPath,
            'owner_id' => $aktor['employee']->id,
        ]);
        $audit = AuditLog::query()->where('auditable_type', 'LeaveRequest')->where('event', 'UPDATE')->latest('created_at')->firstOrFail();
        $oldKeys = array_keys($audit->old_values);
        $newValues = $audit->new_values;
        $this->assertSame('pegawai', $newValues['_effective_role'] ?? null);
        unset($newValues['_effective_role']);
        $newKeys = array_keys($newValues);
        sort($oldKeys);
        sort($newKeys);
        $this->assertSame($oldKeys, $newKeys);
        foreach ([$audit->old_values, $audit->new_values] as $values) {
            $this->assertIsArray($values);
            $serialized = json_encode($values, JSON_THROW_ON_ERROR);
            $this->assertSame($leave->employee_id, $values['employee_id']);
            $this->assertSame($leave->jenis_cuti_id, $values['jenis_cuti_id']);
            $this->assertTrue($values['alamat_selama_cuti_diisi']);
            $this->assertTrue($values['nomor_telepon_diisi']);
            $this->assertTrue($values['lampiran_diisi']);
            $this->assertTrue($values['alamat_selama_cuti_diubah']);
            $this->assertTrue($values['nomor_telepon_diubah']);
            $this->assertTrue($values['lampiran_diubah']);
            foreach (['employee', 'jenis_cuti', 'lampiran_path', 'alamat_selama_cuti', 'nomor_telepon'] as $forbiddenKey) {
                $this->assertArrayNotHasKey($forbiddenKey, $values);
            }
            $this->assertStringNotContainsString('cuti/lampiran/', $serialized);
        }
    }

    /** Rollback audit simulasi tidak boleh menyisakan referensi database ke lampiran lama yang sudah terhapus. */
    public function test_resubmit_simulasi_mempertahankan_lampiran_lama_saat_audit_penggunaan_gagal(): void
    {
        $aktor = $this->makePemohon(role: 'super_admin');
        $jenis = $this->jenisCuti('Cuti Sakit Resubmit Simulasi');

        $this->actingAs($aktor['user'])
            ->post(route('switch-role'), ['target_role' => 'pegawai'])
            ->assertRedirect(route('dashboard'));
        $aktor['user']->refresh();

        $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
            'lampiran' => $this->pdfUpload('lama-simulasi.pdf'),
        ]))->assertRedirect(route('cuti'));
        $leave = LeaveRequest::query()->firstOrFail();
        app(LeaveApprovalService::class)->requestChanges($leave, $aktor['supervisor'], 'Ganti lampiran simulasi.');
        $before = $leave->fresh();
        $oldPath = (string) $before->lampiran_path;
        $this->assertTrue(Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->exists($oldPath));

        $this->installUsageAuditFailureTrigger();

        try {
            $response = $this->actingAs($aktor['user'])->patch(route('cuti.resubmit', $leave), [
                'tanggal_mulai' => '2026-07-13',
                'tanggal_selesai' => '2026-07-15',
                'alasan' => 'Resubmit simulasi yang wajib rollback.',
                'alamat_selama_cuti' => 'Jl. Simulasi',
                'nomor_telepon' => '+62 431 456',
                'lampiran' => $this->pdfUpload('baru-simulasi.pdf'),
            ]);

            $response->assertServerError();
        } finally {
            $this->removeUsageAuditFailureTrigger();
        }

        $after = $leave->fresh();
        $this->assertSame($oldPath, $after->lampiran_path);
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->alasan, $after->alasan);
        $this->assertTrue(Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->exists($oldPath));
        $this->assertSame([$oldPath], Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->allFiles('cuti/lampiran'));
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'operation' => StorageRecoveryTask::OPERATION_CREATION_TARGET,
            'status' => StorageRecoveryTask::STATUS_ADOPTED,
            'path' => $oldPath,
            'owner_id' => $aktor['employee']->id,
        ]);
        $this->assertDatabaseMissing('storage_recovery_tasks', [
            'operation' => StorageRecoveryTask::OPERATION_DELETE,
            'path' => $oldPath,
        ]);
    }

    public function test_resubmit_model_stale_membersihkan_path_lama_dari_row_yang_dikunci(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit Cleanup Locked Row');
        $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
            'lampiran' => UploadedFile::fake()->create('stale.pdf', 100, 'application/pdf'),
        ]));
        $leave = LeaveRequest::query()->sole();
        app(LeaveApprovalService::class)->requestChanges($leave, $aktor['supervisor'], 'Perbarui lampiran.');
        $stale = $leave->fresh();
        $stalePath = (string) $stale->lampiran_path;
        $lockedPath = 'cuti/lampiran/'.$aktor['employee']->id.'/00000000-0000-4000-8000-000000000871.pdf';
        Storage::disk('local')->put($lockedPath, 'lampiran row terkini');
        LeaveRequest::query()->whereKey($leave->id)->update(['lampiran_path' => $lockedPath]);
        $data = [
            'tanggal_mulai' => '2026-07-13',
            'tanggal_selesai' => '2026-07-15',
            'alasan' => 'Mengganti lampiran berdasarkan row terkunci.',
            'alamat_selama_cuti' => 'Jl. Row Terkunci',
            'nomor_telepon' => '+62 431 871',
        ];
        $request = Request::create('/', 'PATCH', $data, [], [
            'lampiran' => UploadedFile::fake()->create('baru-locked.pdf', 100, 'application/pdf'),
        ]);
        $request->setUserResolver(fn () => $aktor['user']);

        app(ResubmitLeaveRequestAction::class)->execute($stale, $data, $request);

        Storage::disk('local')->assertExists($stalePath);
        Storage::disk('local')->assertMissing($lockedPath);
    }

    public function test_resubmit_shared_lampiran_privat_hanya_menghapus_file_setelah_referensi_terakhir_diganti(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit Shared Privat');
        $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
            'lampiran' => UploadedFile::fake()->create('shared.pdf', 100, 'application/pdf'),
        ]));
        $first = LeaveRequest::query()->sole();
        $sharedPath = (string) $first->lampiran_path;
        $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
            'tanggal_mulai' => '2026-08-03',
            'tanggal_selesai' => '2026-08-05',
        ]));
        $second = LeaveRequest::query()->whereKeyNot($first->id)->sole();
        $second->forceFill(['lampiran_path' => $sharedPath])->save();
        app(LeaveApprovalService::class)->requestChanges($first, $aktor['supervisor'], 'Ganti lampiran pertama.');
        app(LeaveApprovalService::class)->requestChanges($second, $aktor['supervisor'], 'Ganti lampiran kedua.');

        $this->actingAs($aktor['user'])->patch(route('cuti.resubmit', $first), [
            'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-07-15',
            'alasan' => 'Ganti referensi pertama.', 'alamat_selama_cuti' => 'Jl. Pertama', 'nomor_telepon' => '+62 431 1',
            'lampiran' => UploadedFile::fake()->create('first-new.pdf', 100, 'application/pdf'),
        ])->assertRedirect(route('cuti.show', $first));
        Storage::disk('local')->assertExists($sharedPath);

        $this->actingAs($aktor['user'])->patch(route('cuti.resubmit', $second), [
            'tanggal_mulai' => '2026-08-10', 'tanggal_selesai' => '2026-08-12',
            'alasan' => 'Ganti referensi terakhir.', 'alamat_selama_cuti' => 'Jl. Kedua', 'nomor_telepon' => '+62 431 2',
            'lampiran' => UploadedFile::fake()->create('second-new.pdf', 100, 'application/pdf'),
        ])->assertRedirect(route('cuti.show', $second));
        Storage::disk('local')->assertMissing($sharedPath);
    }

    public function test_resubmit_lampiran_legacy_public_membersihkan_source_setelah_commit(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit Legacy Public Tunggal');
        $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis));
        $leave = LeaveRequest::query()->sole();
        app(LeaveApprovalService::class)->requestChanges($leave, $aktor['supervisor'], 'Ganti lampiran legacy.');
        $legacyPath = 'cuti/legacy-tunggal.pdf';
        Storage::disk('public')->put($legacyPath, "%PDF-1.4\nlegacy\n%%EOF\n");
        $leave->forceFill(['lampiran_path' => $legacyPath])->save();

        $this->actingAs($aktor['user'])->patch(route('cuti.resubmit', $leave), [
            'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-07-15',
            'alasan' => 'Ganti legacy public.', 'alamat_selama_cuti' => 'Jl. Legacy', 'nomor_telepon' => '+62 431 3',
            'lampiran' => UploadedFile::fake()->create('legacy-new.pdf', 100, 'application/pdf'),
        ])->assertRedirect(route('cuti.show', $leave));

        Storage::disk('public')->assertMissing($legacyPath);
    }

    public function test_resubmit_shared_lampiran_legacy_public_menunggu_referensi_terakhir_sebelum_cleanup(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit Legacy Public Bersama');
        $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis));
        $first = LeaveRequest::query()->sole();
        $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
            'tanggal_mulai' => '2026-08-03', 'tanggal_selesai' => '2026-08-05',
        ]));
        $second = LeaveRequest::query()->whereKeyNot($first->id)->sole();
        $legacyPath = 'cuti/legacy-bersama.pdf';
        Storage::disk('public')->put($legacyPath, "%PDF-1.4\nshared legacy\n%%EOF\n");
        $first->forceFill(['lampiran_path' => $legacyPath])->save();
        $second->forceFill(['lampiran_path' => $legacyPath])->save();
        app(LeaveApprovalService::class)->requestChanges($first, $aktor['supervisor'], 'Ganti legacy pertama.');
        app(LeaveApprovalService::class)->requestChanges($second, $aktor['supervisor'], 'Ganti legacy kedua.');

        $this->actingAs($aktor['user'])->patch(route('cuti.resubmit', $first), [
            'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-07-15',
            'alasan' => 'Ganti legacy pertama.', 'alamat_selama_cuti' => 'Jl. Legacy Satu', 'nomor_telepon' => '+62 431 4',
            'lampiran' => UploadedFile::fake()->create('legacy-first.pdf', 100, 'application/pdf'),
        ])->assertRedirect(route('cuti.show', $first));
        Storage::disk('public')->assertExists($legacyPath);

        $this->actingAs($aktor['user'])->patch(route('cuti.resubmit', $second), [
            'tanggal_mulai' => '2026-08-10', 'tanggal_selesai' => '2026-08-12',
            'alasan' => 'Ganti legacy terakhir.', 'alamat_selama_cuti' => 'Jl. Legacy Dua', 'nomor_telepon' => '+62 431 5',
            'lampiran' => UploadedFile::fake()->create('legacy-second.pdf', 100, 'application/pdf'),
        ])->assertRedirect(route('cuti.show', $second));
        Storage::disk('public')->assertMissing($legacyPath);
    }

    public function test_resubmit_menolak_path_legacy_nested_sebelum_mutasi_database_atau_file_lama(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit Legacy Nested Tidak Aman');
        $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis));
        $leave = LeaveRequest::query()->sole();
        app(LeaveApprovalService::class)->requestChanges($leave, $aktor['supervisor'], 'Ganti lampiran legacy tidak aman.');
        $unsafePath = 'cuti/nested/legacy-tidak-aman.pdf';
        Storage::disk('public')->put($unsafePath, "%PDF-1.4\nlegacy nested\n%%EOF\n");
        $leave->forceFill(['lampiran_path' => $unsafePath])->save();
        $before = $this->rollbackSnapshot($leave->fresh());
        $data = [
            'tanggal_mulai' => '2026-07-13',
            'tanggal_selesai' => '2026-07-15',
            'alasan' => 'Path lama harus divalidasi sebelum mutasi.',
            'alamat_selama_cuti' => 'Jl. Fail Closed',
            'nomor_telepon' => '+62 431 6',
        ];
        $request = Request::create('/', 'PATCH', $data, [], [
            'lampiran' => UploadedFile::fake()->create('pengganti.pdf', 100, 'application/pdf'),
        ]);
        $request->setUserResolver(fn () => $aktor['user']);

        $caught = null;
        try {
            app(ResubmitLeaveRequestAction::class)->execute($leave->fresh(), $data, $request);
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught, 'Resubmit harus fail closed saat path lama berada di subdirektori legacy yang tidak kanonis.');
        $this->assertStringContainsString('lampiran', mb_strtolower($caught->getMessage()));
        $this->assertSame($before, $this->rollbackSnapshot($leave->fresh()));
        Storage::disk('public')->assertExists($unsafePath);
        $this->assertSame([], Storage::disk('local')->allFiles(LeaveRequest::ATTACHMENT_PATH_PREFIX));
    }

    public function test_kegagalan_audit_resubmit_merollback_data_dan_mempertahankan_file_lama_sambil_membersihkan_file_baru(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit Audit Resubmit Gagal');
        $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
            'lampiran' => UploadedFile::fake()->create('lama-audit.pdf', 100, 'application/pdf'),
        ]));
        $leave = LeaveRequest::firstOrFail();
        app(LeaveApprovalService::class)->requestChanges($leave, $aktor['supervisor'], 'Perlu perbaikan sebelum audit gagal.');
        $oldPath = $leave->fresh()->lampiran_path;
        $before = $this->rollbackSnapshot($leave->fresh());

        AuditLog::creating(function (): void {
            static $shouldFail = true;
            if ($shouldFail) {
                $shouldFail = false;
                throw new \RuntimeException('Simulasi kegagalan audit resubmit.');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($aktor['user'])->patch(route('cuti.resubmit', $leave), [
                'tanggal_mulai' => '2026-07-13',
                'tanggal_selesai' => '2026-07-15',
                'alasan' => 'Perubahan yang wajib atomik.',
                'alamat_selama_cuti' => 'Jl. Tidak Boleh Tersimpan',
                'nomor_telepon' => '+62 431 999999',
                'lampiran' => UploadedFile::fake()->create('baru-audit.pdf', 100, 'application/pdf'),
            ]);
            $this->fail('Kegagalan audit harus membatalkan resubmit secara atomik.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi kegagalan audit resubmit.', $exception->getMessage());
        }

        $this->assertSame($before, $this->rollbackSnapshot($leave->fresh()));
        $this->assertTrue(Storage::disk('local')->exists($oldPath));
        $this->assertSame([$oldPath], Storage::disk('local')->allFiles('cuti/lampiran'));
        $this->assertSame([], Storage::disk('public')->allFiles('cuti'));
    }

    public function test_resubmit_tidak_menghapus_file_lokal_lain_bila_path_lama_ditamper(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit Cleanup Path Aman');
        $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
            'lampiran' => UploadedFile::fake()->create('lama-cleanup.pdf', 100, 'application/pdf'),
        ]));
        $leave = LeaveRequest::query()->sole();
        app(LeaveApprovalService::class)->requestChanges($leave, $aktor['supervisor'], 'Perbarui lampiran.');
        $foreign = Employee::factory()->create();
        $foreignPath = 'cuti/lampiran/'.$foreign->id.'/00000000-0000-4000-8000-000000000666.pdf';
        Storage::disk('local')->put($foreignPath, 'milik pegawai lain');
        $leave->forceFill(['lampiran_path' => $foreignPath])->save();
        $before = $this->rollbackSnapshot($leave->fresh());
        $filesBefore = Storage::disk('local')->allFiles(LeaveRequest::ATTACHMENT_PATH_PREFIX);
        $caught = null;
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($aktor['user'])->patch(route('cuti.resubmit', $leave), [
                'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-07-15',
                'alasan' => 'Ganti lampiran dengan aman.', 'alamat_selama_cuti' => 'Jl. Aman', 'nomor_telepon' => '+62 431 10',
                'lampiran' => UploadedFile::fake()->create('baru-cleanup.pdf', 100, 'application/pdf'),
            ]);
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught);
        $this->assertStringContainsString('lampiran', mb_strtolower($caught->getMessage()));
        $this->assertSame($before, $this->rollbackSnapshot($leave->fresh()));
        $this->assertTrue(Storage::disk('local')->exists($foreignPath));
        $this->assertEqualsCanonicalizing(
            $filesBefore,
            Storage::disk('local')->allFiles(LeaveRequest::ATTACHMENT_PATH_PREFIX),
        );
    }

    /** Menormalkan cast Carbon agar assertion rollback membandingkan nilai database, bukan identitas objek. */
    private function rollbackSnapshot(LeaveRequest $leave): array
    {
        return [
            'tanggal_mulai' => $leave->tanggal_mulai?->toDateString(),
            'tanggal_selesai' => $leave->tanggal_selesai?->toDateString(),
            'jumlah_hari_kerja' => $leave->jumlah_hari_kerja,
            'alasan' => $leave->alasan,
            'status' => $leave->status,
            'lampiran_path' => $leave->lampiran_path,
        ];
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
        $this->assertSame([], Storage::disk('local')->allFiles('cuti/lampiran'));
        $this->assertSame([], Storage::disk('public')->allFiles('cuti'));
    }

    public function test_post_pengajuan_gagal_tertutup_tanpa_chain_approval_dan_tidak_menyimpan_apa_pun(): void
    {
        // Pesan resolver dipetakan Action menjadi validation error sebelum persistensi.
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

    public function test_submit_tidak_memetakan_query_exception_resolver_menjadi_error_validasi(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit Query Exception');
        $driverException = new \PDOException('relation employee_lock_view does not exist');
        $driverException->errorInfo = ['42P01', null, 'relation employee_lock_view does not exist'];
        $queryException = new QueryException(
            'pgsql',
            'select * from employee_lock_view where id = ? for update',
            [$aktor['supervisor']->id],
            $driverException,
        );

        $this->mock(ApprovalChainResolver::class, function (MockInterface $mock) use ($queryException): void {
            /** @var Expectation $expectation */
            $expectation = $mock->shouldReceive('resolveEffectiveSteps');
            $expectation->once()->andThrow($queryException);
        });

        $httpRequest = Request::create('/cuti', 'POST');
        $httpRequest->setUserResolver(fn (): User => $aktor['user']);

        $this->expectException(QueryException::class);

        app(SubmitLeaveRequestAction::class)->execute(
            $aktor['employee'],
            $this->payload($jenis),
            $httpRequest,
        );
    }

    public function test_post_simulasi_mengambil_lock_konfigurasi_sebelum_lock_pegawai(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Urutan advisory lock submit simulasi diverifikasi khusus pada PostgreSQL.');
        }

        $aktor = $this->makePemohon('PNS', 'super_admin');
        $jenis = $this->jenisCuti('Cuti Sakit Urutan Lock Simulasi');
        $aktor['user']->forceFill([
            'temporary_role' => 'pegawai',
            'temporary_role_started_at' => now(),
            'temporary_role_switched_by' => $aktor['user']->id,
        ])->save();
        $lockOrder = [];

        DB::listen(static function (QueryExecuted $query) use (&$lockOrder): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'pg_advisory_xact_lock')
                && in_array('simpeg.leave_chain_configuration', $query->bindings, true)) {
                $lockOrder[] = 'configuration';

                return;
            }

            if (str_contains($sql, 'from "employees"') && str_contains($sql, 'for update')) {
                $lockOrder[] = 'employee';
            }
        });

        $response = $this->actingAs($aktor['user'])->post(route(self::ROUTE), $this->payload($jenis, [
            'alasan' => 'Uji urutan lock pada submit simulasi.',
        ]));

        $response->assertRedirect(route('cuti'));
        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $aktor['employee']->id,
            'jenis_cuti_id' => $jenis->id,
            'alasan' => 'Uji urutan lock pada submit simulasi.',
        ]);
        $this->assertSame('configuration', $lockOrder[0] ?? null, json_encode($lockOrder, JSON_THROW_ON_ERROR));
        $this->assertContains('employee', $lockOrder);
    }

    public function test_post_pengajuan_menolak_chain_legacy_invalid_sebelum_mutasi_domain(): void
    {
        $aktor = $this->makePemohon();
        $chain = LeaveApprovalChain::query()
            ->where('employee_id', $aktor['employee']->id)
            ->where('is_active', true)
            ->sole();
        $chain->steps()->delete();
        $chain->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $aktor['supervisor']->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'verifier',
                'role_label' => 'Verifikator Legacy',
                'approver_employee_id' => Employee::factory()->create()->id,
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
        $jenis = $this->jenisCuti('Cuti Sakit Chain Legacy');
        $jumlahAuditSebelum = AuditLog::query()->count();

        $response = $this->actingAs($aktor['user'])->postJson(
            route(self::ROUTE),
            $this->payload($jenis),
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['jenis_cuti_id']);
        $this->assertSame(
            ['Semua Verifikator harus ditempatkan sebelum Kepala Bagian.'],
            $response->json('errors.jenis_cuti_id'),
        );
        $this->assertDatabaseCount('leave_requests', 0);
        $this->assertDatabaseCount('leave_request_steps', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame($jumlahAuditSebelum, AuditLog::query()->count());
    }

    public function test_post_pengajuan_menolak_kepala_bagian_efektif_yang_menjadi_nonaktif_tanpa_mutasi_domain(): void
    {
        $aktor = $this->makePemohon();
        $statusPensiun = RefStatusPegawai::firstOrCreate(
            ['kode' => 'PENSIUN'],
            [
                'nama' => 'Pensiun',
                'kelompok' => 'Nonaktif',
                'keterangan' => 'Pegawai telah memasuki masa pensiun.',
                'is_active' => true,
                'is_default' => false,
            ],
        );
        $aktor['supervisor']->update(['status_pegawai_id' => $statusPensiun->id]);
        $this->assertFalse($aktor['supervisor']->fresh()->isActive());

        $jenis = $this->jenisCuti('Cuti Sakit Kepala Bagian Nonaktif');
        $jumlahAuditSebelum = AuditLog::query()->count();
        $jumlahReservasiSebelum = LeaveBalanceReservationEvent::query()->count();
        $jumlahNotifikasiSebelum = SimpegNotification::query()->count();

        $response = $this->actingAs($aktor['user'])->postJson(
            route(self::ROUTE),
            $this->payload($jenis),
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['jenis_cuti_id']);
        $this->assertSame(
            ['Approver pada rantai approval cuti wajib berstatus Aktif.'],
            $response->json('errors.jenis_cuti_id'),
        );
        $this->assertDatabaseCount('leave_requests', 0);
        $this->assertDatabaseCount('leave_request_steps', 0);
        $this->assertSame($jumlahReservasiSebelum, LeaveBalanceReservationEvent::query()->count());
        $this->assertSame($jumlahNotifikasiSebelum, SimpegNotification::query()->count());
        $this->assertSame($jumlahAuditSebelum, AuditLog::query()->count());
    }

    public function test_post_pengajuan_menerima_resolved_approver_dengan_status_aktif_khusus(): void
    {
        $aktor = $this->makePemohon();
        $statusTugasBelajar = RefStatusPegawai::firstOrCreate(
            ['kode' => 'TUGAS_BELAJAR'],
            [
                'nama' => 'Tugas Belajar',
                'kelompok' => 'Aktif/khusus',
                'keterangan' => 'Pegawai menjalani tugas belajar.',
                'is_active' => true,
                'is_default' => false,
            ],
        );
        $aktor['pybmc']->update(['status_pegawai_id' => $statusTugasBelajar->id]);
        $jenis = $this->jenisCuti('Cuti Sakit Approver Tugas Belajar');

        $this->actingAs($aktor['user'])
            ->post(route(self::ROUTE), $this->payload($jenis))
            ->assertRedirect(route('cuti'));

        $this->assertTrue($aktor['pybmc']->fresh()->isActive());
        $this->assertDatabaseHas('leave_request_steps', [
            'step_order' => 2,
            'step_type' => 'pybmc',
            'approver_employee_id' => $aktor['pybmc']->id,
            'status' => 'pending',
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

    /** Membuat upload PDF dengan byte nyata agar verifikasi SHA-256 menguji artifact, bukan metadata fake. */
    private function pdfUpload(string $name): UploadedFile
    {
        return UploadedFile::fake()
            ->createWithContent($name, "%PDF-1.4\n% {$name}\n%%EOF\n")
            ->mimeType('application/pdf');
    }

    /** Memasang kegagalan database terarah agar rollback audit penggunaan dapat diuji nyata. */
    private function installUsageAuditFailureTrigger(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION fail_role_simulation_usage_audit()
                RETURNS trigger AS $$
                BEGIN
                    IF NEW.event = 'ROLE_SIMULATION_USAGE' THEN
                        RAISE EXCEPTION 'forced role simulation usage audit failure';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER fail_role_simulation_usage_audit
                BEFORE INSERT ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION fail_role_simulation_usage_audit()
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_role_simulation_usage_audit
            BEFORE INSERT ON audit_logs
            WHEN NEW.event = 'ROLE_SIMULATION_USAGE'
            BEGIN
                SELECT RAISE(ABORT, 'forced role simulation usage audit failure');
            END
            SQL);
    }

    /** Membersihkan trigger failure-injection agar test lain tetap memakai audit normal. */
    private function removeUsageAuditFailureTrigger(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_role_simulation_usage_audit ON audit_logs');
            DB::unprepared('DROP FUNCTION IF EXISTS fail_role_simulation_usage_audit()');

            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS fail_role_simulation_usage_audit');
    }
}
