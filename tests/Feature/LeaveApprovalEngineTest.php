<?php

namespace Tests\Feature;

use App\Actions\Cuti\PostponeLeaveAction;
use App\Actions\Cuti\RolloverLeaveBalanceAction;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\Cuti\LeaveUsageReconciliationService;
use App\Services\LeaveApprovalService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Menguji mesin persetujuan cuti berbasis snapshot step.
 * Fokus: otorisasi person-based dari step aktif, perpindahan status dinamis,
 * penundaan pada step yang sama, dan replay saldo final yang hanya berlaku untuk cuti tahunan.
 */
class LeaveApprovalEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    private function service(): LeaveApprovalService
    {
        return app(LeaveApprovalService::class);
    }

    /**
     * @return array{
     *     employee: Employee,
     *     kepala_bagian: Employee,
     *     kepala_bagian_user: User,
     *     pybmc: Employee,
     *     pybmc_user: User
     * }
     */
    private function makePemohon(bool $eligible = false): array
    {
        $kepalaBagian = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $employee = Employee::factory()->create();

        if ($eligible) {
            Appointment::create([
                'employee_id' => $employee->id,
                'jenis_pengangkatan' => 'PNS',
                'tmt_pengangkatan' => '2020-01-01',
            ]);
        }

        return [
            'employee' => $employee,
            'kepala_bagian' => $kepalaBagian,
            'kepala_bagian_user' => User::factory()->kepalaBagian()->create(['employee_id' => $kepalaBagian->id]),
            'pybmc' => $pybmc,
            'pybmc_user' => User::factory()->pimpinan()->create(['employee_id' => $pybmc->id]),
        ];
    }

    /** Membuat fakta pemakaian tahunan existing tanpa memakai event debit legacy. */
    private function recordAnnualUsageFact(
        Employee $employee,
        RefJenisCuti $leaveType,
        User $actor,
        int $workdays,
    ): LeaveUsageRecord {
        $record = LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'usage_year' => 2026,
            'effective_date' => '2026-02-02',
            'start_date' => '2026-02-02',
            'end_date' => '2026-02-02',
            'workdays' => $workdays,
            'administrative_note' => 'Fixture pemakaian tahunan existing.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $actor->id,
        ]);

        return $this->attachValidManualApprovalSnapshot($record);
    }

    private function jenisCuti(string $nama): RefJenisCuti
    {
        return RefJenisCuti::create([
            'nama' => $nama,
            'code' => $nama === 'Cuti Tahunan' ? 'tahunan' : str($nama)->slug('_')->toString(),
            'mengurangi_saldo_tahunan' => $nama === 'Cuti Tahunan',
            'khusus_pns' => false,
        ]);
    }

    /**
     * Membuat pengajuan cuti dengan snapshot step aktif pertama.
     *
     * @param  list<Employee>  $approvers
     */
    private function makeRequest(Employee $employee, RefJenisCuti $jenis, array $approvers, int $hari = 3): LeaveRequest
    {
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-08',
            'jumlah_hari_kerja' => $hari,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
        ]);

        $chain = LeaveApprovalChain::create([
            'employee_id' => $employee->id,
            'name' => 'Snapshot test chain',
            'effective_from' => '2026-01-01',
        ]);

        foreach ($approvers as $index => $approver) {
            $order = $index + 1;
            $isFinal = $order === count($approvers);
            $stepType = $isFinal ? 'pybmc' : 'kepala_bagian';
            $roleLabel = $isFinal ? 'PYBMC' : 'Kepala Bagian';
            $status = $order === 1 ? 'active' : 'pending';

            $chain->steps()->create([
                'step_order' => $order,
                'step_type' => $stepType,
                'role_label' => $roleLabel,
                'approver_employee_id' => $approver->id,
                'is_final' => $isFinal,
            ]);

            LeaveRequestStep::create([
                'leave_request_id' => $leave->id,
                'step_order' => $order,
                'step_type' => $stepType,
                'role_label' => $roleLabel,
                'approver_employee_id' => $approver->id,
                'status' => $status,
                'is_final' => $isFinal,
            ]);
        }

        return $leave;
    }

    public function test_snapshot_approve_menyetujui_penuh_dan_mereplay_saldo_dari_fact_tahunan(): void
    {
        $pemohon = $this->makePemohon(eligible: true);
        $jenis = $this->jenisCuti('Cuti Tahunan');
        LeaveBalance::create([
            'employee_id' => $pemohon['employee']->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
        ]);

        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 3);

        $this->service()->approve($cuti, $pemohon['kepala_bagian'], $this->activeStepId($cuti), null, $pemohon['kepala_bagian_user']);
        $this->assertSame('menunggu_approval', $cuti->fresh()->status);
        $this->assertDatabaseHas('leave_request_steps', ['leave_request_id' => $cuti->id, 'step_order' => 1, 'status' => 'approved']);
        $this->assertDatabaseHas('leave_request_steps', ['leave_request_id' => $cuti->id, 'step_order' => 2, 'status' => 'active']);

        $this->service()->approve($cuti->fresh(), $pemohon['pybmc'], $this->activeStepId($cuti), null, $pemohon['pybmc_user']);
        $this->assertSame('disetujui', $cuti->fresh()->status);

        $balance = LeaveBalance::where('employee_id', $pemohon['employee']->id)->where('tahun', 2026)->first();
        $this->assertSame(3, $balance->terpakai);
        $this->assertSame(9, $balance->sisa);
    }

    public function test_perubahan_dengan_token_tahap_lama_ditolak_tanpa_menyentuh_tahap_berikutnya(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['kepala_bagian']], 2);
        $stepPertamaId = $cuti->steps()->where('step_order', 1)->value('id');

        $this->service()->approve($cuti, $pemohon['kepala_bagian'], $stepPertamaId, null, $pemohon['kepala_bagian_user']);
        $jumlahApproval = $cuti->approvals()->count();

        try {
            $this->service()->requestChanges($cuti->fresh(), $pemohon['kepala_bagian'], $stepPertamaId, 'Token lama tidak boleh memutus tahap berikutnya.');
            $this->fail('Keputusan dengan token tahap lama wajib ditolak.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Tahap persetujuan telah berubah. Muat ulang halaman sebelum mengirim keputusan.'],
                $exception->errors()['active_step_id'] ?? null,
            );
        }

        $this->assertSame($jumlahApproval, $cuti->approvals()->count());
        $this->assertDatabaseHas('leave_request_steps', ['leave_request_id' => $cuti->id, 'step_order' => 2, 'status' => 'active']);
    }

    public function test_final_approval_mencatat_fact_dan_mereplay_bucket_tanpa_double_debit(): void
    {
        $pemohon = $this->makePemohon(eligible: true);
        $jenis = $this->jenisCuti('Cuti Tahunan');
        LeaveBalance::create([
            'employee_id' => $pemohon['employee']->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 6,
            'terpakai' => 0,
            'sisa' => 18,
            'sisa_n2' => 2,
            'sisa_n1' => 4,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);

        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 8);

        $this->service()->approve($cuti, $pemohon['kepala_bagian'], $this->activeStepId($cuti), null, $pemohon['kepala_bagian_user']);
        $this->service()->approve($cuti->fresh(), $pemohon['pybmc'], $this->activeStepId($cuti), null, $pemohon['pybmc_user']);

        $balance = LeaveBalance::where('employee_id', $pemohon['employee']->id)->where('tahun', 2026)->firstOrFail();
        $this->assertSame(8, $balance->terpakai);
        $this->assertSame(4, $balance->sisa);
        $this->assertSame(0, $balance->sisa_n2);
        $this->assertSame(0, $balance->sisa_n1);
        $this->assertSame(4, $balance->sisa_tahun_berjalan);
        $this->assertSame(8, $balance->terpakai_tahun_berjalan);
        $fact = LeaveUsageRecord::query()->where('leave_request_id', $cuti->id)->sole();
        $this->assertSame(LeaveUsageRecord::SOURCE_APPROVED_REQUEST, $fact->source_type);
        $this->assertSame(8, $fact->workdays);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'leave_request_id' => $cuti->id,
            'event_type' => LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED,
            'amount' => 8,
            'dedup_key' => "usage:usage_fact_recorded:{$fact->id}",
        ]);
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'leave_request_id' => $cuti->id,
            'event_type' => 'leave_deducted',
        ]);
    }

    public function test_penundaan_workflow_tidak_mencatat_mutasi_saldo(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Tahunan');
        LeaveBalance::create([
            'employee_id' => $pemohon['employee']->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
        ]);
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 3);

        $this->service()->postpone($cuti, $pemohon['kepala_bagian'], $this->activeStepId($cuti), 'Menunggu pengganti tugas.');

        $this->assertSame('ditangguhkan', $cuti->fresh()->status);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
        $balance = LeaveBalance::where('employee_id', $pemohon['employee']->id)->where('tahun', 2026)->firstOrFail();
        $this->assertSame(0, $balance->terpakai);
        $this->assertSame(12, $balance->sisa);
    }

    public function test_perlu_perubahan_mengembalikan_pengajuan_ke_pemohon(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 2);

        $this->service()->requestChanges($cuti, $pemohon['kepala_bagian'], $this->activeStepId($cuti), 'Tanggal cuti perlu diperbaiki.');

        $this->assertSame('perlu_perubahan', $cuti->fresh()->status);
        $this->assertDatabaseHas('leave_request_steps', ['leave_request_id' => $cuti->id, 'step_order' => 1, 'status' => 'active']);
        $this->assertDatabaseHas('leave_approvals', ['leave_request_id' => $cuti->id, 'stage' => 1, 'action' => 'REQUEST_CHANGES']);
    }

    public function test_perlu_perubahan_tidak_bisa_disetujui_sebelum_dikirim_ulang(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 2);

        $this->service()->requestChanges($cuti, $pemohon['kepala_bagian'], $this->activeStepId($cuti), 'Tanggal cuti perlu diperbaiki.');

        $this->expectException(ValidationException::class);
        $this->service()->approve($cuti->fresh(), $pemohon['kepala_bagian'], $this->activeStepId($cuti), null, $pemohon['kepala_bagian_user']);
    }

    public function test_tidak_disetujui_menutup_pengajuan_tanpa_memotong_saldo(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Tahunan');
        LeaveBalance::create([
            'employee_id' => $pemohon['employee']->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
        ]);
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 2);

        $this->service()->decline($cuti, $pemohon['kepala_bagian'], $this->activeStepId($cuti), 'Dokumen pendukung tidak sesuai.');

        $this->assertSame('tidak_disetujui', $cuti->fresh()->status);
        $this->assertDatabaseHas('leave_request_steps', ['leave_request_id' => $cuti->id, 'step_order' => 1, 'status' => 'tidak_disetujui']);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $cuti->id,
            'step_order' => 2,
            'status' => 'skipped',
            'skipped_reason' => 'request_not_approved',
        ]);
        $this->assertDatabaseHas('leave_approvals', ['leave_request_id' => $cuti->id, 'stage' => 1, 'action' => 'NOT_APPROVED']);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
        $balance = LeaveBalance::where('employee_id', $pemohon['employee']->id)->where('tahun', 2026)->first();
        $this->assertSame(0, $balance->terpakai);
        $this->assertSame(12, $balance->sisa);
    }

    public function test_tidak_disetujui_hanya_dapat_diputuskan_approver_snapshot_aktif(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 2);

        $this->expectException(AuthorizationException::class);
        $this->service()->decline($cuti, $pemohon['pybmc'], $this->activeStepId($cuti), 'Dokumen pendukung tidak sesuai.');
    }

    public function test_penundaan_lalu_disetujui_kembali_oleh_approver_yang_sama(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 2);

        $this->service()->postpone($cuti, $pemohon['kepala_bagian'], $this->activeStepId($cuti), 'Menunggu pengganti tugas.');
        $this->assertSame('ditangguhkan', $cuti->fresh()->status);
        $this->assertSame(1, $this->service()->pendingStage($cuti->fresh()));

        $this->service()->approve($cuti->fresh(), $pemohon['kepala_bagian'], $this->activeStepId($cuti), null, $pemohon['kepala_bagian_user']);
        $this->assertDatabaseHas('leave_request_steps', ['leave_request_id' => $cuti->id, 'step_order' => 2, 'status' => 'active']);
    }

    public function test_generic_postpone_preserves_workflow_and_never_creates_statutory_protection(): void
    {
        $pemohon = $this->makePemohon();
        $jenisPns = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $pemohon['employee']->forceFill(['jenis_pegawai_id' => $jenisPns->id])->save();
        Appointment::create([
            'employee_id' => $pemohon['employee']->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-GENERIC-POSTPONE',
            'tanggal_sk' => '2020-01-01',
        ]);
        $jenis = $this->jenisCuti('Cuti Tahunan');
        $reconciliationActor = User::factory()->adminKepegawaian()->create();
        app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $pemohon['employee'],
            2026,
            [2024 => 12, 2025 => 12, 2026 => 0],
            now(config('app.timezone')),
            'Fixture fakta pemakaian untuk regresi penundaan generik.',
            $reconciliationActor,
        );
        $balance = LeaveBalance::query()
            ->where('employee_id', $pemohon['employee']->id)
            ->where('tahun', 2026)
            ->sole();
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 3);
        $activeStep = $cuti->steps()->where('status', 'active')->sole();
        LeaveBalanceReservationEvent::create([
            'employee_id' => $pemohon['employee']->id,
            'leave_request_id' => $cuti->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => 'reserved',
            'amount' => 3,
            'dedup_key' => "leave_reservation:{$cuti->id}:reserved",
        ]);

        app(PostponeLeaveAction::class)->execute(
            $cuti,
            $pemohon['kepala_bagian'],
            $activeStep->id,
            'Menunggu pengganti tugas.',
            Request::create('/cuti/test', 'POST'),
        );

        $this->assertSame('ditangguhkan', $cuti->fresh()->status);
        $this->assertSame('active', $activeStep->fresh()->status);
        $this->assertSame($pemohon['kepala_bagian']->id, $activeStep->fresh()->approver_employee_id);
        $this->assertSame(3, (int) DB::table('leave_balance_reservation_events')->where('leave_request_id', $cuti->id)->sum('amount'));
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'leave_request_id' => $cuti->id,
            'event_type' => LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $pemohon['employee']->id,
            'type' => 'cuti.ditunda',
        ]);

        $this->service()->approve($cuti->fresh(), $pemohon['kepala_bagian'], $this->activeStepId($cuti), null, $pemohon['kepala_bagian_user']);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $cuti->id,
            'step_order' => 2,
            'status' => 'active',
        ]);

        $testNow = Carbon::getTestNow();

        try {
            Carbon::setTestNow('2027-01-01 00:05:00');
            app(RolloverLeaveBalanceAction::class)->execute(2026);
        } finally {
            Carbon::setTestNow($testNow);
        }
        $carry = LeaveBalanceLedger::query()
            ->where('employee_id', $pemohon['employee']->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED)
            ->sole();
        $this->assertSame(6, $carry->amount);
        $this->assertSame(6, $carry->metadata['n1']);
        $this->assertSame(1, SimpegNotification::query()->where('type', 'cuti.ditunda')->count());
    }

    public function test_approver_salah_step_ditolak(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 2);

        $this->expectException(AuthorizationException::class);
        $this->service()->approve($cuti, $pemohon['pybmc'], $this->activeStepId($cuti), null, $pemohon['pybmc_user']);
    }

    public function test_cuti_non_tahunan_tidak_memotong_saldo(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');
        LeaveBalance::create([
            'employee_id' => $pemohon['employee']->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
        ]);

        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 2);

        $this->service()->approve($cuti, $pemohon['kepala_bagian'], $this->activeStepId($cuti), null, $pemohon['kepala_bagian_user']);
        $this->service()->approve($cuti->fresh(), $pemohon['pybmc'], $this->activeStepId($cuti), null, $pemohon['pybmc_user']);
        $this->assertSame('disetujui', $cuti->fresh()->status);

        $balance = LeaveBalance::where('employee_id', $pemohon['employee']->id)->where('tahun', 2026)->first();
        $this->assertSame(0, $balance->terpakai);
        $this->assertSame(12, $balance->sisa);
    }

    public function test_saldo_tidak_cukup_saat_final_menggagalkan_persetujuan(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Tahunan');
        LeaveBalance::create([
            'employee_id' => $pemohon['employee']->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 11,
            'sisa' => 1,
        ]);
        $this->recordAnnualUsageFact(
            $pemohon['employee'],
            $jenis,
            $pemohon['kepala_bagian_user'],
            11,
        );

        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 3);
        $this->service()->approve($cuti, $pemohon['kepala_bagian'], $this->activeStepId($cuti), null, $pemohon['kepala_bagian_user']);

        try {
            $this->service()->approve($cuti->fresh(), $pemohon['pybmc'], $this->activeStepId($cuti), null, $pemohon['pybmc_user']);
            $this->fail('Persetujuan final seharusnya gagal karena saldo tidak cukup.');
        } catch (ValidationException $e) {
            $this->assertSame('menunggu_approval', $cuti->fresh()->status);
            $this->assertDatabaseHas('leave_request_steps', ['leave_request_id' => $cuti->id, 'step_order' => 2, 'status' => 'active']);
            $this->assertDatabaseMissing('leave_balance_ledger', ['leave_request_id' => $cuti->id]);
            $balance = LeaveBalance::where('employee_id', $pemohon['employee']->id)->where('tahun', 2026)->first();
            $this->assertSame(11, $balance->terpakai);
            $this->assertSame(1, $balance->sisa);
        }
    }

    public function test_final_cuti_besar_gagal_jika_cuti_tahunan_tahun_sama_sudah_dipakai(): void
    {
        $pemohon = $this->makePemohon();
        $jenisPns = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $pemohon['employee']->forceFill(['jenis_pegawai_id' => $jenisPns->id])->save();
        Appointment::create([
            'employee_id' => $pemohon['employee']->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2018-01-01',
            'no_sk' => 'SK-APPROVAL-RULE-5',
            'tanggal_sk' => '2018-01-01',
        ]);
        $jenisBesar = RefJenisCuti::firstOrCreate(
            ['code' => 'besar'],
            ['nama' => 'Cuti Besar', 'mengurangi_saldo_tahunan' => false, 'khusus_pns' => true],
        );
        LeaveBalance::create([
            'employee_id' => $pemohon['employee']->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 2,
            'sisa' => 10,
            'sisa_tahun_berjalan' => 10,
            'terpakai_tahun_berjalan' => 2,
        ]);
        $jenisTahunan = $this->jenisCuti('Cuti Tahunan');
        $this->recordAnnualUsageFact(
            $pemohon['employee'],
            $jenisTahunan,
            $pemohon['kepala_bagian_user'],
            2,
        );
        $cuti = $this->makeRequest($pemohon['employee'], $jenisBesar, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 20);

        $this->service()->approve($cuti, $pemohon['kepala_bagian'], $this->activeStepId($cuti), null, $pemohon['kepala_bagian_user']);

        try {
            $this->service()->approve($cuti->fresh(), $pemohon['pybmc'], $this->activeStepId($cuti), null, $pemohon['pybmc_user']);
            $this->fail('Persetujuan final cuti besar seharusnya gagal setelah cuti tahunan dipakai di tahun yang sama.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
            $this->assertSame(
                'Cuti Besar tidak dapat disetujui karena Cuti Tahunan tahun yang sama sudah digunakan.',
                $e->errors()['status'][0],
            );
            $this->assertSame('menunggu_approval', $cuti->fresh()->status);
            $this->assertDatabaseHas('leave_request_steps', ['leave_request_id' => $cuti->id, 'step_order' => 2, 'status' => 'active']);
        }
    }

    public function test_approve_tanpa_step_aktif_memberi_pesan_konfigurasi(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [], 2);

        $otherApprover = Employee::factory()->create();
        $otherUser = User::factory()->create(['employee_id' => $otherApprover->id]);

        try {
            $this->service()->approve($cuti, $otherApprover, '00000000-0000-4000-8000-000000000001', null, $otherUser);
            $this->fail('Persetujuan seharusnya gagal karena pengajuan belum memiliki step aktif.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Pengajuan cuti ini belum memiliki step approval aktif', $e->getMessage());
        }
    }

    private function activeStepId(LeaveRequest $leaveRequest): string
    {
        return $leaveRequest->steps()->where('status', 'active')->valueOrFail('id');
    }
}
