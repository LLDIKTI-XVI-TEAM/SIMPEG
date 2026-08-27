<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Support\Cuti\BackfillLegacyApprovedLeaveUsage;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BackfillLegacyApprovedLeaveUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        Carbon::setTestNow('2026-08-27 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_replay_upgrade_memakai_predecessor_material_untuk_carry_legacy(): void
    {
        $pns = RefJenisPegawai::query()->where('nama', 'PNS')->firstOrFail();
        $annual = RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $pns->id,
            'status_aktif' => 'Aktif',
        ]);
        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-BACKFILL-CARRY',
            'tanggal_sk' => '2020-01-01',
        ]);
        $predecessor = LeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'tahun' => 2025,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 6,
            'sisa' => 6,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 6,
            'terpakai_tahun_berjalan' => 6,
            'hangus' => 0,
        ]);
        $predecessorBefore = $predecessor->only([
            'employee_id', 'tahun', 'jatah_awal', 'carry_over', 'terpakai', 'sisa',
            'sisa_n2', 'sisa_n1', 'sisa_tahun_berjalan', 'terpakai_tahun_berjalan', 'hangus',
        ]);
        $request = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $annual->id,
            'tanggal_mulai' => '2026-03-02',
            'tanggal_selesai' => '2026-03-18',
            'jumlah_hari_kerja' => 13,
            'alasan' => 'Pengajuan legacy memakai carry predecessor.',
            'status' => 'disetujui',
        ]);

        $summary = app(BackfillLegacyApprovedLeaveUsage::class)->execute();

        $this->assertSame(['facts' => 1, 'employees_recalculated' => 1], $summary);
        $this->assertDatabaseHas('leave_usage_records', [
            'leave_request_id' => $request->id,
            'usage_year' => 2026,
            'workdays' => 13,
        ]);
        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'carry_over' => 6,
            'terpakai' => 13,
            'sisa' => 5,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 5,
        ]);
        $this->assertSame($predecessorBefore, $predecessor->fresh()->only(array_keys($predecessorBefore)));
    }
}
