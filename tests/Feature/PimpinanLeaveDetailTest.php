<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveApproval;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\PositionHistory;
use App\Models\RefJenisCuti;
use App\Models\RefJenisJabatan;
use App\Models\RefUnitKerja;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PimpinanLeaveDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_monitoring_filters_by_unit_year_month_perubahan_and_employee_search(): void
    {
        $unit = RefUnitKerja::create(['nama' => 'Bagian Akademik']);
        $included = $this->employeeInUnit('Citra Wulandari', $unit);
        $excluded = $this->employeeInUnit('Pegawai Di Luar Filter', RefUnitKerja::create(['nama' => 'Bagian Keuangan']));
        $leaveType = $this->leaveType();
        $matching = $this->leave($included, $leaveType, '2026-07-06', 'perlu_perubahan');
        $this->leave($excluded, $leaveType, '2026-07-06', 'perlu_perubahan');
        $this->leave($included, $leaveType, '2026-06-06', 'perlu_perubahan');

        $this->actingAs($this->pimpinan())
            ->get(route('pimpinan.cuti.index', [
                'unit_kerja_id' => $unit->id,
                'tahun' => 2026,
                'bulan' => 7,
                'status' => 'perubahan',
                'search' => 'Citra',
            ]))
            ->assertOk()
            ->assertSee('Citra Wulandari')
            ->assertSee($matching->id)
            ->assertDontSee('Pegawai Di Luar Filter')
            ->assertSee('Perubahan');
    }

    public function test_detail_shows_official_timeline_actions_notes_and_times(): void
    {
        $approver = Employee::factory()->create(['nama_lengkap' => 'Pejabat Cuti']);
        $leave = $this->leave(Employee::factory()->create(), $this->leaveType(), '2026-07-06', 'ditangguhkan');
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'verifikator',
            'role_label' => 'Verifikator',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => false,
            'decision_note' => 'Lengkapi surat pendukung.',
        ]);
        LeaveApproval::create([
            'leave_request_id' => $leave->id,
            'approver_id' => $approver->id,
            'stage' => 1,
            'action' => 'REQUEST_CHANGES',
            'komentar' => 'Lengkapi surat pendukung.',
            'acted_at' => '2026-07-01 10:30:00',
        ]);
        LeaveApproval::create([
            'leave_request_id' => $leave->id,
            'approver_id' => $approver->id,
            'stage' => 1,
            'action' => 'POSTPONE',
            'komentar' => 'Menunggu konfirmasi jadwal.',
            'acted_at' => '2026-07-02 11:45:00',
        ]);

        $this->actingAs($this->pimpinan())
            ->get(route('pimpinan.cuti.show', $leave))
            ->assertOk()
            ->assertSee('Riwayat Tindakan Resmi')
            ->assertSee('Perubahan')
            ->assertSee('Ditangguhkan')
            ->assertSee('Lengkapi surat pendukung.')
            ->assertSee('Menunggu konfirmasi jadwal.')
            ->assertSee('01 Jul 2026 10:30')
            ->assertSee('02 Jul 2026 11:45')
            ->assertSee('Pejabat Cuti');
    }

    public function test_detail_only_exposes_attachment_through_an_authorized_route_when_file_exists(): void
    {
        Storage::fake('public');
        $leave = $this->leave(Employee::factory()->create(), $this->leaveType(), '2026-07-06', 'menunggu_approval');
        $leave->forceFill(['lampiran_path' => 'cuti/surat-pendukung.pdf'])->save();
        Storage::disk('public')->put('cuti/surat-pendukung.pdf', 'lampiran aktual');
        $pimpinan = $this->pimpinan();

        $this->actingAs($pimpinan)
            ->get(route('pimpinan.cuti.show', $leave))
            ->assertOk()
            ->assertSee('Lampiran Pendukung')
            ->assertSee(route('pimpinan.cuti.attachment.download', $leave), false);
        $this->actingAs($pimpinan)
            ->get(route('pimpinan.cuti.attachment.download', $leave))
            ->assertOk()
            ->assertDownload('Lampiran_Cuti_'.strtoupper(substr($leave->id, 0, 8)).'.pdf');
    }

    public function test_detail_shows_an_empty_attachment_state_without_a_fake_link(): void
    {
        $leave = $this->leave(Employee::factory()->create(), $this->leaveType(), '2026-07-06', 'menunggu_approval');

        $this->actingAs($this->pimpinan())
            ->get(route('pimpinan.cuti.show', $leave))
            ->assertOk()
            ->assertSee('Tidak ada lampiran pendukung.')
            ->assertDontSee(route('pimpinan.cuti.attachment.download', $leave), false);
    }

    private function employeeInUnit(string $name, RefUnitKerja $unit): Employee
    {
        $employee = Employee::factory()->create(['nama_lengkap' => $name]);
        $jenisJabatan = RefJenisJabatan::firstOrCreate(
            ['nama' => 'Jabatan Fungsional'],
            ['maks_usia_pensiun' => 60],
        );
        PositionHistory::create([
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Analis Kepegawaian',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'unit_kerja_id' => $unit->id,
            'tmt_jabatan' => '2025-01-01',
            'no_sk' => 'SK-UNIT-'.strtoupper(substr($employee->id, 0, 8)),
            'tanggal_sk' => '2025-01-01',
            'is_latest' => true,
        ]);

        return $employee;
    }

    private function leaveType(): RefJenisCuti
    {
        return RefJenisCuti::firstOrCreate([
            'code' => 'cuti-sakit',
        ], [
            'nama' => 'Cuti Sakit',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
    }

    private function leave(Employee $employee, RefJenisCuti $leaveType, string $date, string $status): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => $date,
            'tanggal_selesai' => $date,
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Keperluan keluarga.',
            'status' => $status,
        ]);
    }

    private function pimpinan(): User
    {
        return User::factory()->pimpinan()->create();
    }
}
