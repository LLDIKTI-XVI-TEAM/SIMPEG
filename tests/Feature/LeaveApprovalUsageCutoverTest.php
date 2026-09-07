<?php

namespace Tests\Feature;

use App\Actions\Cuti\ApproveLeaveAction;
use App\Actions\Cuti\ReconcileAnnualLeaveUsageAction;
use App\Jobs\SendSimpegNotificationEmailJob;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\LeaveUsageRecord;
use App\Models\NotificationEventChannel;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\RefNotificationChannel;
use App\Models\SimpegNotification;
use App\Models\StorageRecoveryTask;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceReservationService;
use App\Services\LeaveApprovalService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Validation\ValidationException;
use Mockery;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class LeaveApprovalUsageCutoverTest extends TestCase
{
    use RefreshDatabase;

    private QueueManager $actualQueueManager;

    private QueueFake $queueFake;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-18 10:00:00');
        $this->seed(RbacSeeder::class);
        Storage::fake('local');
        $this->actualQueueManager = app('queue');
        $this->queueFake = Queue::fake();
        $this->enableApprovalNotificationChannels();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_persetujuan_final_tahunan_mengonversi_reservasi_mencatat_satu_fact_dan_replay_tanpa_ledger_legacy(): void
    {
        $fixture = $this->makePendingFinalApproval(true);
        $auditBefore = AuditLog::query()->pluck('id');
        $recalculationBefore = LeaveBalanceLedger::query()
            ->where('employee_id', $fixture['pemohon']->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->count();
        $lockOrder = [];
        DB::listen(function ($query) use (&$lockOrder): void {
            $sql = strtolower((string) $query->sql);

            if (! str_contains($sql, 'for update')) {
                return;
            }

            if (str_contains($sql, 'from "leave_requests"')) {
                $lockOrder[] = 'request';
            } elseif (str_contains($sql, 'from "employees"')) {
                $lockOrder[] = 'employee';
            }
        });

        $approved = $this->approveThroughAction($fixture);

        $requestLock = array_search('request', $lockOrder, true);
        $employeeLock = array_search('employee', $lockOrder, true);
        $this->assertIsInt($requestLock);
        $this->assertIsInt($employeeLock);
        $this->assertLessThan($employeeLock, $requestLock, 'Request existing wajib dikunci sebelum employee.');

        $this->assertSame('disetujui', $approved->status);
        $fact = LeaveUsageRecord::query()->where('leave_request_id', $fixture['request']->id)->sole();
        $this->assertSame(LeaveUsageRecord::SOURCE_APPROVED_REQUEST, $fact->source_type);
        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $fact->record_status);
        $this->assertSame($fixture['jenis']->id, $fact->leave_type_id);
        $this->assertSame(2026, $fact->usage_year);
        $this->assertSame('2026-08-20', $fact->effective_date->toDateString());
        $this->assertSame('2026-08-20', $fact->start_date->toDateString());
        $this->assertSame('2026-08-24', $fact->end_date->toDateString());
        $this->assertSame(3, $fact->workdays);
        $this->assertSame($fixture['approver_user']->id, $fact->recorded_by);

        $balance = LeaveBalance::query()
            ->where('employee_id', $fixture['pemohon']->id)
            ->where('tahun', 2026)
            ->sole();
        $this->assertSame(3, $balance->terpakai);
        $this->assertSame(9, $balance->sisa);
        $this->assertSame(0, $balance->sisa_n2);
        $this->assertSame(0, $balance->sisa_n1);
        $this->assertSame(9, $balance->sisa_tahun_berjalan);
        $this->assertSame($recalculationBefore + 1, LeaveBalanceLedger::query()
            ->where('employee_id', $fixture['pemohon']->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->count());
        $this->assertSame(1, LeaveBalanceLedger::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED)
            ->count());
        $this->assertNoLegacyLedgerEvents($fixture);

        $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->sum('amount'));
        $this->assertDatabaseHas('leave_balance_reservation_events', [
            'leave_request_id' => $fixture['request']->id,
            'event_type' => LeaveBalanceReservationEvent::EVENT_CONVERTED,
            'amount' => -3,
            'created_by' => $fixture['approver_user']->id,
        ]);

        $proof = LeaveProof::query()->where('leave_request_id', $fixture['request']->id)->sole();
        $this->assertSame($fixture['approver_user']->id, $proof->generated_by);
        $this->assertSame('application/pdf', $proof->document_mime);
        $this->assertMatchesRegularExpression(
            '#^leave-proofs/'.$fixture['request']->id.'/[0-9a-f-]{36}\.pdf$#',
            (string) $proof->document_path,
        );
        $this->assertTrue(Storage::disk('local')->exists((string) $proof->document_path));
        $this->assertDatabaseHas('notifications', [
            'user_id' => $fixture['pemohon']->id,
            'type' => 'cuti.disetujui',
        ]);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 1);
        $queuedJob = $this->queueFake->pushed(SendSimpegNotificationEmailJob::class)->sole();
        $this->assertTrue($queuedJob->afterCommit === true);

        $decisionAudit = AuditLog::query()
            ->where('event', 'DECIDE')
            ->where('auditable_type', 'LeaveRequest')
            ->where('auditable_id', $fixture['request']->id)
            ->sole();
        $this->assertSame($fixture['approver_user']->id, $decisionAudit->user_id);
        $this->assertSame($fixture['approver_user']->name, $decisionAudit->user_name);
        $this->assertSame('pimpinan', $decisionAudit->new_values['actor_role']);
        $this->assertSame('198.51.100.44', $decisionAudit->ip_address);
        $this->assertSame('SIMPEG-Approval-Cutover-Test/1.0', $decisionAudit->user_agent);

        $domainAudits = [
            AuditLog::query()
                ->where('event', 'LEAVE_BALANCE_RESERVATION_CONVERTED')
                ->where('auditable_type', 'LeaveBalanceReservationEvent')
                ->where('auditable_id', LeaveBalanceReservationEvent::query()
                    ->where('leave_request_id', $fixture['request']->id)
                    ->where('event_type', LeaveBalanceReservationEvent::EVENT_CONVERTED)
                    ->sole()->id)
                ->sole(),
            AuditLog::query()
                ->where('event', 'CREATE')
                ->where('auditable_type', 'LeaveUsageRecord')
                ->where('auditable_id', $fact->id)
                ->sole(),
            AuditLog::query()
                ->where('event', 'UPDATE')
                ->where('auditable_type', 'LeaveBalance')
                ->where('auditable_id', $balance->id)
                ->latest('created_at')
                ->firstOrFail(),
            AuditLog::query()
                ->where('event', 'LEAVE_PROOF_GENERATED')
                ->where('auditable_type', 'LeaveProof')
                ->where('auditable_id', $proof->id)
                ->sole(),
        ];

        foreach ($domainAudits as $domainAudit) {
            $this->assertSame('198.51.100.44', $domainAudit->ip_address);
            $this->assertSame('SIMPEG-Approval-Cutover-Test/1.0', $domainAudit->user_agent);
        }

        $newAudits = AuditLog::query()->whereNotIn('id', $auditBefore)->get();
        $this->assertNotEmpty($newAudits);
        $auditPayload = $newAudits
            ->map(fn (AuditLog $audit): array => [
                'old' => $audit->old_values,
                'new' => $audit->new_values,
            ])
            ->all();
        $this->assertKeysAbsentRecursively($auditPayload, [
            'document_path',
            'path',
            'disk',
            'stored_name',
            'public_url',
            'alamat_selama_cuti',
            'nomor_telepon',
            'address',
            'phone',
        ]);
        $this->assertStringNotContainsString((string) $proof->document_path, json_encode($auditPayload, JSON_THROW_ON_ERROR));
        $notification = SimpegNotification::query()
            ->where('user_id', $fixture['pemohon']->id)
            ->where('type', 'cuti.disetujui')
            ->sole();
        $privacyPayload = [
            'audits' => $auditPayload,
            'notification' => $notification->data,
            'proof' => $proof->metadata,
        ];
        $this->assertKeysAbsentRecursively($privacyPayload, [
            'alamat_selama_cuti',
            'nomor_telepon',
            'address',
            'phone',
        ]);
        $serializedPrivacySurfaces = json_encode($privacyPayload, JSON_THROW_ON_ERROR).serialize($queuedJob);

        foreach (['Alamat privat pemohon.', '081234567890'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $serializedPrivacySurfaces);
        }

        $effectsBeforeRetry = $this->effectSnapshot($fixture);

        try {
            $this->approveThroughAction(array_merge($fixture, ['request' => $approved->fresh()]));
            $this->fail('Retry entrypoint persetujuan final harus ditolak secara terkendali.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame('disetujui', $fixture['request']->fresh()->status);
        $this->assertSame($effectsBeforeRetry, $this->effectSnapshot($fixture));
    }

    public function test_persetujuan_final_non_tahunan_mencatat_fact_dan_bukti_tanpa_membuat_projection(): void
    {
        $fixture = $this->makePendingFinalApproval(false);

        $approved = $this->approveThroughAction($fixture);

        $this->assertSame('disetujui', $approved->status);
        $fact = LeaveUsageRecord::query()->where('leave_request_id', $fixture['request']->id)->sole();
        $this->assertSame(LeaveUsageRecord::SOURCE_APPROVED_REQUEST, $fact->source_type);
        $this->assertSame($fixture['approver_user']->id, $fact->recorded_by);
        $this->assertSame(3, $fact->workdays);
        $this->assertDatabaseCount('leave_balances', 0);
        $this->assertSame(0, LeaveBalanceLedger::query()
            ->where('employee_id', $fixture['pemohon']->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->count());
        $this->assertSame(1, LeaveBalanceLedger::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED)
            ->count());
        $this->assertNoLegacyLedgerEvents($fixture);

        $proof = LeaveProof::query()->where('leave_request_id', $fixture['request']->id)->sole();
        $this->assertTrue(Storage::disk('local')->exists((string) $proof->document_path));
        $this->assertDatabaseHas('notifications', [
            'user_id' => $fixture['pemohon']->id,
            'type' => 'cuti.disetujui',
        ]);
    }

    public function test_persetujuan_final_mewajibkan_user_yang_sama_dengan_employee_approver_sebelum_mutasi(): void
    {
        foreach (['mismatch', 'missing'] as $case) {
            $fixture = $this->makePendingFinalApproval(false);
            $actingUser = $case === 'mismatch'
                ? User::factory()->create(['employee_id' => Employee::factory()->create()->id])
                : null;
            $before = $this->effectSnapshot($fixture);

            try {
                app(LeaveApprovalService::class)->approve(
                    $fixture['request'],
                    $fixture['approver'],
                    $fixture['request']->steps()->where('status', 'active')->valueOrFail('id'),
                    (int) $fixture['request']->fresh()->revision_version,
                    'Disetujui final.',
                    $actingUser,
                );
                $this->fail("Persetujuan final {$case} seharusnya ditolak sebelum mutasi.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }

            $this->assertSame($before, $this->effectSnapshot($fixture));
        }
    }

    public function test_audit_keputusan_mengambil_status_sebelum_dari_request_yang_sudah_dikunci(): void
    {
        $fixture = $this->makePendingFinalApproval(false);
        $staleRequest = $fixture['request']->fresh();
        LeaveRequest::query()->whereKey($fixture['request']->id)->update(['status' => 'ditangguhkan']);

        $this->approveThroughAction(array_merge($fixture, ['request' => $staleRequest]));

        $decisionAudit = AuditLog::query()
            ->where('event', 'DECIDE')
            ->where('auditable_type', 'LeaveRequest')
            ->where('auditable_id', $fixture['request']->id)
            ->sole();
        $this->assertSame('ditangguhkan', $decisionAudit->old_values['status']);
        $this->assertSame(1, $decisionAudit->old_values['step_order']);
        $this->assertSame('PYBMC', $decisionAudit->old_values['step_label']);
    }

    public function test_kegagalan_simpan_pdf_merollback_status_step_reservasi_fact_projection_ledger_audit_dan_notifikasi(): void
    {
        $fixture = $this->makePendingFinalApproval(true);
        $sentinel = $this->seedSentinelProofFile();
        $realDisk = Storage::disk('local');
        $before = $this->effectSnapshot($fixture, $realDisk);
        $observedAtPut = [];
        $failingDisk = Mockery::mock(FilesystemAdapter::class);
        $this->mockExpectation($failingDisk, 'put')->once()->andReturnUsing(function () use ($fixture, &$observedAtPut): bool {
            $observedAtPut = [
                'request_status' => LeaveRequest::query()->whereKey($fixture['request']->id)->value('status'),
                'step_status' => LeaveRequestStep::query()
                    ->where('leave_request_id', $fixture['request']->id)
                    ->where('is_final', true)
                    ->value('status'),
                'fact_rows' => LeaveUsageRecord::query()->where('leave_request_id', $fixture['request']->id)->count(),
                'projection_used' => (int) LeaveBalance::query()
                    ->where('employee_id', $fixture['pemohon']->id)
                    ->where('tahun', 2026)
                    ->value('terpakai'),
                'proof_rows' => LeaveProof::query()->where('leave_request_id', $fixture['request']->id)->count(),
                'notification_rows' => SimpegNotification::query()
                    ->where('user_id', $fixture['pemohon']->id)
                    ->where('type', 'cuti.disetujui')
                    ->count(),
            ];

            return false;
        });
        $this->mockExpectation($failingDisk, 'exists')->once()->andReturnFalse();
        $this->mockExpectation($failingDisk, 'delete')->zeroOrMoreTimes()->andReturnTrue();
        Storage::shouldReceive('disk')->with('local')->andReturn($failingDisk);

        try {
            $this->approveThroughAction($fixture);
            $this->fail('Persetujuan final seharusnya gagal saat dokumen PDF tidak dapat disimpan.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('dokumen', mb_strtolower($exception->getMessage()));
        }

        $this->assertSame([
            'request_status' => 'disetujui',
            'step_status' => 'approved',
            'fact_rows' => 1,
            'projection_used' => 3,
            'proof_rows' => 1,
            'notification_rows' => 0,
        ], $observedAtPut);
        $this->assertSame($before, $this->effectSnapshot($fixture, $realDisk));
        $this->assertSentinelUnchanged($sentinel, $realDisk);
        Queue::assertNothingPushed();
    }

    public function test_kegagalan_hapus_file_kompensasi_mempertahankan_exception_utama_dan_mencatat_bukti_operasional(): void
    {
        $fixture = $this->makePendingFinalApproval(false);
        $realDisk = Storage::disk('local');
        $domainBefore = $this->domainSnapshot($fixture);
        $filesBefore = $realDisk->allFiles('leave-proofs');
        $diskWithFailedDelete = Mockery::mock(FilesystemAdapter::class);
        $this->mockExpectation($diskWithFailedDelete, 'put')
            ->once()
            ->andReturnUsing(fn (string $path, string $contents): bool => $realDisk->put($path, $contents));
        $this->mockExpectation($diskWithFailedDelete, 'exists')->once()->andReturnTrue();
        $this->mockExpectation($diskWithFailedDelete, 'delete')->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('local')->andReturn($diskWithFailedDelete);
        $dispatcher = clone SimpegNotification::getEventDispatcher();
        SimpegNotification::creating(function (SimpegNotification $notification): void {
            if ($notification->type === 'cuti.disetujui') {
                throw new RuntimeException('Exception utama notifikasi untuk uji kompensasi.');
            }
        });

        try {
            $this->approveThroughAction($fixture);
            $this->fail('Persetujuan final seharusnya gagal pada notifikasi sebelum kompensasi file.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Exception utama notifikasi untuk uji kompensasi.', $exception->getMessage());
        } finally {
            SimpegNotification::setEventDispatcher($dispatcher);
        }

        $this->assertSame($domainBefore, $this->domainSnapshot($fixture));
        $newFiles = array_values(array_diff($realDisk->allFiles('leave-proofs'), $filesBefore));
        $this->assertCount(1, $newFiles);
        $this->assertMatchesRegularExpression(
            '#^leave-proofs/'.$fixture['request']->id.'/[0-9a-f-]{36}\.pdf$#',
            $newFiles[0],
        );
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'operation' => StorageRecoveryTask::OPERATION_DELETE,
            'status' => StorageRecoveryTask::STATUS_PENDING,
            'category' => 'leave_proof',
            'disk' => 'local',
            'path' => $newFiles[0],
            'owner_id' => $fixture['request']->id,
            'attempts' => 1,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_kegagalan_notifikasi_setelah_pdf_terbentuk_merollback_database_dan_menghapus_hanya_file_baru(): void
    {
        $fixture = $this->makePendingFinalApproval(true);
        $sentinel = $this->seedSentinelProofFile();
        $before = $this->effectSnapshot($fixture);
        $dispatcher = clone SimpegNotification::getEventDispatcher();
        $observedTransientState = [];
        SimpegNotification::creating(function (SimpegNotification $notification) use ($fixture, &$observedTransientState): void {
            if ($notification->type !== 'cuti.disetujui') {
                return;
            }

            $observedTransientState = $this->transientFinalState($fixture);

            throw new RuntimeException('Simulasi persist notifikasi final gagal.');
        });

        try {
            $this->approveThroughAction($fixture);
            $this->fail('Persetujuan final seharusnya gagal ketika notifikasi in-app gagal disimpan.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulasi persist notifikasi final gagal.', $exception->getMessage());
        } finally {
            SimpegNotification::setEventDispatcher($dispatcher);
        }

        $this->assertSame($this->expectedTransientFinalState(0), $observedTransientState);
        $this->assertSame($before, $this->effectSnapshot($fixture));
        $this->assertSentinelUnchanged($sentinel);
        Queue::assertNothingPushed();
    }

    public function test_kegagalan_audit_keputusan_setelah_fact_replay_dan_pdf_merollback_seluruh_efek_final(): void
    {
        $fixture = $this->makePendingFinalApproval(true);
        $sentinel = $this->seedSentinelProofFile();
        $before = $this->effectSnapshot($fixture);
        $dispatcher = clone AuditLog::getEventDispatcher();
        $queueFake = $this->queueFake;
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => config('database.default'),
        ]);
        $this->actualQueueManager->setDefaultDriver('database');
        Queue::swap($this->actualQueueManager);
        $observedLateState = [];
        AuditLog::creating(function (AuditLog $audit) use ($fixture, &$observedLateState): void {
            if ($audit->event !== 'DECIDE' || $audit->auditable_type !== 'LeaveRequest' || $audit->auditable_id !== $fixture['request']->id) {
                return;
            }

            $observedLateState = $this->transientFinalState($fixture, true);

            throw new RuntimeException('Simulasi audit keputusan final gagal.');
        });

        try {
            $this->approveThroughAction($fixture);
            $this->fail('Persetujuan final seharusnya gagal ketika audit keputusan tidak dapat ditulis.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulasi audit keputusan final gagal.', $exception->getMessage());
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
            Queue::swap($queueFake);
        }

        $this->assertSame($this->expectedTransientFinalState(1), $observedLateState);
        $this->assertSame($before, $this->effectSnapshot($fixture));
        $this->assertSentinelUnchanged($sentinel);
        $this->assertDatabaseCount('jobs', 0);
        Queue::assertNothingPushed();
    }

    /**
     * @return array{
     *     pemohon: Employee,
     *     pemohon_user: User,
     *     approver: Employee,
     *     approver_user: User,
     *     jenis: RefJenisCuti,
     *     request: LeaveRequest
     * }
     */
    private function makePendingFinalApproval(bool $annual): array
    {
        $fixtureId = (string) Str::uuid();
        $pppk = RefJenisPegawai::query()->firstOrCreate(['nama' => 'PPPK']);
        $pemohon = Employee::factory()->create([
            'nama_lengkap' => $annual ? 'Pemohon Cuti Tahunan' : 'Pemohon Cuti Sakit',
            'email' => "pemohon-{$fixtureId}@example.test",
            'jenis_pegawai_id' => $pppk->id,
            'tanggal_akhir_kontrak' => null,
        ]);
        $pemohonUser = User::factory()->pegawai()->create(['employee_id' => $pemohon->id]);
        $approver = Employee::factory()->create(['nama_lengkap' => 'Pejabat Final PYBMC']);
        $approverUser = User::factory()->create([
            'name' => 'User Pejabat Final',
            'role' => 'pimpinan',
            'employee_id' => $approver->id,
        ]);
        $jenis = RefJenisCuti::query()->firstOrCreate(
            ['code' => $annual ? 'tahunan' : 'sakit'],
            [
                'nama' => $annual ? 'Cuti Tahunan' : 'Cuti Sakit',
                'mengurangi_saldo_tahunan' => $annual,
                'khusus_pns' => false,
            ],
        );

        if ($annual) {
            Appointment::create([
                'employee_id' => $pemohon->id,
                'jenis_pengangkatan' => 'PPPK',
                'tmt_pengangkatan' => '2020-01-01',
            ]);

            $admin = User::factory()->adminKepegawaian()->create();
            app(ReconcileAnnualLeaveUsageAction::class)->execute(
                $pemohon->id,
                [
                    'balance_year' => 2026,
                    'usage_n2' => 0,
                    'usage_n1' => 0,
                    'usage_current' => 0,
                    'administrative_note' => 'Rekonsiliasi fixture persetujuan final.',
                ],
                $admin,
                $this->approvalRequest($admin),
            );
        }

        $request = LeaveRequest::query()->create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-20',
            'tanggal_selesai' => '2026-08-24',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga yang telah diverifikasi.',
            'alamat_selama_cuti' => 'Alamat privat pemohon.',
            'nomor_telepon' => '081234567890',
            'status' => 'menunggu_approval',
        ]);
        $activeStep = LeaveRequestStep::query()->create([
            'leave_request_id' => $request->id,
            'step_order' => 1,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        if ($annual) {
            app(LeaveBalanceReservationService::class)->reserveForNewRequest(
                $request->fresh('jenisCuti'),
                $pemohonUser,
            );
        }

        return [
            'pemohon' => $pemohon,
            'pemohon_user' => $pemohonUser,
            'approver' => $approver,
            'approver_user' => $approverUser,
            'jenis' => $jenis,
            'request' => $request->fresh(),
            'active_step_id' => $activeStep->id,
        ];
    }

    /**
     * @param  array{request: LeaveRequest, approver: Employee, approver_user: User, active_step_id: string}  $fixture
     */
    private function approveThroughAction(array $fixture): LeaveRequest
    {
        return app(ApproveLeaveAction::class)->execute(
            $fixture['request']->fresh(),
            $fixture['approver'],
            $fixture['active_step_id'],
            (int) $fixture['request']->fresh()->revision_version,
            'Disetujui final melalui SIMPEG.',
            $this->approvalRequest($fixture['approver_user']),
        );
    }

    private function approvalRequest(User $actor): Request
    {
        $request = Request::create('/cuti/approval', 'POST', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.44',
            'HTTP_USER_AGENT' => 'SIMPEG-Approval-Cutover-Test/1.0',
        ]);
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }

    private function enableApprovalNotificationChannels(): void
    {
        foreach (['in_app', 'email'] as $code) {
            $channel = RefNotificationChannel::query()->where('code', $code)->firstOrFail();
            NotificationEventChannel::query()->updateOrCreate([
                'event_key' => 'cuti.disetujui',
                'notification_channel_id' => $channel->id,
            ], [
                'is_enabled' => true,
            ]);
        }
    }

    /**
     * @param  array{pemohon: Employee, request: LeaveRequest}  $fixture
     * @return array<string, mixed>
     */
    private function domainSnapshot(array $fixture): array
    {
        return [
            'request' => LeaveRequest::query()->whereKey($fixture['request']->id)->firstOrFail()->toArray(),
            'steps' => LeaveRequestStep::query()->where('leave_request_id', $fixture['request']->id)->orderBy('step_order')->get()->toArray(),
            'approvals' => LeaveApproval::query()->where('leave_request_id', $fixture['request']->id)->orderBy('id')->get()->toArray(),
            'facts' => LeaveUsageRecord::query()->where('employee_id', $fixture['pemohon']->id)->orderBy('id')->get()->toArray(),
            'balances' => LeaveBalance::query()->where('employee_id', $fixture['pemohon']->id)->orderBy('tahun')->get()->toArray(),
            'ledger' => LeaveBalanceLedger::query()->where('employee_id', $fixture['pemohon']->id)->orderBy('id')->get()->toArray(),
            'reservations' => LeaveBalanceReservationEvent::query()->where('employee_id', $fixture['pemohon']->id)->orderBy('id')->get()->toArray(),
            'proofs' => LeaveProof::query()->where('leave_request_id', $fixture['request']->id)->orderBy('id')->get()->toArray(),
            'audit' => AuditLog::query()->orderBy('id')->get()->toArray(),
            'notifications' => SimpegNotification::query()->where('user_id', $fixture['pemohon']->id)->orderBy('id')->get()->toArray(),
        ];
    }

    /**
     * Snapshot ini mengunci seluruh efek yang dapat terlihat di luar transaksi, termasuk
     * byte file privat dan jumlah job email, agar retry atau kegagalan tidak lolos parsial.
     *
     * @param  array{pemohon: Employee, request: LeaveRequest}  $fixture
     * @return array<string, mixed>
     */
    private function effectSnapshot(array $fixture, ?FilesystemAdapter $disk = null): array
    {
        $disk ??= Storage::disk('local');
        $files = collect($disk->allFiles('leave-proofs'))
            ->sort()
            ->mapWithKeys(fn (string $path): array => [$path => $disk->get($path)])
            ->all();

        return array_merge($this->domainSnapshot($fixture), [
            'private_files' => $files,
            'queued_email_jobs' => $this->queueFake->pushed(SendSimpegNotificationEmailJob::class)->count(),
        ]);
    }

    /**
     * Membaca keadaan sementara tepat sebelum fault disuntikkan. Setiap flag mewakili
     * satu efek final yang wajib ikut rollback bila tahap terakhir gagal.
     *
     * @param  array{pemohon: Employee, request: LeaveRequest}  $fixture
     * @return array<string, int|string|bool|null>
     */
    private function transientFinalState(array $fixture, bool $databaseQueueProbe = false): array
    {
        $fact = LeaveUsageRecord::query()->where('leave_request_id', $fixture['request']->id)->first();
        $proof = LeaveProof::query()->where('leave_request_id', $fixture['request']->id)->first();
        $proofPath = $proof?->document_path;
        $converted = LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->where('event_type', LeaveBalanceReservationEvent::EVENT_CONVERTED)
            ->first();

        return [
            'request_status' => LeaveRequest::query()->whereKey($fixture['request']->id)->value('status'),
            'step_status' => LeaveRequestStep::query()
                ->where('leave_request_id', $fixture['request']->id)
                ->where('is_final', true)
                ->value('status'),
            'approval_rows' => LeaveApproval::query()->where('leave_request_id', $fixture['request']->id)->count(),
            'reservation_net' => (int) LeaveBalanceReservationEvent::query()
                ->where('leave_request_id', $fixture['request']->id)
                ->sum('amount'),
            'converted_rows' => $converted === null ? 0 : 1,
            'fact_rows' => $fact === null ? 0 : 1,
            'usage_ledger_rows' => LeaveBalanceLedger::query()
                ->where('leave_request_id', $fixture['request']->id)
                ->where('event_type', LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED)
                ->count(),
            'projection_used' => (int) LeaveBalance::query()
                ->where('employee_id', $fixture['pemohon']->id)
                ->where('tahun', 2026)
                ->value('terpakai'),
            'projection_remaining' => (int) LeaveBalance::query()
                ->where('employee_id', $fixture['pemohon']->id)
                ->where('tahun', 2026)
                ->value('sisa'),
            'proof_rows' => $proof === null ? 0 : 1,
            'proof_path_private' => is_string($proofPath)
                && preg_match('#^leave-proofs/'.$fixture['request']->id.'/[0-9a-f-]{36}\.pdf$#', $proofPath) === 1,
            'proof_file_exists' => is_string($proofPath) && Storage::disk('local')->exists($proofPath),
            'reservation_audit_rows' => $converted === null ? 0 : AuditLog::query()
                ->where('event', 'LEAVE_BALANCE_RESERVATION_CONVERTED')
                ->where('auditable_id', $converted->id)
                ->count(),
            'fact_audit_rows' => $fact === null ? 0 : AuditLog::query()
                ->where('event', 'CREATE')
                ->where('auditable_type', 'LeaveUsageRecord')
                ->where('auditable_id', $fact->id)
                ->count(),
            'proof_audit_rows' => $proof === null ? 0 : AuditLog::query()
                ->where('event', 'LEAVE_PROOF_GENERATED')
                ->where('auditable_id', $proof->id)
                ->count(),
            'legacy_ledger_rows' => LeaveBalanceLedger::query()
                ->where('employee_id', $fixture['pemohon']->id)
                ->whereIn('event_type', $this->legacyLedgerEvents())
                ->count(),
            'notification_rows' => SimpegNotification::query()
                ->where('user_id', $fixture['pemohon']->id)
                ->where('type', 'cuti.disetujui')
                ->count(),
            'queued_email_jobs' => $databaseQueueProbe
                ? DB::table('jobs')->count()
                : $this->queueFake->pushed(SendSimpegNotificationEmailJob::class)->count(),
        ];
    }

    /** @return array<string, int|string|bool> */
    private function expectedTransientFinalState(int $notificationRows): array
    {
        return [
            'request_status' => 'disetujui',
            'step_status' => 'approved',
            'approval_rows' => 1,
            'reservation_net' => 0,
            'converted_rows' => 1,
            'fact_rows' => 1,
            'usage_ledger_rows' => 1,
            'projection_used' => 3,
            'projection_remaining' => 9,
            'proof_rows' => 1,
            'proof_path_private' => true,
            'proof_file_exists' => true,
            'reservation_audit_rows' => 1,
            'fact_audit_rows' => 1,
            'proof_audit_rows' => 1,
            'legacy_ledger_rows' => 0,
            'notification_rows' => $notificationRows,
            'queued_email_jobs' => 0,
        ];
    }

    /** @return array{path:string,contents:string} */
    private function seedSentinelProofFile(): array
    {
        $sentinel = [
            'path' => 'leave-proofs/sentinel/existing-proof.pdf',
            'contents' => 'PDF-SENTINEL-YANG-TIDAK-BOLEH-DIHAPUS',
        ];
        $this->assertTrue(Storage::disk('local')->put($sentinel['path'], $sentinel['contents']));

        return $sentinel;
    }

    /** @param array{path:string,contents:string} $sentinel */
    private function assertSentinelUnchanged(array $sentinel, ?FilesystemAdapter $disk = null): void
    {
        $disk ??= Storage::disk('local');
        $this->assertTrue($disk->exists($sentinel['path']));
        $this->assertSame($sentinel['contents'], $disk->get($sentinel['path']));
    }

    /** @param array{pemohon: Employee, request: LeaveRequest} $fixture */
    private function assertNoLegacyLedgerEvents(array $fixture): void
    {
        $this->assertSame(0, LeaveBalanceLedger::query()
            ->where('employee_id', $fixture['pemohon']->id)
            ->whereIn('event_type', $this->legacyLedgerEvents())
            ->count());
    }

    /** @return list<string> */
    private function legacyLedgerEvents(): array
    {
        return [
            'leave_deducted',
            'manual_adjustment',
            'opening_balance_set',
        ];
    }

    /**
     * @param  array<mixed>  $payload
     * @param  list<string>  $forbiddenKeys
     */
    private function assertKeysAbsentRecursively(array $payload, array $forbiddenKeys): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $this->assertNotContains($key, $forbiddenKeys, "Kunci privat {$key} tidak boleh masuk audit.");
            }

            if (is_array($value)) {
                $this->assertKeysAbsentRecursively($value, $forbiddenKeys);
            }
        }
    }

    private function mockExpectation(MockInterface $mock, string $method): Expectation|CompositeExpectation
    {
        $expectation = $mock->shouldReceive($method);

        if (! $expectation instanceof Expectation && ! $expectation instanceof CompositeExpectation) {
            throw new RuntimeException("Mockery tidak membuat expectation yang valid untuk {$method}.");
        }

        return $expectation;
    }
}
