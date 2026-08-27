<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PimpinanFrontendViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_leave_detail_uses_official_decision_copy_and_the_real_decision_endpoint(): void
    {
        $applicant = Employee::factory()->create(['nama_lengkap' => 'Pegawai Cuti Tampilan']);
        $approver = Employee::factory()->create();
        $leave = LeaveRequest::create([
            'employee_id' => $applicant->id,
            'jenis_cuti_id' => RefJenisCuti::create([
                'nama' => 'Cuti Sakit',
                'code' => 'cuti_sakit',
                'mengurangi_saldo_tahunan' => false,
                'khusus_pns' => false,
            ])->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-08',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        $response = $this->actingAs($this->pimpinan(['employee_id' => $approver->id]))
            ->get(route('pimpinan.cuti.show', $leave))
            ->assertOk()
            ->assertSee('Pegawai Cuti Tampilan')
            ->assertSee('Perubahan')
            ->assertDontSee('Disetujui dengan Perubahan')
            ->assertSee('Wajib diisi jika memilih Perubahan, Ditangguhkan, atau Tidak Disetujui...')
            ->assertDontSee('Tunda Sementara')
            ->assertDontSee('aria-describedby="decision-note-help keputusan-error"', false)
            ->assertDontSee('aria-describedby="decision-note-help catatan-error"', false)
            ->assertSee('aria-describedby="pimpinan-approval-confirmation-description"', false)
            ->assertSee('id="pimpinan-approval-confirmation-description"', false)
            ->assertSee('@keydown.escape.window="if (confirmOpen) { confirmOpen = false }"', false)
            ->assertSee(route('pimpinan.cuti.decision', $leave), false);

        $this->assertMatchesRegularExpression(
            '/<button(?=[^>]*\bid="pimpinan-approval-confirmation-cancel")(?=[^>]*\bdata-modal-initial-focus="true")[^>]*>/s',
            (string) $response->getContent(),
        );
    }

    public function test_leave_surfaces_label_returned_rollover_without_offering_a_decision(): void
    {
        $applicant = Employee::factory()->create(['nama_lengkap' => 'Pegawai Rollover Pimpinan']);
        $approver = Employee::factory()->create();
        $leave = LeaveRequest::create([
            'employee_id' => $applicant->id,
            'jenis_cuti_id' => RefJenisCuti::create([
                'nama' => 'Cuti Tahunan Rollover Pimpinan',
                'code' => 'tahunan',
                'mengurangi_saldo_tahunan' => true,
                'khusus_pns' => false,
            ])->id,
            'tanggal_mulai' => '2026-12-28',
            'tanggal_selesai' => '2026-12-30',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Pengajuan rollover untuk tampilan pimpinan.',
            'status' => LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER,
            'rollover_source_year' => 2026,
            'rollover_target_year' => 2027,
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);
        $user = $this->pimpinan(['employee_id' => $approver->id]);

        $this->actingAs($user)
            ->get(route('pimpinan.cuti.index', ['status' => LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER]))
            ->assertOk()
            ->assertSee('Pegawai Rollover Pimpinan')
            ->assertSee('Dikembalikan karena Rollover')
            ->assertSee('value="'.LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER.'"', false);

        $this->actingAs($user)
            ->get(route('pimpinan.cuti.show', $leave))
            ->assertOk()
            ->assertSee('Dikembalikan karena Rollover')
            ->assertDontSee(route('pimpinan.cuti.decision', $leave), false);
    }

    /**
     * Counter antrean harus memakai predikat yang sama dengan daftarnya. Pengajuan yang dikembalikan
     * saat rollover masih menyimpan step aktif sebagai snapshot, sehingga counter tidak boleh
     * menghitungnya dan membuat angka berbeda dari isi daftar yang dibuka approver.
     */
    public function test_rollover_return_is_excluded_from_my_pending_action_counter(): void
    {
        $applicant = Employee::factory()->create(['nama_lengkap' => 'Pegawai Counter Rollover']);
        $approver = Employee::factory()->create();
        $leave = LeaveRequest::create([
            'employee_id' => $applicant->id,
            'jenis_cuti_id' => RefJenisCuti::create([
                'nama' => 'Cuti Tahunan Counter Rollover',
                'code' => 'tahunan',
                'mengurangi_saldo_tahunan' => true,
                'khusus_pns' => false,
            ])->id,
            'tanggal_mulai' => '2026-12-28',
            'tanggal_selesai' => '2026-12-30',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Pengajuan rollover tidak boleh masuk antrean.',
            'status' => LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER,
            'rollover_source_year' => 2026,
            'rollover_target_year' => 2027,
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);
        $user = $this->pimpinan(['employee_id' => $approver->id]);

        $this->actingAs($user)
            ->get(route('pimpinan.cuti.index'))
            ->assertOk()
            ->assertViewHas('menungguTindakanSaya', 0);

        $this->actingAs($user)
            ->get(route('pimpinan.cuti.index', ['status' => 'menunggu_saya']))
            ->assertOk()
            ->assertViewHas('menungguTindakanSaya', 0)
            ->assertDontSee('Pegawai Counter Rollover');
    }

    public function test_employee_detail_exposes_an_accessible_info_tab_without_a_dummy_export_submission(): void
    {
        $employee = Employee::factory()->lengkap()->create(['nama_lengkap' => 'Pegawai Detail Tampilan']);

        $this->actingAs($this->pimpinan())
            ->get(route('pimpinan.pegawai.show', $employee))
            ->assertOk()
            ->assertSee('Pegawai Detail Tampilan')
            ->assertSee('Detail Pegawai')
            ->assertDontSee('Edit Pegawai')
            ->assertDontSee('/pimpinan/laporan/pegawai/custom', false);
    }

    public function test_ews_presentation_exposes_required_fields_without_fake_navigation_or_pagination(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai EWS Tampilan']);
        EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KONTRAK_PPPK',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);

        $this->actingAs($this->pimpinan())
            ->get(route('pimpinan.ews.index'))
            ->assertOk()
            ->assertSee('Kontrak PPPK')
            ->assertSee('Pegawai EWS Tampilan')
            ->assertSee('Tanggal Target')
            ->assertSee('Status Kelayakan')
            ->assertDontSee('href="#"', false)
            ->assertSee(route('pimpinan.pegawai.show', $employee), false)
            ->assertDontSee('data per halaman');
    }

    /** @param array<string, mixed> $attributes */
    private function pimpinan(array $attributes = []): User
    {
        return User::factory()->pimpinan()->create($attributes);
    }
}
