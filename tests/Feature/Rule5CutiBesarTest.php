<?php

namespace Tests\Feature;

use App\Actions\Cuti\ApproveLeaveAction;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceService;
use App\Services\Cuti\LeaveUsageRecordService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Rule5CutiBesarTest extends TestCase
{
    use RefreshDatabase;

    private string $rollbackProbeLeaveRequestId;

    private int $rollbackAuditCount;

    private int $rollbackNotificationCount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ReferenceSeeder::class, RbacSeeder::class]);
    }

    /** @return array{employee: Employee, approver1: Employee, approver2: Employee} */
    private function actors(string $tmt = '2018-01-01'): array
    {
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', 'PNS')->value('id'),
        ]);
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => $tmt,
            'no_sk' => 'SK-RULE-5',
            'tanggal_sk' => $tmt,
        ]);

        return [
            'employee' => $employee,
            'approver1' => Employee::factory()->create(),
            'approver2' => Employee::factory()->create(),
        ];
    }

    private function leaveType(string $code): RefJenisCuti
    {
        return RefJenisCuti::query()->where('code', $code)->firstOrFail();
    }

    private function request(
        Employee $employee,
        RefJenisCuti $type,
        string $status,
        string $start = '2026-08-03',
        string $end = '2026-08-07',
        int $workdays = 5,
    ): LeaveRequest {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $type->id,
            'tanggal_mulai' => $start,
            'tanggal_selesai' => $end,
            'jumlah_hari_kerja' => $workdays,
            'alasan' => 'Fixture Rule 5.',
            'status' => $status,
        ]);
    }

    private function recordApprovedUsage(
        Employee $employee,
        RefJenisCuti $type,
        string $start = '2026-08-03',
        string $end = '2026-08-07',
        int $workdays = 5,
    ): LeaveRequest {
        $leaveRequest = $this->request($employee, $type, 'disetujui', $start, $end, $workdays);
        app(LeaveUsageRecordService::class)->recordApprovedRequest(
            $leaveRequest,
            User::factory()->adminKepegawaian()->create(),
        );

        return $leaveRequest;
    }

    /** @param array{employee: Employee, approver1: Employee, approver2: Employee} $actors */
    private function pendingFinalRequest(array $actors, RefJenisCuti $type, string $start = '2026-08-03', string $end = '2026-08-07'): LeaveRequest
    {
        $request = $this->request($actors['employee'], $type, 'menunggu_approval', $start, $end);
        $request->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $actors['approver1']->id,
                'status' => 'approved',
                'is_final' => false,
                'acted_at' => now(),
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $actors['approver2']->id,
                'status' => 'active',
                'is_final' => true,
            ],
        ]);

        $this->seedRollbackSideEffectProbe($actors['employee']);

        return $request;
    }

    /** Menyediakan bukti audit/notifikasi yang sudah ada sebelum guard final dieksekusi. */
    private function seedRollbackSideEffectProbe(Employee $employee): void
    {
        $this->rollbackProbeLeaveRequestId = (string) Str::uuid();

        AuditLog::create([
            'event' => 'CREATE',
            'auditable_type' => 'LeaveRequest',
            'auditable_id' => $this->rollbackProbeLeaveRequestId,
        ]);
        SimpegNotification::create([
            'user_id' => $employee->id,
            'type' => 'cuti.menunggu_persetujuan',
            'title' => 'Probe rollback Rule 5',
            'body' => 'Bukti notifikasi sudah ada sebelum guard persetujuan final dijalankan.',
            'data' => ['leave_request_id' => $this->rollbackProbeLeaveRequestId],
        ]);
        $this->rollbackAuditCount = AuditLog::query()->count();
        $this->rollbackNotificationCount = SimpegNotification::query()->count();
    }

    private function balance(Employee $employee, array $overrides = []): LeaveBalance
    {
        return LeaveBalance::create(array_merge([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 6,
            'terpakai' => 0,
            'sisa' => 18,
            'sisa_n2' => 0,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ], $overrides));
    }

    private function assertFinalApprovalRolledBack(LeaveRequest $large): void
    {
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CREATE',
            'auditable_type' => 'LeaveRequest',
            'auditable_id' => $this->rollbackProbeLeaveRequestId,
        ]);
        $this->assertTrue(SimpegNotification::query()
            ->where('data->leave_request_id', $this->rollbackProbeLeaveRequestId)
            ->exists());
        $this->assertSame($this->rollbackAuditCount, AuditLog::query()->count());
        $this->assertSame($this->rollbackNotificationCount, SimpegNotification::query()->count());
        $this->assertSame('menunggu_approval', $large->fresh()->status);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $large->id,
            'step_order' => 2,
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('leave_approvals', [
            'leave_request_id' => $large->id,
            'stage' => 2,
            'action' => 'APPROVE',
        ]);
        $this->assertDatabaseMissing('leave_proofs', ['leave_request_id' => $large->id]);
        // Persetujuan tahap menengah dan keputusan akhir memakai kosakata berbeda, sehingga keduanya
        // harus sama-sama tidak ada agar test tetap membuktikan pengajuan belum pernah disetujui.
        foreach (['VERIFY', 'DECIDE'] as $eventKeputusan) {
            $this->assertDatabaseMissing('audit_logs', [
                'auditable_type' => 'LeaveRequest',
                'auditable_id' => $large->id,
                'event' => $eventKeputusan,
            ]);
        }
        $this->assertFalse(SimpegNotification::query()
            ->where('data->leave_request_id', $large->id)
            ->exists());
    }

    /** Menjalankan jalur Action nyata agar rollback meliputi orkestrasi audit dan notifikasi. */
    private function approveFinal(LeaveRequest $large, Employee $actor): LeaveRequest
    {
        $actingUser = User::factory()->create(['employee_id' => $actor->id]);
        $request = Request::create('/cuti/approval', 'POST');
        $request->setUserResolver(fn (): User => $actingUser);

        return app(ApproveLeaveAction::class)->execute(
            $large,
            $actor,
            null,
            $request,
        );
    }

    /** Memastikan pesan domain tidak berubah menjadi validasi generik yang sulit ditindaklanjuti pengguna. */
    private function assertValidationError(callable $action, string $field, string $message): void
    {
        try {
            $action();
            $this->fail("Validasi {$field} seharusnya ditolak.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            $this->assertSame($message, $exception->errors()[$field][0]);
        }
    }

    /** @return array<string, array{string}> */
    public static function activeAnnualStatuses(): array
    {
        return [
            'menunggu approval' => ['menunggu_approval'],
            'ditangguhkan generik' => ['ditangguhkan'],
            'perlu perubahan' => ['perlu_perubahan'],
        ];
    }

    #[DataProvider('activeAnnualStatuses')]
    public function test_final_cuti_besar_ditolak_oleh_request_tahunan_aktif_dan_rollback_atomik(string $status): void
    {
        $actors = $this->actors();
        $annual = $this->request($actors['employee'], $this->leaveType('tahunan'), $status);
        $large = $this->pendingFinalRequest($actors, $this->leaveType('besar'));

        $this->assertValidationError(
            fn () => $this->approveFinal($large, $actors['approver2']),
            'status',
            'Cuti Besar tidak dapat disetujui karena masih ada pengajuan Cuti Tahunan aktif pada tahun yang sama.',
        );

        $this->assertSame($status, $annual->fresh()->status);
        $this->assertFinalApprovalRolledBack($large);
    }

    public function test_final_cuti_besar_ditolak_oleh_fakta_pemakaian_tahunan_aktif(): void
    {
        $actors = $this->actors();
        $this->recordApprovedUsage($actors['employee'], $this->leaveType('tahunan'));
        $large = $this->pendingFinalRequest($actors, $this->leaveType('besar'));

        $this->assertValidationError(
            fn () => $this->approveFinal($large, $actors['approver2']),
            'status',
            'Cuti Besar tidak dapat disetujui karena Cuti Tahunan tahun yang sama sudah digunakan.',
        );
        $this->assertFinalApprovalRolledBack($large);
    }

    public function test_final_cuti_besar_ditolak_oleh_fakta_tahunan_pada_tahun_penggunaan(): void
    {
        $actors = $this->actors();
        $this->recordApprovedUsage(
            $actors['employee'],
            $this->leaveType('tahunan'),
            '2026-02-02',
            '2026-02-03',
            2,
        );
        $large = $this->pendingFinalRequest($actors, $this->leaveType('besar'));

        $this->assertValidationError(
            fn () => $this->approveFinal($large, $actors['approver2']),
            'status',
            'Cuti Besar tidak dapat disetujui karena Cuti Tahunan tahun yang sama sudah digunakan.',
        );
        $this->assertFinalApprovalRolledBack($large);
    }

    public function test_final_cuti_besar_ditolak_oleh_reservasi_tahunan_net_aktif_walau_status_request_tidak_aktif(): void
    {
        $actors = $this->actors();
        $balance = $this->balance($actors['employee']);
        $annual = $this->request($actors['employee'], $this->leaveType('tahunan'), 'menunggu_approval');
        LeaveBalanceReservationEvent::create([
            'employee_id' => $actors['employee']->id,
            'leave_request_id' => $annual->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
            'amount' => 5,
            'reason' => 'Reservasi aktif Rule 5.',
            'dedup_key' => "rule5:reservation:{$annual->id}",
            'occurred_at' => now(),
        ]);
        $annual->forceFill(['status' => 'tidak_disetujui'])->saveQuietly();
        $large = $this->pendingFinalRequest($actors, $this->leaveType('besar'));

        $this->assertValidationError(
            fn () => $this->approveFinal($large, $actors['approver2']),
            'status',
            'Cuti Besar tidak dapat disetujui karena saldo Cuti Tahunan masih dialokasikan pada tahun yang sama.',
        );
        $this->assertFinalApprovalRolledBack($large);
    }

    public function test_final_cuti_besar_mengabaikan_reservasi_jenis_alternatif_non_tahunan(): void
    {
        $actors = $this->actors();
        $balance = $this->balance($actors['employee']);
        $alternative = RefJenisCuti::create([
            'nama' => 'Cuti Alternatif Non Tahunan',
            'code' => 'alternatif_non_tahunan',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $request = $this->request($actors['employee'], $alternative, 'tidak_disetujui');
        LeaveBalanceReservationEvent::create([
            'employee_id' => $actors['employee']->id,
            'leave_request_id' => $request->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
            'amount' => 5,
            'reason' => 'Fixture reservasi alternatif non-tahunan.',
            'dedup_key' => "rule5:alternative-non-annual-reservation:{$request->id}",
            'occurred_at' => now(),
        ]);
        $large = $this->pendingFinalRequest($actors, $this->leaveType('besar'));

        $this->approveFinal($large, $actors['approver2']);

        $this->assertSame('disetujui', $large->fresh()->status);
    }

    public function test_final_jenis_alternatif_non_tahunan_mengabaikan_marker_rollover(): void
    {
        $actors = $this->actors();
        $balance = $this->balance($actors['employee']);
        $alternative = RefJenisCuti::create([
            'nama' => 'Cuti Alternatif Non Tahunan',
            'code' => 'alternatif_non_tahunan',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        LeaveBalanceLedger::create([
            'employee_id' => $actors['employee']->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
            'amount' => 0,
            'source_year' => 2026,
            'reason' => 'Marker penutupan tahun sumber.',
            'dedup_key' => "rule5:alternative-annual-rollover:{$actors['employee']->id}",
            'occurred_at' => now(),
        ]);
        $request = $this->pendingFinalRequest($actors, $alternative);

        $this->approveFinal($request, $actors['approver2']);

        $this->assertSame('disetujui', $request->fresh()->status);
    }

    public function test_final_cuti_besar_mengabaikan_reservasi_non_tahunan(): void
    {
        $actors = $this->actors();
        $balance = $this->balance($actors['employee']);
        $sick = $this->request($actors['employee'], $this->leaveType('sakit'), 'tidak_disetujui');
        LeaveBalanceReservationEvent::create([
            'employee_id' => $actors['employee']->id,
            'leave_request_id' => $sick->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
            'amount' => 5,
            'reason' => 'Data non-tahunan tidak boleh menjadi fakta konflik Rule 5.',
            'dedup_key' => "rule5:non-annual-reservation:{$sick->id}",
            'occurred_at' => now(),
        ]);
        $large = $this->pendingFinalRequest($actors, $this->leaveType('besar'));

        $this->approveFinal($large, $actors['approver2']);

        $this->assertSame('disetujui', $large->fresh()->status);
    }

    public function test_final_cuti_besar_memvalidasi_ulang_status_pns_dari_request_tersimpan(): void
    {
        $actors = $this->actors();
        $large = $this->pendingFinalRequest($actors, $this->leaveType('besar'));
        $actors['employee']->forceFill([
            'jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', 'PPPK')->value('id'),
        ])->save();

        $this->assertValidationError(
            fn () => $this->approveFinal($large, $actors['approver2']),
            'jenis_cuti_id',
            'Jenis cuti ini hanya dapat diajukan oleh pegawai berstatus PNS.',
        );
        $this->assertFinalApprovalRolledBack($large);
    }

    public function test_final_cuti_besar_memvalidasi_ulang_masa_kerja_lima_tahun_dari_request_tersimpan(): void
    {
        $actors = $this->actors('2021-08-04');
        $large = $this->pendingFinalRequest($actors, $this->leaveType('besar'));

        $this->assertValidationError(
            fn () => $this->approveFinal($large, $actors['approver2']),
            'tanggal_mulai',
            'Cuti Besar hanya dapat diajukan setelah masa kerja minimal 5 tahun kalender sejak TMT pengangkatan (04-08-2026).',
        );
        $this->assertFinalApprovalRolledBack($large);
    }

    public function test_final_cuti_besar_memvalidasi_ulang_durasi_maksimal_tiga_bulan_dari_request_tersimpan(): void
    {
        $actors = $this->actors();
        $large = $this->pendingFinalRequest($actors, $this->leaveType('besar'), '2026-08-03', '2026-11-03');

        $this->assertValidationError(
            fn () => $this->approveFinal($large, $actors['approver2']),
            'tanggal_selesai',
            'Cuti Besar paling lama 3 bulan kalender. Batas akhir pengajuan ini adalah 02-11-2026.',
        );
        $this->assertFinalApprovalRolledBack($large);
    }

    public function test_final_cuti_besar_memvalidasi_ulang_rentang_satu_tahun_dari_request_tersimpan(): void
    {
        $actors = $this->actors();
        $large = $this->pendingFinalRequest($actors, $this->leaveType('besar'), '2026-12-01', '2027-01-02');

        $this->assertValidationError(
            fn () => $this->approveFinal($large, $actors['approver2']),
            'tanggal_selesai',
            'Pengajuan cuti tidak boleh melewati tahun kalender. Pisahkan menjadi dua pengajuan terpisah untuk tiap tahun.',
        );
        $this->assertFinalApprovalRolledBack($large);
    }

    public function test_approve_action_tidak_menulis_audit_atau_notifikasi_saat_guard_final_menolak(): void
    {
        $actors = $this->actors();
        $this->request($actors['employee'], $this->leaveType('tahunan'), 'menunggu_approval');
        $large = $this->pendingFinalRequest($actors, $this->leaveType('besar'));

        $this->assertValidationError(
            fn () => $this->approveFinal($large, $actors['approver2']),
            'status',
            'Cuti Besar tidak dapat disetujui karena masih ada pengajuan Cuti Tahunan aktif pada tahun yang sama.',
        );

        $this->assertFinalApprovalRolledBack($large);
    }

    public function test_final_cuti_besar_mengabaikan_request_tahunan_aktif_tahun_sebelumnya(): void
    {
        $actors = $this->actors();
        $this->request($actors['employee'], $this->leaveType('tahunan'), 'menunggu_approval', '2025-12-01', '2025-12-05');
        $large = $this->pendingFinalRequest($actors, $this->leaveType('besar'));

        $this->approveFinal($large, $actors['approver2']);

        $this->assertSame('disetujui', $large->fresh()->status);
    }

    public function test_final_cuti_besar_mengabaikan_fakta_tahunan_tahun_sebelumnya(): void
    {
        $actors = $this->actors();
        $this->recordApprovedUsage(
            $actors['employee'],
            $this->leaveType('tahunan'),
            '2025-12-01',
            '2025-12-02',
            2,
        );
        $large = $this->pendingFinalRequest($actors, $this->leaveType('besar'));

        $this->approveFinal($large, $actors['approver2']);

        $this->assertSame('disetujui', $large->fresh()->status);
    }

    public function test_final_cuti_besar_mengabaikan_request_tahunan_ditangguhkan_tugas_dinas(): void
    {
        $actors = $this->actors();
        $this->request($actors['employee'], $this->leaveType('tahunan'), LeaveRequest::STATUS_DUTY_POSTPONED);
        $large = $this->pendingFinalRequest($actors, $this->leaveType('besar'));

        $this->approveFinal($large, $actors['approver2']);

        $this->assertSame('disetujui', $large->fresh()->status);
    }

    public function test_final_cuti_besar_menerima_batas_durasi_tiga_bulan_secara_inklusif(): void
    {
        $actors = $this->actors();
        $large = $this->pendingFinalRequest($actors, $this->leaveType('besar'), '2026-08-03', '2026-11-02');

        $this->approveFinal($large, $actors['approver2']);

        $this->assertSame('disetujui', $large->fresh()->status);
    }

    public function test_final_cuti_besar_menerima_batas_masa_kerja_lima_tahun_tepat(): void
    {
        $actors = $this->actors('2021-08-03');
        $large = $this->pendingFinalRequest($actors, $this->leaveType('besar'));

        $this->approveFinal($large, $actors['approver2']);

        $this->assertSame('disetujui', $large->fresh()->status);
    }

    public function test_final_cuti_besar_membuat_seluruh_bucket_tahunan_tidak_tersedia_tanpa_zeroing(): void
    {
        $actors = $this->actors();
        $large = $this->recordApprovedUsage($actors['employee'], $this->leaveType('besar'));
        $balance = $this->balance($actors['employee'], [
            'carry_over' => 10,
            'sisa' => 22,
            'sisa_n2' => 4,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
        ]);
        $this->assertSame(
            0,
            app(LeaveBalanceService::class)->availableFor($actors['employee'], 2026, Carbon::parse('2026-08-10')),
        );

        $balance->refresh();
        $this->assertSame(4, $balance->sisa_n2);
        $this->assertSame(6, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(22, $balance->sisa);
        $this->assertDatabaseHas('leave_requests', ['id' => $large->id, 'status' => 'disetujui']);
        $this->assertDatabaseMissing('leave_balance_ledger', ['event_type' => 'leave_deducted']);
    }

    public function test_cuti_besar_non_final_tidak_membuat_saldo_tahunan_efektif_nol(): void
    {
        $actors = $this->actors();
        $this->balance($actors['employee'], [
            'carry_over' => 10,
            'sisa' => 22,
            'sisa_n2' => 4,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
        ]);
        $this->request($actors['employee'], $this->leaveType('besar'), 'menunggu_approval');

        $this->assertSame(
            22,
            app(LeaveBalanceService::class)->availableFor($actors['employee'], 2026, Carbon::parse('2026-08-10')),
        );
    }

    public function test_assert_annual_leave_allowed_menolak_tahun_cuti_besar_final_dan_tidak_menolak_tahun_lain(): void
    {
        $actors = $this->actors();
        $this->recordApprovedUsage($actors['employee'], $this->leaveType('besar'));
        $balances = app(LeaveBalanceService::class);

        $this->assertValidationError(
            fn () => $balances->assertAnnualLeaveAllowed($actors['employee'], 2026),
            'tanggal_mulai',
            'Cuti Tahunan tidak dapat digunakan pada tahun yang sama dengan Cuti Besar yang telah disetujui.',
        );

        $balances->assertAnnualLeaveAllowed($actors['employee'], 2027);
    }
}
