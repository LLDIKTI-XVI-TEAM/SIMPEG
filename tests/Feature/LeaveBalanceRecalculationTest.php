<?php

namespace Tests\Feature;

use App\Actions\Cuti\CorrectManualLeaveUsageAction;
use App\Actions\Cuti\ReconcileAnnualLeaveAnniversaryAction;
use App\Actions\Cuti\RolloverLeaveBalanceAction;
use App\Actions\Cuti\StoreManualLeaveUsageAction;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageDocument;
use App\Models\LeaveUsageExternalApprovalStep;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceReservationService;
use App\Services\Cuti\LeaveBalanceService;
use App\Services\Cuti\LeaveUsageRecordService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeaveBalanceRecalculationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-19 10:00:00');
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_fakta_manual_dan_pengajuan_disetujui_mengurangi_saldo_tepat_sekali(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', 'PNS')->value('id'),
        ]);
        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
        ]);
        $annual = RefJenisCuti::query()->where('code', 'tahunan')->sole();

        $record = app(StoreManualLeaveUsageAction::class)->execute(
            $employee->id,
            [
                'leave_type_id' => $annual->id,
                'leave_request_case_id' => null,
                'tanggal_mulai' => '2026-03-02',
                'tanggal_selesai' => '2026-03-03',
                'alasan' => 'Pemakaian cuti sebelum go-live SIMPEG.',
                'approval_document_number' => null,
                'approval_steps' => $this->approvalSteps(),
            ],
            null,
            $admin,
            $this->requestFor($admin),
        );

        $this->assertSame(LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL, $record->source_type);
        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $record->record_status);
        $this->assertSame([
            LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
        ], LeaveUsageRecord::query()
            ->where('employee_id', $employee->id)
            ->pluck('source_type')
            ->all());
        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'terpakai' => 2,
            'sisa' => 22,
            'sisa_n2' => 4,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
        ]);
        $this->assertSame(2, LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2026)
            ->value('terpakai'));

        $approved = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $annual->id,
            'tanggal_mulai' => '2026-04-06',
            'tanggal_selesai' => '2026-04-07',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Pengajuan operasional yang telah mendapat persetujuan final.',
            'status' => 'disetujui',
        ]);
        $service = app(LeaveUsageRecordService::class);
        $approvedFact = $service->recordApprovedRequest($approved, $admin);
        $ledgerCount = LeaveBalanceLedger::query()->where('employee_id', $employee->id)->count();
        $auditCount = AuditLog::query()->count();

        $this->assertSame($approvedFact->id, $service->recordApprovedRequest($approved, $admin)->id);
        $this->assertSame(LeaveUsageRecord::SOURCE_APPROVED_REQUEST, $approvedFact->source_type);
        $this->assertSame(2, $employee->leaveUsageRecords()->count());
        $this->assertSame($ledgerCount, LeaveBalanceLedger::query()->where('employee_id', $employee->id)->count());
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'terpakai' => 4,
            'sisa' => 20,
            'sisa_n2' => 2,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
        ]);
    }

    #[DataProvider('materialPredecessors')]
    public function test_fakta_pertama_setelah_rollover_mempertahankan_expiry_dan_predecessor_material(
        int $sourceYear,
        int $protectedDays,
        int $expectedRemaining,
        int $expectedN2,
        int $expectedExpired,
    ): void {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', 'PNS')->value('id'),
        ]);
        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
        ]);
        Carbon::setTestNow("{$sourceYear}-08-19 10:00:00");
        $anniversary = app(ReconcileAnnualLeaveAnniversaryAction::class)->execute(now(), 10);
        $this->assertSame(1, $anniversary['processed']);
        $predecessor = $employee->leaveBalances()->where('tahun', $sourceYear)->sole();
        $this->assertSame(24, $predecessor->sisa);
        $sourceSnapshot = $predecessor->getAttributes();

        if ($protectedDays > 0) {
            $dutyRequest = LeaveRequest::query()->create([
                'employee_id' => $employee->id,
                'jenis_cuti_id' => RefJenisCuti::query()->where('code', 'tahunan')->sole()->id,
                'tanggal_mulai' => "{$sourceYear}-12-02",
                'tanggal_selesai' => "{$sourceYear}-12-04",
                'jumlah_hari_kerja' => $protectedDays,
                'alasan' => 'Penugasan dinas akhir tahun.',
                'status' => LeaveRequest::STATUS_DUTY_POSTPONED,
            ]);
            app(LeaveBalanceService::class)->recordDutyPostponement($dutyRequest, $admin, 'Penugasan instansi.');
        }

        foreach (range($sourceYear, 2025) as $rolloverYear) {
            Carbon::setTestNow(($rolloverYear + 1).'-01-01 00:05:00');
            $rollover = app(RolloverLeaveBalanceAction::class)->execute($rolloverYear);
            $this->assertSame(1, $rollover['processed']);
        }
        $opening = $employee->leaveBalances()->where('tahun', 2026)->sole();
        $this->assertSame($expectedExpired, $opening->hangus);
        $this->assertSame($expectedRemaining + 2, $opening->sisa);

        Carbon::setTestNow('2026-08-19 10:00:00');
        app(StoreManualLeaveUsageAction::class)->execute(
            $employee->id,
            [
                'leave_type_id' => RefJenisCuti::query()->where('code', 'tahunan')->sole()->id,
                'tanggal_mulai' => '2026-03-02',
                'tanggal_selesai' => '2026-03-03',
                'alasan' => 'Pencatatan pertama sesudah rollover.',
                'approval_steps' => $this->validManualApprovalPayload(),
            ],
            null,
            $admin,
        );

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'terpakai' => 2,
            'sisa' => $expectedRemaining,
            'sisa_n2' => $expectedN2,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
            'hangus' => $expectedExpired,
        ]);
        $this->assertSame($sourceSnapshot, $predecessor->fresh()->getAttributes());
        $this->assertSame(1, $employee->leaveUsageRecords()->count());
        $this->assertSame(range($sourceYear, 2026), $employee->leaveBalances()->orderBy('tahun')->pluck('tahun')->all());
    }

    /** @return array<string, array{int,int,int,int,int}> */
    public static function materialPredecessors(): array
    {
        return [
            'rollover tanpa fakta' => [2025, 0, 22, 4, 12],
            'hak dinas kedaluwarsa setelah dua rollover' => [2024, 3, 19, 1, 15],
        ];
    }

    public function test_koreksi_tahunan_rollback_seluruh_efek_bila_melanggar_reservasi_aktif(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', 'PNS')->value('id'),
        ]);
        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
        ]);
        $annual = RefJenisCuti::query()->where('code', 'tahunan')->sole();
        $data = [
            'leave_type_id' => $annual->id,
            'tanggal_mulai' => '2026-03-02',
            'tanggal_selesai' => '2026-03-03',
            'alasan' => 'Fakta manual sebelum reservasi.',
            'approval_steps' => $this->validManualApprovalPayload(),
        ];
        $record = app(StoreManualLeaveUsageAction::class)->execute(
            $employee->id,
            $data,
            UploadedFile::fake()->create('awal.pdf', 20, 'application/pdf'),
            $admin,
        );
        $pending = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $annual->id,
            'tanggal_mulai' => '2026-06-01',
            'tanggal_selesai' => '2026-06-29',
            'jumlah_hari_kerja' => 21,
            'alasan' => 'Pengajuan aktif yang telah mengalokasikan hak.',
            'status' => 'menunggu_approval',
        ]);
        $reservations = app(LeaveBalanceReservationService::class);
        $reservations->reserveForNewRequest($pending, $admin);
        $this->assertSame(1, $reservations->availableForSubmission($employee, 2026, Carbon::parse('2026-06-01')));
        $this->assertSame(22, $employee->leaveBalances()->where('tahun', 2026)->sole()->sisa);
        $before = [
            'fact' => $record->getAttributes(),
            'balance' => $employee->leaveBalances()->sole()->getAttributes(),
            'audit' => AuditLog::query()->count(),
            'ledger' => LeaveBalanceLedger::query()->count(),
            'reservations' => LeaveBalanceReservationEvent::query()->count(),
            'approvals' => LeaveUsageExternalApprovalStep::query()->count(),
            'files' => Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX),
        ];

        try {
            app(CorrectManualLeaveUsageAction::class)->execute(
                $record->id,
                [...$data, 'tanggal_selesai' => '2026-03-05', 'correction_reason' => 'Surat baru menambah pemakaian menjadi empat hari.'],
                UploadedFile::fake()->create('koreksi.pdf', 20, 'application/pdf'),
                $admin,
            );
            $this->fail('Koreksi yang menyisakan 20 hari di bawah reservasi 21 hari wajib ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('saldo', $exception->errors());
        }

        $this->assertSame($before['fact'], $record->fresh()->getAttributes());
        $this->assertSame($before['balance'], $employee->leaveBalances()->sole()->getAttributes());
        $this->assertSame($before['audit'], AuditLog::query()->count());
        $this->assertSame($before['ledger'], LeaveBalanceLedger::query()->count());
        $this->assertSame($before['reservations'], LeaveBalanceReservationEvent::query()->count());
        $this->assertSame($before['approvals'], LeaveUsageExternalApprovalStep::query()->count());
        $this->assertSame(1, $reservations->availableForSubmission($employee, 2026, Carbon::parse('2026-06-01')));
        $this->assertSame(1, $employee->leaveUsageRecords()->count());
        $this->assertDatabaseCount('leave_usage_documents', 1);
        $this->assertSame($before['files'], Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX));
    }

    /**
     * @param  list<array{string,string,int}>  $periods
     * @param  array{terpakai:int,sisa:int,sisa_n2:int,sisa_n1:int,sisa_tahun_berjalan:int,hangus:int}  $expected
     */
    #[DataProvider('historicalPeriods')]
    public function test_input_manual_mempertahankan_hak_tahunan_meski_tahun_lain_tanpa_fakta(
        array $periods,
        array $expected,
    ): void {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', 'PNS')->value('id'),
        ]);
        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
        ]);
        $annual = RefJenisCuti::query()->where('code', 'tahunan')->sole();

        foreach ($periods as [$start, $end, $workdays]) {
            $record = app(StoreManualLeaveUsageAction::class)->execute(
                $employee->id,
                [
                    'leave_type_id' => $annual->id,
                    'tanggal_mulai' => $start,
                    'tanggal_selesai' => $end,
                    'alasan' => 'Fakta historis yang telah disetujui di luar sistem.',
                    'approval_steps' => $this->validManualApprovalPayload(),
                ],
                null,
                $admin,
                $this->requestFor($admin),
            );
            $this->assertSame($workdays, $record->workdays);
        }

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2026,
            ...$expected,
        ]);
        $this->assertSame(count($periods), $employee->leaveUsageRecords()->count());
        $this->assertFalse($employee->leaveUsageRecords()->where('workdays', '<=', 0)->exists());
        $firstFactYear = min(array_map(fn (array $period): int => (int) substr($period[0], 0, 4), $periods));
        $this->assertSame(range($firstFactYear, 2026), $employee->leaveBalances()->orderBy('tahun')->pluck('tahun')->all());
    }

    /** @return array<string, array{list<array{string,string,int}>,array{terpakai:int,sisa:int,sisa_n2:int,sisa_n1:int,sisa_tahun_berjalan:int,hangus:int}}> */
    public static function historicalPeriods(): array
    {
        return [
            'hanya tahun berjalan' => [[['2026-03-02', '2026-03-03', 2]], ['terpakai' => 2, 'sisa' => 22, 'sisa_n2' => 4, 'sisa_n1' => 6, 'sisa_tahun_berjalan' => 12, 'hangus' => 6]],
            'hanya N-1 dua hari' => [[['2025-03-03', '2025-03-04', 2]], ['terpakai' => 0, 'sisa' => 18, 'sisa_n2' => 0, 'sisa_n1' => 6, 'sisa_tahun_berjalan' => 12, 'hangus' => 16]],
            'hanya N-1 dua puluh hari' => [[['2025-03-03', '2025-03-28', 20]], ['terpakai' => 0, 'sisa' => 16, 'sisa_n2' => 0, 'sisa_n1' => 4, 'sisa_tahun_berjalan' => 12, 'hangus' => 0]],
            'hanya N-2 dua hari' => [[['2024-03-04', '2024-03-05', 2]], ['terpakai' => 0, 'sisa' => 18, 'sisa_n2' => 0, 'sisa_n1' => 6, 'sisa_tahun_berjalan' => 12, 'hangus' => 12]],
            'hanya N-2 dua puluh hari' => [[['2024-03-04', '2024-03-29', 20]], ['terpakai' => 0, 'sisa' => 18, 'sisa_n2' => 0, 'sisa_n1' => 6, 'sisa_tahun_berjalan' => 12, 'hangus' => 10]],
            'ketiga tahun memiliki fakta' => [[['2024-03-04', '2024-03-29', 20], ['2025-03-03', '2025-03-12', 8], ['2026-03-02', '2026-03-03', 2]], ['terpakai' => 2, 'sisa' => 16, 'sisa_n2' => 0, 'sisa_n1' => 4, 'sisa_tahun_berjalan' => 12, 'hangus' => 2]],
        ];
    }

    /** @return list<array<string, string|null>> */
    private function approvalSteps(): array
    {
        return [
            [
                'step_type' => 'kepala_bagian',
                'approver_source' => 'external_official',
                'approver_employee_id' => null,
                'approver_name' => 'Pejabat Kepala Bagian',
                'approver_position' => 'Kepala Bagian',
                'approver_institution' => 'LLDIKTI Wilayah XVI',
                'acted_on' => '2026-03-04',
                'decision_note' => null,
            ],
            [
                'step_type' => 'pybmc',
                'approver_source' => 'external_official',
                'approver_employee_id' => null,
                'approver_name' => 'Pejabat PYBMC',
                'approver_position' => 'PYBMC',
                'approver_institution' => 'LLDIKTI Wilayah XVI',
                'acted_on' => '2026-03-05',
                'decision_note' => null,
            ],
        ];
    }

    private function requestFor(User $actor): Request
    {
        $request = Request::create('/cuti/pemakaian-manual', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }
}
