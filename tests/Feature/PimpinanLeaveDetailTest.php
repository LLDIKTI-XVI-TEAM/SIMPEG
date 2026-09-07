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
use Tests\Concerns\CreatesAdoptedLeaveAttachment;
use Tests\TestCase;

class PimpinanLeaveDetailTest extends TestCase
{
    use CreatesAdoptedLeaveAttachment;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->setUpAdoptedLeaveAttachmentFixtures();
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

    public function test_monitoring_accepts_and_labels_duty_postponement_status(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Terminal Pimpinan']);
        $leave = $this->leave(
            $employee,
            $this->leaveType(),
            '2026-07-06',
            LeaveRequest::STATUS_DUTY_POSTPONED,
        );

        $response = $this->actingAs($this->pimpinan())
            ->get(route('pimpinan.cuti.index', ['status' => LeaveRequest::STATUS_DUTY_POSTPONED]));

        $response->assertOk()
            ->assertSee('Pegawai Terminal Pimpinan')
            ->assertSee('Ditangguhkan karena Tugas Dinas')
            ->assertSee('value="'.LeaveRequest::STATUS_DUTY_POSTPONED.'"', false)
            ->assertViewHas('totalDitangguhkan', 1);
    }

    public function test_detail_pending_step_explains_waiting_role_and_unknown_remains_neutral(): void
    {
        $leave = $this->leave(Employee::factory()->create(), $this->leaveType(), '2026-07-06', 'menunggu_approval');
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'status' => 'pending',
            'is_final' => false,
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 2,
            'step_type' => 'unknown',
            'role_label' => 'Role Rahasia',
            'status' => 'status_rahasia',
            'is_final' => false,
        ]);

        $this->actingAs($this->pimpinan())
            ->get(route('pimpinan.cuti.show', $leave))
            ->assertOk()
            ->assertSeeInOrder(['Tahap 1 · Atasan Langsung', 'Menunggu Atasan Langsung'])
            ->assertSeeInOrder(['Tahap 2 · Role Rahasia', 'Status tidak tersedia'])
            ->assertDontSee('status_rahasia');
    }

    public function test_detail_shows_active_step_as_held_while_cancellation_is_pending(): void
    {
        $leave = $this->leave(Employee::factory()->create(), $this->leaveType(), '2026-07-06', LeaveRequest::STATUS_CANCELLATION_PENDING);
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => Employee::factory()->create()->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        $this->actingAs($this->pimpinan())
            ->get(route('pimpinan.cuti.show', $leave))
            ->assertOk()
            ->assertSeeInOrder(['Timeline Persetujuan', 'Tahap 1 · PYBMC', 'Menunggu Keputusan Pembatalan'])
            ->assertDontSee('animate-pulse', false);
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
        LeaveApproval::create([
            'leave_request_id' => $leave->id,
            'approver_id' => $approver->id,
            'stage' => 1,
            'action' => 'NOT_APPROVED',
            'komentar' => 'Dokumen baru tidak memenuhi persyaratan.',
            'acted_at' => '2026-07-03 09:15:00',
        ]);
        $response = $this->actingAs($this->pimpinan())
            ->get(route('pimpinan.cuti.show', $leave));

        $response
            ->assertOk()
            ->assertSee('Riwayat Tindakan Resmi')
            ->assertSee('Perubahan')
            ->assertSee('Ditangguhkan')
            ->assertDontSee('Ditolak')
            ->assertSee('Lengkapi surat pendukung.')
            ->assertSee('Menunggu konfirmasi jadwal.')
            ->assertSee('Dokumen baru tidak memenuhi persyaratan.')
            ->assertSee('01 Jul 2026 10:30')
            ->assertSee('02 Jul 2026 11:45')
            ->assertSee('Pejabat Cuti');

        $response->assertSee('Tidak Disetujui');
    }

    public function test_detail_only_exposes_attachment_through_an_authorized_route_when_file_exists(): void
    {
        Storage::fake('local');

        $leave = $this->leave(Employee::factory()->create(), $this->leaveType(), '2026-07-06', 'menunggu_approval');
        $this->createAdoptedLeaveAttachment($leave, "%PDF-1.4\n% lampiran aktual\n%%EOF\n");
        $pimpinan = $this->pimpinan();

        $this->actingAs($pimpinan)
            ->get(route('pimpinan.cuti.show', $leave))
            ->assertOk()
            ->assertSee('Lampiran Pendukung')
            ->assertSee(route('pimpinan.cuti.attachment.download', $leave), false);
        $download = $this->actingAs($pimpinan)
            ->get(route('pimpinan.cuti.attachment.download', $leave));
        $download
            ->assertOk()
            ->assertDownload('Lampiran_Cuti_'.strtoupper(substr($leave->id, 0, 8)).'.pdf')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        foreach (['private', 'no-store', 'max-age=0'] as $directive) {
            $this->assertStringContainsString($directive, (string) $download->headers->get('Cache-Control'));
        }
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
