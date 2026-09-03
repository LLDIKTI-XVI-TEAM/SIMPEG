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

    public function test_decline_menyimpan_audit_dengan_id_dan_status_sebelum_sesudah(): void
    {
        [$user, $approver, $pemohon, $cuti] = $this->buatPengajuanMenungguApproval('cuti_sakit_decline_audit');
        $cuti->steps()->update([
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
        ]);

        $komentar = 'Dokumen pendukung tidak lengkap sehingga pengajuan tidak disetujui.';

        $url = route('cuti.decline', ['id' => $cuti->id]);
        $this->assertSame("/cuti/{$cuti->id}/decline", parse_url($url, PHP_URL_PATH));

        $response = $this->actingAs($user)->post($url, [
            'active_step_id' => $cuti->steps()->where('status', 'active')->valueOrFail('id'),
            'komentar' => $komentar,
        ]);

        $response->assertRedirect(route('cuti.approval'));
        $this->assertSame('tidak_disetujui', $cuti->fresh()->status);

        $audit = AuditLog::query()
            ->where('auditable_type', 'LeaveRequest')
            ->where('auditable_id', $cuti->id)
            ->where('event', 'NOT_APPROVED')
            ->firstOrFail();

        $this->assertSame([
            'leave_request_id' => $cuti->id,
            'employee_id' => $pemohon->id,
            'status' => 'menunggu_approval',
            'step_order' => 1,
            'step_label' => 'Atasan Langsung',
            'approver_id' => $approver->id,
        ], $audit->old_values);

        // acted_at wajib terisi setelah keputusan tercatat; strict assert di bawah mengambil nilainya
        // dari payload sehingga null tidak akan tertangkap tanpa pemeriksaan eksplisit ini.
        $this->assertNotNull($audit->new_values['acted_at']);

        $this->assertSame([
            'leave_request_id' => $cuti->id,
            'employee_id' => $pemohon->id,
            'status' => 'tidak_disetujui',
            'decision' => 'NOT_APPROVED',
            'step_order' => 1,
            'step_label' => 'Atasan Langsung',
            'approver_id' => $approver->id,
            'acted_at' => $audit->new_values['acted_at'],
            'komentar' => $komentar,
            '_effective_role' => 'pegawai',
        ], $audit->new_values);
    }

    public function test_request_changes_menyimpan_audit_dengan_id_dan_status_sebelum_sesudah(): void
    {
        [$user, $approver, $pemohon, $cuti] = $this->buatPengajuanMenungguApproval('cuti_sakit_reqchange_audit');

        $komentar = 'Mohon perbaiki tanggal mulai dan lampirkan surat keterangan.';

        $response = $this->actingAs($user)->post(route('cuti.request-changes', ['id' => $cuti->id]), [
            'active_step_id' => $cuti->steps()->where('status', 'active')->valueOrFail('id'),
            'komentar' => $komentar,
        ]);

        $response->assertRedirect(route('cuti.approval'));
        $this->assertSame('perlu_perubahan', $cuti->fresh()->status);

        $audit = AuditLog::query()
            ->where('auditable_type', 'LeaveRequest')
            ->where('auditable_id', $cuti->id)
            ->where('event', 'CHANGE_REQUESTED')
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
            'decision' => 'CHANGE_REQUESTED',
            'step_order' => 1,
            'step_label' => 'Verifikator',
            'approver_id' => $approver->id,
            'acted_at' => $audit->new_values['acted_at'],
            'komentar' => $komentar,
            '_effective_role' => 'pegawai',
        ], $audit->new_values);
    }

    public function test_persetujuan_tahap_non_final_tercatat_sebagai_verifikasi(): void
    {
        [$user, $approver, $pemohon, $cuti] = $this->buatPengajuanDuaTahap('cuti_sakit_verify_audit');

        $response = $this->actingAs($user)->post(route('cuti.approve', ['id' => $cuti->id]), [
            'active_step_id' => $cuti->steps()->where('status', 'active')->valueOrFail('id'),
            'komentar' => 'Diteruskan ke tahap berikutnya.',
        ]);

        $response->assertRedirect(route('cuti.approval'));
        // Tahap pertama bukan tahap akhir sehingga pengajuan masih menunggu keputusan berikutnya.
        $this->assertSame('menunggu_approval', $cuti->fresh()->status);

        $audit = $this->auditKeputusan($cuti->id, 'VERIFY');
        $this->assertSame('VERIFY', $audit->new_values['decision']);
        $this->assertSame($pemohon->id, $audit->new_values['employee_id']);
        $this->assertSame($approver->id, $audit->new_values['approver_id']);
    }

    public function test_persetujuan_tahap_final_tercatat_sebagai_keputusan(): void
    {
        [$user, , , $cuti] = $this->buatPengajuanMenungguApproval('cuti_sakit_decide_audit');

        $response = $this->actingAs($user)->post(route('cuti.approve', ['id' => $cuti->id]), [
            'active_step_id' => $cuti->steps()->where('status', 'active')->valueOrFail('id'),
            'komentar' => 'Disetujui.',
        ]);

        $response->assertRedirect(route('cuti.approval'));
        $this->assertSame('disetujui', $cuti->fresh()->status);

        $audit = $this->auditKeputusan($cuti->id, 'DECIDE');
        $this->assertSame('DECIDE', $audit->new_values['decision']);
        $this->assertSame('disetujui', $audit->new_values['status']);
    }

    public function test_penangguhan_tercatat_sebagai_penangguhan(): void
    {
        [$user, , , $cuti] = $this->buatPengajuanMenungguApproval('cuti_sakit_defer_audit');

        $response = $this->actingAs($user)->post(route('cuti.postpone', ['id' => $cuti->id]), [
            'active_step_id' => $cuti->steps()->where('status', 'active')->valueOrFail('id'),
            'komentar' => 'Ditangguhkan karena kebutuhan unit kerja.',
        ]);

        $response->assertRedirect(route('cuti.approval'));
        $this->assertSame('ditangguhkan', $cuti->fresh()->status);

        $audit = $this->auditKeputusan($cuti->id, 'DEFER');
        $this->assertSame('DEFER', $audit->new_values['decision']);
    }

    public function test_perubahan_dan_tidak_disetujui_terpisah_lewat_filter_event(): void
    {
        [$userPerubahan, , , $cutiPerubahan] = $this->buatPengajuanMenungguApproval('cuti_sakit_filter_ubah');
        $this->actingAs($userPerubahan)->post(route('cuti.request-changes', ['id' => $cutiPerubahan->id]), [
            'active_step_id' => $cutiPerubahan->steps()->where('status', 'active')->valueOrFail('id'),
            'komentar' => 'Mohon perbaiki tanggal pengajuan.',
        ]);

        [$userTolak, , , $cutiTolak] = $this->buatPengajuanMenungguApproval('cuti_sakit_filter_tolak');
        $this->actingAs($userTolak)->post(route('cuti.decline', ['id' => $cutiTolak->id]), [
            'active_step_id' => $cutiTolak->steps()->where('status', 'active')->valueOrFail('id'),
            'komentar' => 'Kuota unit kerja tidak memungkinkan.',
        ]);

        // Kedua keputusan wajib memakai kosakata berbeda supaya penyaringan audit tidak mencampur
        // permintaan perubahan dengan penolakan.
        $idPerubahan = AuditLog::query()->where('event', 'CHANGE_REQUESTED')->pluck('auditable_id');
        $idTolak = AuditLog::query()->where('event', 'NOT_APPROVED')->pluck('auditable_id');

        $this->assertTrue($idPerubahan->contains($cutiPerubahan->id));
        $this->assertFalse($idPerubahan->contains($cutiTolak->id));
        $this->assertTrue($idTolak->contains($cutiTolak->id));
        $this->assertFalse($idTolak->contains($cutiPerubahan->id));
    }

    private function auditKeputusan(string $leaveRequestId, string $event): AuditLog
    {
        return AuditLog::query()
            ->where('auditable_type', 'LeaveRequest')
            ->where('auditable_id', $leaveRequestId)
            ->where('event', $event)
            ->firstOrFail();
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

    /**
     * Menyiapkan pengajuan dengan dua tahap approval sehingga tahap pertama bukan tahap akhir.
     * Dipakai untuk memisahkan persetujuan antara yang meneruskan berkas dan yang memutus final.
     *
     * @return array{0: User, 1: Employee, 2: Employee, 3: LeaveRequest}
     */
    private function buatPengajuanDuaTahap(string $jenisCode): array
    {
        [$user, $approver, $pemohon, $cuti] = $this->buatPengajuanMenungguApproval($jenisCode);

        $cuti->steps()->where('step_order', 1)->update(['is_final' => false]);

        LeaveRequestStep::create([
            'leave_request_id' => $cuti->id,
            'step_order' => 2,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => Employee::factory()->create()->id,
            'status' => 'pending',
            'is_final' => true,
        ]);

        return [$user, $approver, $pemohon, $cuti];
    }
}
