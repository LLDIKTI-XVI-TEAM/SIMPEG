<?php

namespace Tests\Feature;

use App\Models\ApprovalConfig;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menguji penyimpanan konfigurasi rantai approval cuti.
 * Konfigurasi ini adalah pengaturan tingkat sistem yang hanya boleh diubah pemegang cuti.configure
 * (di Fase 1 hanya super_admin), dan setiap perubahan wajib meninggalkan jejak audit.
 */
class SaveApprovalChainConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /**
     * Membuat dua kandidat approver dengan role yang berwenang menyetujui cuti.
     *
     * @return array{0: User, 1: User}
     */
    private function buatKandidatApprover(): array
    {
        $verifikator = User::factory()->adminKepegawaian()->create(['name' => 'Verifikator Satu']);
        $pimpinan = User::factory()->pimpinan()->create(['name' => 'Pimpinan Satu']);

        return [$verifikator, $pimpinan];
    }

    public function test_super_admin_bisa_menyimpan_konfigurasi_approver(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        [$verifikator, $pimpinan] = $this->buatKandidatApprover();

        $response = $this->actingAs($superAdmin)->post(route('cuti.config.update'), [
            'stage2_approver_id' => $verifikator->id,
            'stage3_approver_id' => $pimpinan->id,
            'reason' => 'Penetapan approver awal slice cuti',
        ]);

        $response->assertRedirect(route('cuti.config'));
        $response->assertSessionHas('success');

        // Nilai konfigurasi harus benar-benar tersimpan agar dipakai engine approval berikutnya.
        $this->assertSame((string) $verifikator->id, (string) ApprovalConfig::getVal('stage2_approver_id'));
        $this->assertSame((string) $pimpinan->id, (string) ApprovalConfig::getVal('stage3_approver_id'));
    }

    public function test_perubahan_konfigurasi_tercatat_di_audit_log(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        [$verifikator, $pimpinan] = $this->buatKandidatApprover();

        $this->actingAs($superAdmin)->post(route('cuti.config.update'), [
            'stage2_approver_id' => $verifikator->id,
            'stage3_approver_id' => $pimpinan->id,
            'reason' => 'Penetapan approver awal slice cuti',
        ]);

        // Setiap kunci yang berubah harus meninggalkan satu entri audit UPDATE pada ApprovalConfig.
        // Kunci konfigurasi disimpan di payload (bukan auditable_id) karena auditable_id bertipe UUID.
        $auditStage2 = AuditLog::where('auditable_type', 'ApprovalConfig')
            ->whereJsonContains('new_values->key', 'stage2_approver_id')
            ->first();
        $this->assertNotNull($auditStage2);
        $this->assertSame('UPDATE', $auditStage2->event);

        $auditStage3 = AuditLog::where('auditable_type', 'ApprovalConfig')
            ->whereJsonContains('new_values->key', 'stage3_approver_id')
            ->first();
        $this->assertNotNull($auditStage3);

        // Nama approver baru ikut tersimpan agar audit terbaca tanpa harus me-resolve id lagi.
        $this->assertSame('Verifikator Satu', $auditStage2->new_values['approver_name']);
        $this->assertSame('Penetapan approver awal slice cuti', $auditStage2->new_values['reason']);
    }

    public function test_konfigurasi_yang_tidak_berubah_tidak_membuat_audit_baru(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        [$verifikator, $pimpinan] = $this->buatKandidatApprover();

        ApprovalConfig::setVal('stage2_approver_id', (string) $verifikator->id);
        ApprovalConfig::setVal('stage3_approver_id', (string) $pimpinan->id);

        // Menyimpan nilai yang sama persis tidak boleh menghasilkan entri audit kosong.
        $this->actingAs($superAdmin)->post(route('cuti.config.update'), [
            'stage2_approver_id' => $verifikator->id,
            'stage3_approver_id' => $pimpinan->id,
            'reason' => 'Simpan ulang tanpa perubahan',
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_non_super_admin_tidak_bisa_menyimpan_konfigurasi(): void
    {
        [$verifikator, $pimpinan] = $this->buatKandidatApprover();

        // Role berwenang approve cuti pun tidak boleh mengonfigurasi approval chain.
        foreach (['admin_kepegawaian', 'pimpinan', 'kepala_bagian', 'pegawai'] as $role) {
            $user = User::factory()->state(['role' => $role])->create();

            $response = $this->actingAs($user)->post(route('cuti.config.update'), [
                'stage2_approver_id' => $verifikator->id,
                'stage3_approver_id' => $pimpinan->id,
                'reason' => 'Percobaan oleh non super admin',
            ]);

            $response->assertForbidden();
        }

        // Tidak ada konfigurasi yang tersimpan dari percobaan non super admin.
        $this->assertNull(ApprovalConfig::getVal('stage2_approver_id'));
    }

    public function test_approver_tidak_valid_ditolak_422(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        [$verifikator] = $this->buatKandidatApprover();

        $response = $this->actingAs($superAdmin)->post(route('cuti.config.update'), [
            // ID approver stage 3 tidak menunjuk user manapun -> harus gagal validasi.
            'stage2_approver_id' => $verifikator->id,
            'stage3_approver_id' => '99999999-9999-9999-9999-999999999999',
            'reason' => 'Approver stage 3 tidak ada',
        ]);

        $response->assertSessionHasErrors('stage3_approver_id');
        $this->assertNull(ApprovalConfig::getVal('stage3_approver_id'));
    }

    public function test_alasan_wajib_diisi(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        [$verifikator, $pimpinan] = $this->buatKandidatApprover();

        $response = $this->actingAs($superAdmin)->post(route('cuti.config.update'), [
            'stage2_approver_id' => $verifikator->id,
            'stage3_approver_id' => $pimpinan->id,
            'reason' => '',
        ]);

        $response->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_approver_stage2_dan_stage3_boleh_sama(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        [$verifikator] = $this->buatKandidatApprover();

        // Approver sama antar stage sah; duplikasi ditangani skip-logic di engine approval.
        $response = $this->actingAs($superAdmin)->post(route('cuti.config.update'), [
            'stage2_approver_id' => $verifikator->id,
            'stage3_approver_id' => $verifikator->id,
            'reason' => 'Approver tunggal untuk kedua tahap',
        ]);

        $response->assertRedirect(route('cuti.config'));
        $this->assertSame((string) $verifikator->id, (string) ApprovalConfig::getVal('stage2_approver_id'));
        $this->assertSame((string) $verifikator->id, (string) ApprovalConfig::getVal('stage3_approver_id'));
    }
}
