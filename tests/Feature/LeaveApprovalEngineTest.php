<?php

namespace Tests\Feature;

use App\Models\ApprovalConfig;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\LeaveApprovalService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Menguji mesin persetujuan cuti tiga tahap.
 * Fokus: derivasi tahap menunggu, otorisasi person-based, skip approver duplikat,
 * reversibilitas penundaan, dan pemotongan saldo final yang hanya berlaku untuk Cuti Tahunan.
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
     * Membuat pegawai pemohon dengan atasan langsung aktif sebagai approver stage 1.
     *
     * @return array{employee: Employee, supervisor: Employee}
     */
    private function makePemohon(): array
    {
        $employee = Employee::factory()->create();
        $supervisor = Employee::factory()->create();

        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'supervisor_id' => $supervisor->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);

        return ['employee' => $employee, 'supervisor' => $supervisor];
    }

    /**
     * Menetapkan approver stage 2 dan stage 3 melalui approval_configs.
     * Konfigurasi menyimpan id User, sehingga tiap approver dibuat sebagai User yang tertaut ke Employee.
     */
    private function setApprovers(Employee $verifikator, Employee $pimpinan): void
    {
        $userVerifikator = User::factory()->create(['employee_id' => $verifikator->id]);
        $userPimpinan = User::factory()->create(['employee_id' => $pimpinan->id]);

        ApprovalConfig::setVal('stage2_approver_id', (string) $userVerifikator->id);
        ApprovalConfig::setVal('stage3_approver_id', (string) $userPimpinan->id);
    }

    private function jenisCuti(string $nama): RefJenisCuti
    {
        return RefJenisCuti::create(['nama' => $nama, 'khusus_pns' => false]);
    }

    /**
     * Membuat pengajuan cuti pada status awal menunggu atasan langsung.
     */
    private function makeRequest(Employee $employee, RefJenisCuti $jenis, int $hari = 3): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-08',
            'jumlah_hari_kerja' => $hari,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'Menunggu Atasan Langsung',
        ]);
    }

    public function test_tiga_tahap_approve_menyetujui_penuh_dan_memotong_saldo_tahunan(): void
    {
        $pemohon = $this->makePemohon();
        $verifikator = Employee::factory()->create();
        $pimpinan = Employee::factory()->create();
        $this->setApprovers($verifikator, $pimpinan);

        $jenis = $this->jenisCuti('Cuti Tahunan');
        LeaveBalance::create([
            'employee_id' => $pemohon['employee']->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
        ]);

        $cuti = $this->makeRequest($pemohon['employee'], $jenis, 3);
        $service = $this->service();

        // Stage 1 oleh atasan langsung -> lanjut ke verifikator.
        $service->approve($cuti, $pemohon['supervisor']);
        $this->assertSame('Menunggu Verifikator', $cuti->fresh()->status);

        // Stage 2 oleh verifikator -> lanjut ke pimpinan.
        $service->approve($cuti->fresh(), $verifikator);
        $this->assertSame('Menunggu Pimpinan', $cuti->fresh()->status);

        // Stage 3 oleh pimpinan -> disetujui penuh.
        $service->approve($cuti->fresh(), $pimpinan);
        $this->assertSame('Disetujui', $cuti->fresh()->status);

        // Saldo cuti tahunan terpotong sesuai jumlah hari kerja.
        $balance = LeaveBalance::where('employee_id', $pemohon['employee']->id)->where('tahun', 2026)->first();
        $this->assertSame(3, $balance->terpakai);
        $this->assertSame(9, $balance->sisa);
    }

    public function test_approver_duplikat_dilewati_otomatis(): void
    {
        $pemohon = $this->makePemohon();
        $pimpinan = Employee::factory()->create();

        // Stage 2 sengaja diisi orang yang sama dengan atasan langsung (stage 1) -> stage 2 harus dilewati.
        $this->setApprovers($pemohon['supervisor'], $pimpinan);

        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, 2);
        $service = $this->service();

        // Approve stage 1 oleh atasan langsung; karena approver stage 2 sama, alur melompat ke stage 3.
        $service->approve($cuti, $pemohon['supervisor']);
        $this->assertSame('Menunggu Pimpinan', $cuti->fresh()->status);

        // Tahap 2 tercatat sebagai persetujuan otomatis pada riwayat approval.
        $this->assertTrue($cuti->approvals()->where('stage', 2)->exists());
    }

    public function test_penundaan_lalu_disetujui_kembali_oleh_approver_yang_sama(): void
    {
        $pemohon = $this->makePemohon();
        $verifikator = Employee::factory()->create();
        $pimpinan = Employee::factory()->create();
        $this->setApprovers($verifikator, $pimpinan);

        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, 2);
        $service = $this->service();

        // Atasan langsung menunda; status menjadi Ditunda namun tetap reversible pada tahap yang sama.
        $service->postpone($cuti, $pemohon['supervisor'], 'Menunggu pengganti tugas.');
        $this->assertSame('Ditunda', $cuti->fresh()->status);

        // Tahap menunggu untuk status Ditunda tetap dapat diturunkan dari riwayat (stage 1).
        $this->assertSame(1, $service->pendingStage($cuti->fresh()));

        // Approver yang sama menyetujui kembali -> alur melanjutkan ke verifikator.
        $service->approve($cuti->fresh(), $pemohon['supervisor']);
        $this->assertSame('Menunggu Verifikator', $cuti->fresh()->status);
    }

    public function test_approver_salah_tahap_ditolak(): void
    {
        $pemohon = $this->makePemohon();
        $verifikator = Employee::factory()->create();
        $pimpinan = Employee::factory()->create();
        $this->setApprovers($verifikator, $pimpinan);

        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, 2);

        // Pengajuan masih di stage 1 (atasan langsung); verifikator belum berhak bertindak.
        $this->expectException(AuthorizationException::class);
        $this->service()->approve($cuti, $verifikator);
    }

    public function test_cuti_non_tahunan_tidak_memotong_saldo(): void
    {
        $pemohon = $this->makePemohon();
        $verifikator = Employee::factory()->create();
        $pimpinan = Employee::factory()->create();
        $this->setApprovers($verifikator, $pimpinan);

        $jenis = $this->jenisCuti('Cuti Sakit');
        LeaveBalance::create([
            'employee_id' => $pemohon['employee']->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
        ]);

        $cuti = $this->makeRequest($pemohon['employee'], $jenis, 2);
        $service = $this->service();

        $service->approve($cuti, $pemohon['supervisor']);
        $service->approve($cuti->fresh(), $verifikator);
        $service->approve($cuti->fresh(), $pimpinan);
        $this->assertSame('Disetujui', $cuti->fresh()->status);

        // Saldo tidak berubah karena hanya Cuti Tahunan yang memotong saldo.
        $balance = LeaveBalance::where('employee_id', $pemohon['employee']->id)->where('tahun', 2026)->first();
        $this->assertSame(0, $balance->terpakai);
        $this->assertSame(12, $balance->sisa);
    }

    public function test_saldo_tidak_cukup_saat_final_menggagalkan_persetujuan(): void
    {
        $pemohon = $this->makePemohon();
        $verifikator = Employee::factory()->create();
        $pimpinan = Employee::factory()->create();
        $this->setApprovers($verifikator, $pimpinan);

        $jenis = $this->jenisCuti('Cuti Tahunan');
        // Saldo hanya 1 hari, sedangkan pengajuan butuh 3 hari kerja -> final harus gagal dan tidak memotong saldo.
        LeaveBalance::create([
            'employee_id' => $pemohon['employee']->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 11,
            'sisa' => 1,
        ]);

        $cuti = $this->makeRequest($pemohon['employee'], $jenis, 3);
        $service = $this->service();

        $service->approve($cuti, $pemohon['supervisor']);
        $service->approve($cuti->fresh(), $verifikator);

        // Persetujuan final ditolak karena saldo tidak mencukupi; transaksi dibatalkan seluruhnya.
        try {
            $service->approve($cuti->fresh(), $pimpinan);
            $this->fail('Persetujuan final seharusnya gagal karena saldo tidak cukup.');
        } catch (ValidationException $e) {
            // Status tetap di tahap pimpinan dan saldo tidak terpotong.
            $this->assertSame('Menunggu Pimpinan', $cuti->fresh()->status);
            $balance = LeaveBalance::where('employee_id', $pemohon['employee']->id)->where('tahun', 2026)->first();
            $this->assertSame(11, $balance->terpakai);
            $this->assertSame(1, $balance->sisa);
        }
    }

    public function test_approve_pada_tahap_tanpa_approver_terkonfigurasi_memberi_pesan_konfigurasi(): void
    {
        $pemohon = $this->makePemohon();

        // Sengaja TIDAK memanggil setApprovers: rantai approval stage 2/3 belum dikonfigurasi.
        $jenis = $this->jenisCuti('Cuti Sakit');
        $cuti = $this->makeRequest($pemohon['employee'], $jenis, 2);
        $cuti->update(['status' => 'Menunggu Verifikator']);

        $aktor = Employee::factory()->create();

        // Tahap menunggu tanpa approver terkonfigurasi harus menghasilkan pesan konfigurasi yang jelas,
        // bukan AuthorizationException "bukan approver" yang menyesatkan.
        try {
            $this->service()->approve($cuti->fresh(), $aktor);
            $this->fail('Persetujuan seharusnya gagal karena rantai approval belum dikonfigurasi.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Konfigurasi approver cuti belum lengkap', $e->getMessage());
        }
    }
}
