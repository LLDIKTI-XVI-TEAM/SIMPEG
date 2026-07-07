<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RefJenisCuti;
use App\Services\LeaveApprovalService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Menguji mesin persetujuan cuti berbasis snapshot step.
 * Fokus: otorisasi person-based dari step aktif, perpindahan status dinamis,
 * penundaan pada step yang sama, dan pemotongan saldo final yang hanya berlaku untuk cuti tahunan.
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

    /** @return array{employee: Employee, kepala_bagian: Employee, pybmc: Employee} */
    private function makePemohon(): array
    {
        return [
            'employee' => Employee::factory()->create(),
            'kepala_bagian' => Employee::factory()->create(),
            'pybmc' => Employee::factory()->create(),
        ];
    }

    private function jenisCuti(string $nama): RefJenisCuti
    {
        return RefJenisCuti::create([
            'nama' => $nama,
            'code' => str($nama)->slug('_')->toString(),
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

        $latestOrderByApprover = collect($approvers)
            ->mapWithKeys(fn (Employee $approver, int $index) => [$approver->id => $index + 1]);
        $firstActiveAssigned = false;

        foreach ($approvers as $index => $approver) {
            $order = $index + 1;
            $isFinal = $order === count($approvers);
            $stepType = $isFinal ? 'pybmc' : 'kepala_bagian';
            $roleLabel = $isFinal ? 'PYBMC' : 'Kepala Bagian';
            $isEarlierDuplicate = $latestOrderByApprover[$approver->id] !== $order;
            $status = 'pending';

            if ($isEarlierDuplicate) {
                $status = 'skipped';
            } elseif (! $firstActiveAssigned) {
                $status = 'active';
                $firstActiveAssigned = true;
            }

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
                'skipped_reason' => $isEarlierDuplicate ? 'duplicate_approver' : null,
            ]);
        }

        return $leave;
    }

    public function test_snapshot_approve_menyetujui_penuh_dan_memotong_saldo_tahunan(): void
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

        $this->service()->approve($cuti, $pemohon['kepala_bagian']);
        $this->assertSame('menunggu_approval', $cuti->fresh()->status);
        $this->assertDatabaseHas('leave_request_steps', ['leave_request_id' => $cuti->id, 'step_order' => 1, 'status' => 'approved']);
        $this->assertDatabaseHas('leave_request_steps', ['leave_request_id' => $cuti->id, 'step_order' => 2, 'status' => 'active']);

        $this->service()->approve($cuti->fresh(), $pemohon['pybmc']);
        $this->assertSame('disetujui', $cuti->fresh()->status);

        $balance = LeaveBalance::where('employee_id', $pemohon['employee']->id)->where('tahun', 2026)->first();
        $this->assertSame(3, $balance->terpakai);
        $this->assertSame(9, $balance->sisa);
    }

    public function test_approver_duplikat_dilewati_otomatis_pada_snapshot(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['kepala_bagian'], $pemohon['pybmc']], 2);

        $this->service()->approve($cuti, $pemohon['kepala_bagian']);

        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $cuti->id,
            'step_order' => 1,
            'status' => 'skipped',
            'skipped_reason' => 'duplicate_approver',
        ]);
        $this->assertDatabaseHas('leave_request_steps', ['leave_request_id' => $cuti->id, 'step_order' => 2, 'status' => 'approved']);
        $this->assertDatabaseHas('leave_request_steps', ['leave_request_id' => $cuti->id, 'step_order' => 3, 'status' => 'active']);
    }

    public function test_duplikat_kepala_bagian_dan_pybmc_mempertahankan_step_final(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['pybmc'], $pemohon['pybmc']], 2);

        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $cuti->id,
            'step_order' => 1,
            'status' => 'skipped',
            'skipped_reason' => 'duplicate_approver',
            'is_final' => false,
        ]);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $cuti->id,
            'step_order' => 2,
            'status' => 'active',
            'is_final' => true,
        ]);

        $this->service()->approve($cuti->fresh(), $pemohon['pybmc']);

        $this->assertSame('disetujui', $cuti->fresh()->status);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $cuti->id,
            'stage' => 2,
            'action' => 'APPROVE',
        ]);
    }

    public function test_duplikat_verifikator_dan_pybmc_mempertahankan_step_final(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');
        $verifikatorFinal = Employee::factory()->create();
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $verifikatorFinal, $verifikatorFinal], 2);

        $this->service()->approve($cuti, $pemohon['kepala_bagian']);

        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $cuti->id,
            'step_order' => 2,
            'status' => 'skipped',
            'skipped_reason' => 'duplicate_approver',
            'is_final' => false,
        ]);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $cuti->id,
            'step_order' => 3,
            'status' => 'active',
            'is_final' => true,
        ]);
    }

    public function test_perlu_perubahan_mengembalikan_pengajuan_ke_pemohon(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 2);

        $this->service()->requestChanges($cuti, $pemohon['kepala_bagian'], 'Tanggal cuti perlu diperbaiki.');

        $this->assertSame('perlu_perubahan', $cuti->fresh()->status);
        $this->assertDatabaseHas('leave_request_steps', ['leave_request_id' => $cuti->id, 'step_order' => 1, 'status' => 'active']);
        $this->assertDatabaseHas('leave_approvals', ['leave_request_id' => $cuti->id, 'stage' => 1, 'action' => 'REQUEST_CHANGES']);
    }

    public function test_perlu_perubahan_tidak_bisa_disetujui_sebelum_dikirim_ulang(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 2);

        $this->service()->requestChanges($cuti, $pemohon['kepala_bagian'], 'Tanggal cuti perlu diperbaiki.');

        $this->expectException(ValidationException::class);
        $this->service()->approve($cuti->fresh(), $pemohon['kepala_bagian']);
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

        $this->service()->reject($cuti, $pemohon['kepala_bagian'], 'Dokumen pendukung tidak sesuai.');

        $this->assertSame('tidak_disetujui', $cuti->fresh()->status);
        $this->assertDatabaseHas('leave_request_steps', ['leave_request_id' => $cuti->id, 'step_order' => 1, 'status' => 'rejected']);
        $this->assertDatabaseHas('leave_approvals', ['leave_request_id' => $cuti->id, 'stage' => 1, 'action' => 'REJECT']);
        $balance = LeaveBalance::where('employee_id', $pemohon['employee']->id)->where('tahun', 2026)->first();
        $this->assertSame(0, $balance->terpakai);
        $this->assertSame(12, $balance->sisa);
    }

    public function test_penundaan_lalu_disetujui_kembali_oleh_approver_yang_sama(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 2);

        $this->service()->postpone($cuti, $pemohon['kepala_bagian'], 'Menunggu pengganti tugas.');
        $this->assertSame('ditangguhkan', $cuti->fresh()->status);
        $this->assertSame(1, $this->service()->pendingStage($cuti->fresh()));

        $this->service()->approve($cuti->fresh(), $pemohon['kepala_bagian']);
        $this->assertDatabaseHas('leave_request_steps', ['leave_request_id' => $cuti->id, 'step_order' => 2, 'status' => 'active']);
    }

    public function test_approver_salah_step_ditolak(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 2);

        $this->expectException(AuthorizationException::class);
        $this->service()->approve($cuti, $pemohon['pybmc']);
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

        $this->service()->approve($cuti, $pemohon['kepala_bagian']);
        $this->service()->approve($cuti->fresh(), $pemohon['pybmc']);
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

        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [$pemohon['kepala_bagian'], $pemohon['pybmc']], 3);
        $this->service()->approve($cuti, $pemohon['kepala_bagian']);

        try {
            $this->service()->approve($cuti->fresh(), $pemohon['pybmc']);
            $this->fail('Persetujuan final seharusnya gagal karena saldo tidak cukup.');
        } catch (ValidationException $e) {
            $this->assertSame('menunggu_approval', $cuti->fresh()->status);
            $this->assertDatabaseHas('leave_request_steps', ['leave_request_id' => $cuti->id, 'step_order' => 2, 'status' => 'active']);
            $balance = LeaveBalance::where('employee_id', $pemohon['employee']->id)->where('tahun', 2026)->first();
            $this->assertSame(11, $balance->terpakai);
            $this->assertSame(1, $balance->sisa);
        }
    }

    public function test_approve_tanpa_step_aktif_memberi_pesan_konfigurasi(): void
    {
        $pemohon = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, [], 2);

        try {
            $this->service()->approve($cuti, Employee::factory()->create());
            $this->fail('Persetujuan seharusnya gagal karena pengajuan belum memiliki step aktif.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Pengajuan cuti ini belum memiliki step approval aktif', $e->getMessage());
        }
    }
}
