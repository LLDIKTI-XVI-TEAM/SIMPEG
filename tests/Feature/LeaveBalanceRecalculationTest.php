<?php

namespace Tests\Feature;

use App\Actions\Cuti\StoreManualLeaveUsageAction;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    public function test_fakta_manual_aktif_menjadi_satu_satunya_sumber_pemakaian_projection_tahunan(): void
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
        ]);
        $this->assertSame(2, LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2026)
            ->value('terpakai'));
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
