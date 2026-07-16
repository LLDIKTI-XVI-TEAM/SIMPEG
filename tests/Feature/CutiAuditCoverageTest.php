<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Mengunci keterlacakan audit pengajuan cuti (submit).
 * Audit CREATE saat submit wajib membawa employee_id pemohon yang akurat pada new_values,
 * bukan sekadar keberadaan baris audit, agar jejak pengajuan cuti dapat ditelusuri ke
 * pegawai yang benar untuk kebutuhan audit kepegawaian.
 */
class CutiAuditCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = 'cuti.store';

    protected function setUp(): void
    {
        parent::setUp();

        // Seed RBAC agar permission cuti.create tersedia bagi gerbang route dan FormRequest.
        $this->seed(RbacSeeder::class);
    }

    public function test_submit_menulis_audit_create_leave_request_dengan_employee_id_pemohon(): void
    {
        // Isolasi job email notifikasi (dispatched afterCommit) agar test fokus pada jejak audit submit.
        Queue::fake();

        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');

        $this->actingAs($aktor['user']);
        $response = $this->post(route(self::ROUTE), $this->payload($jenis));

        $response->assertRedirect(route('cuti'));

        // Ambil pengajuan target secara deterministik untuk mengunci query audit ke ID tersebut,
        // bukan mengandalkan baris audit terbaru secara global.
        $leaveRequest = LeaveRequest::query()
            ->where('employee_id', $aktor['employee']->id)
            ->firstOrFail();

        $audit = AuditLog::query()
            ->where('event', 'CREATE')
            ->where('auditable_type', 'LeaveRequest')
            ->where('auditable_id', $leaveRequest->id)
            ->firstOrFail();

        // Jejak audit submit wajib membawa employee_id pemohon yang benar pada new_values
        // agar pengajuan cuti dapat ditelusuri ke pegawai yang tepat.
        $this->assertSame($aktor['employee']->id, $audit->new_values['employee_id']);
    }

    /**
     * Membuat pegawai pemohon lengkap dengan akun, jenis pegawai, atasan langsung aktif,
     * dan rantai approval agar submit lolos prasyarat dan mencapai penulisan audit.
     *
     * @return array{user: User, employee: Employee}
     */
    private function makePemohon(): array
    {
        $jenis = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);

        $employee = Employee::factory()->create(['jenis_pegawai_id' => $jenis->id]);
        $supervisor = Employee::factory()->create();
        $pybmc = Employee::factory()->create();

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2024-01-01',
            'no_sk' => 'SK-TEST-001',
            'tanggal_sk' => '2024-01-01',
        ]);

        // Atasan langsung aktif: tanggal_berakhir null menandai penugasan masih berjalan.
        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'supervisor_id' => $supervisor->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);

        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $chain = LeaveApprovalChain::create([
            'employee_id' => $employee->id,
            'name' => 'Chain cuti pegawai',
            'effective_from' => '2026-01-01',
            'change_reason' => 'Setup test chain.',
        ]);
        $chain->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $supervisor->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $pybmc->id,
                'is_final' => true,
            ],
        ]);

        return ['user' => $user, 'employee' => $employee];
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
     * @return array<string, mixed>
     */
    private function payload(RefJenisCuti $jenis): array
    {
        return [
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-10',
            'alasan' => 'Keperluan keluarga.',
            'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 1, Manado',
            'nomor_telepon' => '+62 (431) 123-456',
        ];
    }
}
