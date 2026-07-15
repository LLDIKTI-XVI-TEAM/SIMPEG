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

        $this->actingAs($this->pimpinan(['employee_id' => $approver->id]))
            ->get(route('pimpinan.cuti.show', $leave))
            ->assertOk()
            ->assertSee('Pegawai Cuti Tampilan')
            ->assertSee('Perubahan')
            ->assertDontSee('Disetujui dengan Perubahan')
            ->assertSee('Wajib diisi jika memilih Perubahan, Ditangguhkan, atau Tidak Disetujui...')
            ->assertDontSee('aria-describedby="decision-note-help keputusan-error"', false)
            ->assertDontSee('aria-describedby="decision-note-help catatan-error"', false)
            ->assertSee(route('pimpinan.cuti.decision', $leave), false);
    }

    public function test_employee_detail_exposes_an_accessible_info_tab_without_a_dummy_export_submission(): void
    {
        $employee = Employee::factory()->lengkap()->create(['nama_lengkap' => 'Pegawai Detail Tampilan']);

        $this->actingAs($this->pimpinan())
            ->get(route('pimpinan.pegawai.show', $employee))
            ->assertOk()
            ->assertSee('Pegawai Detail Tampilan')
            ->assertSee('aria-label="Navigasi detail pegawai"', false)
            ->assertSee('aria-orientation="vertical"', false)
            ->assertSee('aria-controls="pimpinan-panel-info"', false)
            ->assertSee('id="pimpinan-panel-info"', false)
            ->assertSee('@keydown.down.prevent', false)
            ->assertDontSee('@keydown.right.prevent', false)
            ->assertSee('history-export-unavailable', false)
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
