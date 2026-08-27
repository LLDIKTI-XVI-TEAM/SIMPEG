<?php

namespace Tests\Feature;

use App\Actions\Cuti\ReconcileAnnualLeaveUsageAction;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\SimpegNotification;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Menguji perjalanan lengkap pengajuan cuti melalui endpoint web sebenarnya:
 * pegawai mengajukan, Kepala Bagian memutus lewat halaman kabag, PYBMC memutus
 * final lewat halaman pimpinan, lalu saldo tahunan terpotong otomatis.
 */
class CutiEndToEndApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_alur_lengkap_pengajuan_kabag_pybmc_sampai_saldo_terpotong(): void
    {
        // === Setup struktur: pemohon, Kepala Bagian, PYBMC, chain, dan saldo awal ===
        $jenisPegawai = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $kabagEmployee = Employee::factory()->create(['nama_lengkap' => 'Kepala Bagian E2E']);
        $pybmcEmployee = Employee::factory()->create(['nama_lengkap' => 'Pejabat PYBMC E2E']);
        $pemohon = Employee::factory()->create([
            'nama_lengkap' => 'Pemohon E2E',
            'jenis_pegawai_id' => $jenisPegawai->id,
            'kepala_bagian_id' => $kabagEmployee->id,
        ]);

        Appointment::create([
            'employee_id' => $pemohon->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-E2E-001',
            'tanggal_sk' => '2020-01-01',
        ]);
        SupervisorAssignment::create([
            'employee_id' => $pemohon->id,
            'supervisor_id' => $kabagEmployee->id,
            'kepala_bagian_id' => $kabagEmployee->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);

        $chain = LeaveApprovalChain::create([
            'employee_id' => $pemohon->id,
            'name' => 'Chain E2E',
            'effective_from' => '2026-01-01',
            'change_reason' => 'Setup chain untuk uji alur lengkap.',
        ]);
        $chain->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $kabagEmployee->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $pybmcEmployee->id,
                'is_final' => true,
            ],
        ]);

        $pegawaiUser = User::factory()->pegawai()->create(['employee_id' => $pemohon->id]);
        $kabagUser = User::factory()->kepalaBagian()->create(['employee_id' => $kabagEmployee->id]);
        $pimpinanUser = User::factory()->pimpinan()->create(['employee_id' => $pybmcEmployee->id]);

        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $admin = User::factory()->adminKepegawaian()->create();
        app(ReconcileAnnualLeaveUsageAction::class)->execute(
            $pemohon->id,
            [
                'balance_year' => 2026,
                'usage_n2' => 12,
                'usage_n1' => 12,
                'usage_current' => 0,
                'administrative_note' => 'Rekonsiliasi saldo awal fixture alur lengkap.',
            ],
            $admin,
            $this->actorRequest($admin),
        );

        // === Tahap 1: pegawai mengajukan cuti (3-7 Agustus 2026 = 5 hari kerja) ===
        $this->actingAs($pegawaiUser)
            ->post(route('cuti.store'), [
                'jenis_cuti_id' => $jenis->id,
                'tanggal_mulai' => '2026-08-03',
                'tanggal_selesai' => '2026-08-07',
                'alasan' => 'Keperluan keluarga.',
                'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 1, Manado',
                'nomor_telepon' => '+62 (431) 123-456',
            ])
            ->assertRedirect(route('cuti'));

        $leave = LeaveRequest::firstOrFail();
        $this->assertSame('menunggu_approval', $leave->status);
        $this->assertSame(5, $leave->jumlah_hari_kerja);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'status' => 'active',
            'approver_employee_id' => $kabagEmployee->id,
        ]);
        // Approver pertama menerima notifikasi pengajuan baru.
        $this->assertTrue(SimpegNotification::query()->where('user_id', $kabagEmployee->id)->exists());

        // === Tahap 2: Kepala Bagian menyetujui lewat endpoint halaman kabag ===
        $this->actingAs($kabagUser)
            ->post(route('kepala-bagian.cuti.decision', $leave), [
                'keputusan' => 'DISETUJUI',
            ])
            ->assertRedirect(route('kepala-bagian.cuti.show', $leave))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 2,
            'status' => 'active',
            'approver_employee_id' => $pybmcEmployee->id,
        ]);
        $this->assertSame('menunggu_approval', $leave->refresh()->status);
        // Saldo belum boleh terpotong sebelum keputusan final.
        $this->assertSame(0, LeaveBalance::query()
            ->where('employee_id', $pemohon->id)
            ->where('tahun', 2026)
            ->firstOrFail()
            ->terpakai);
        // PYBMC menerima notifikasi giliran memutus.
        $this->assertTrue(SimpegNotification::query()->where('user_id', $pybmcEmployee->id)->exists());

        // === Tahap 3: PYBMC menyetujui final lewat endpoint halaman pimpinan ===
        $this->actingAs($pimpinanUser)
            ->post(route('pimpinan.cuti.decision', $leave), [
                'keputusan' => 'DISETUJUI',
                'catatan' => 'Disetujui.',
            ])
            ->assertRedirect(route('pimpinan.cuti.show', $leave));

        $leave->refresh();
        $this->assertSame('disetujui', $leave->status);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 2,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $leave->id,
            'approver_id' => $kabagEmployee->id,
            'action' => 'APPROVE',
        ]);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $leave->id,
            'approver_id' => $pybmcEmployee->id,
            'action' => 'APPROVE',
        ]);

        // === Hasil akhir: saldo terpotong otomatis, ledger dan bukti QR tercatat ===
        $balance = LeaveBalance::query()
            ->where('employee_id', $pemohon->id)
            ->where('tahun', 2026)
            ->firstOrFail();
        $this->assertSame(5, $balance->terpakai);
        $this->assertSame(7, $balance->sisa);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $pemohon->id,
        ]);
        $this->assertDatabaseHas('leave_proofs', [
            'leave_request_id' => $leave->id,
        ]);
        // Pemohon menerima notifikasi hasil keputusan final.
        $this->assertTrue(SimpegNotification::query()->where('user_id', $pemohon->id)->exists());
    }

    private function actorRequest(User $actor): Request
    {
        $request = Request::create('/cuti/reconciliation', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }
}
