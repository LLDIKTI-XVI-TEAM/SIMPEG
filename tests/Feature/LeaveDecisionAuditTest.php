<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Menjaga payload audit keputusan cuti tetap dapat ditelusuri: setiap baris audit
 * keputusan wajib memuat leave_request_id dan employee_id di sisi old dan new,
 * serta merekam status sebelum/sesudah keputusan beserta metadata keputusannya.
 */
class LeaveDecisionAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Email notifikasi berjalan lewat queue; palsukan agar test fokus ke jejak audit, bukan job email.
        Queue::fake();

        $this->seed(RbacSeeder::class);
    }

    public function test_reject_menyimpan_audit_dengan_id_dan_status_sebelum_sesudah(): void
    {
        [$user, $approver, $pemohon, $cuti] = $this->buatPengajuanMenungguApproval('cuti_sakit_reject_audit');

        $komentar = 'Dokumen pendukung tidak lengkap sehingga pengajuan ditolak.';

        $response = $this->actingAs($user)->post(route('cuti.reject', ['id' => $cuti->id]), [
            'komentar' => $komentar,
        ]);

        $response->assertRedirect(route('cuti.approval'));
        $this->assertSame('tidak_disetujui', $cuti->fresh()->status);

        $audit = AuditLog::query()
            ->where('auditable_type', 'LeaveRequest')
            ->where('auditable_id', $cuti->id)
            ->where('event', 'UPDATE')
            ->firstOrFail();

        $this->assertSame([
            'leave_request_id' => $cuti->id,
            'employee_id' => $pemohon->id,
            'status' => 'menunggu_approval',
            'step_order' => 1,
            'step_label' => 'Verifikator',
            'approver_id' => $approver->id,
        ], $audit->old_values);

        // acted_at wajib terisi setelah keputusan tercatat; strict assert di bawah mengambil nilainya
        // dari payload sehingga null tidak akan tertangkap tanpa pemeriksaan eksplisit ini.
        $this->assertNotNull($audit->new_values['acted_at']);

        $this->assertSame([
            'leave_request_id' => $cuti->id,
            'employee_id' => $pemohon->id,
            'status' => 'tidak_disetujui',
            'decision' => 'REJECT',
            'step_order' => 1,
            'step_label' => 'Verifikator',
            'approver_id' => $approver->id,
            'acted_at' => $audit->new_values['acted_at'],
            'komentar' => $komentar,
        ], $audit->new_values);
    }

    public function test_request_changes_menyimpan_audit_dengan_id_dan_status_sebelum_sesudah(): void
    {
        [$user, $approver, $pemohon, $cuti] = $this->buatPengajuanMenungguApproval('cuti_sakit_reqchange_audit');

        $komentar = 'Mohon perbaiki tanggal mulai dan lampirkan surat keterangan.';

        $response = $this->actingAs($user)->post(route('cuti.request-changes', ['id' => $cuti->id]), [
            'komentar' => $komentar,
        ]);

        $response->assertRedirect(route('cuti.approval'));
        $this->assertSame('perlu_perubahan', $cuti->fresh()->status);

        $audit = AuditLog::query()
            ->where('auditable_type', 'LeaveRequest')
            ->where('auditable_id', $cuti->id)
            ->where('event', 'UPDATE')
            ->firstOrFail();

        $this->assertSame([
            'leave_request_id' => $cuti->id,
            'employee_id' => $pemohon->id,
            'status' => 'menunggu_approval',
            'step_order' => 1,
            'step_label' => 'Verifikator',
            'approver_id' => $approver->id,
        ], $audit->old_values);

        // acted_at wajib terisi setelah keputusan tercatat; strict assert di bawah mengambil nilainya
        // dari payload sehingga null tidak akan tertangkap tanpa pemeriksaan eksplisit ini.
        $this->assertNotNull($audit->new_values['acted_at']);

        $this->assertSame([
            'leave_request_id' => $cuti->id,
            'employee_id' => $pemohon->id,
            'status' => 'perlu_perubahan',
            'decision' => 'REQUEST_CHANGES',
            'step_order' => 1,
            'step_label' => 'Verifikator',
            'approver_id' => $approver->id,
            'acted_at' => $audit->new_values['acted_at'],
            'komentar' => $komentar,
        ], $audit->new_values);
    }

    /**
     * Menyiapkan pengajuan cuti menunggu approval dengan approver snapshot aktif sebagai step final.
     * Pola fixture mengikuti CutiRbacTest agar konsisten dengan skenario keputusan yang sudah ada.
     *
     * @return array{0: User, 1: Employee, 2: Employee, 3: LeaveRequest}
     */
    private function buatPengajuanMenungguApproval(string $jenisCode): array
    {
        $approver = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $approver->id]);
        $pemohon = Employee::factory()->create();

        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Sakit',
            'code' => $jenisCode,
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);

        $cuti = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-06',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
        ]);

        LeaveRequestStep::create([
            'leave_request_id' => $cuti->id,
            'step_order' => 1,
            'step_type' => 'verifikator',
            'role_label' => 'Verifikator',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        return [$user, $approver, $pemohon, $cuti];
    }
}
