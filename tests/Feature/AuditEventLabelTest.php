<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditLogViewPayload;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Permukaan baca audit harus menerima dua kosakata sekaligus. Baris lama memakai APPROVE dan
 * POSTPONE, sedangkan baris baru memakai kosakata keputusan resmi. Karena audit tidak dapat
 * ditulis ulang, keduanya akan hidup bersama selamanya dan harus terbaca dengan istilah resmi.
 */
class AuditEventLabelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pemetaanLabel(): array
    {
        return [
            'verifikasi tahap menengah' => ['VERIFY', 'Diverifikasi'],
            'keputusan akhir' => ['DECIDE', 'Disetujui'],
            'persetujuan kosakata lama' => ['APPROVE', 'Disetujui'],
            'permintaan perubahan' => ['CHANGE_REQUESTED', 'Perubahan'],
            'penangguhan' => ['DEFER', 'Ditangguhkan'],
            'penangguhan kosakata lama' => ['POSTPONE', 'Ditangguhkan'],
            'tidak disetujui' => ['NOT_APPROVED', 'Tidak Disetujui'],
        ];
    }

    #[DataProvider('pemetaanLabel')]
    public function test_event_keputusan_ditampilkan_dengan_istilah_resmi(string $event, string $label): void
    {
        $log = $this->auditLog($event);

        $payload = AuditLogViewPayload::forView($log);

        $this->assertSame($label, $payload['event_label']);
        // Nilai mentah tetap disertakan agar penyaringan dan penelusuran tidak kehilangan ketelitian.
        $this->assertSame($event, $payload['event']);
    }

    public function test_event_tanpa_padanan_resmi_ditampilkan_apa_adanya(): void
    {
        $payload = AuditLogViewPayload::forView($this->auditLog('IMPORT'));

        $this->assertSame('IMPORT', $payload['event_label']);
    }

    /** Lifecycle identitas SSO adalah autentikasi: tampil di kategori autentikasi, bukan aktivitas_sistem. */
    public function test_event_identitas_sso_terklasifikasi_autentikasi(): void
    {
        foreach (['SSO_BINDING', 'SSO_MAPPING_REJECTED'] as $event) {
            $payload = AuditLogViewPayload::forView($this->auditLog($event, 'User'));

            $this->assertSame('autentikasi', $payload['kategori']);
            $this->assertSame($event, $payload['event']);
        }
    }

    public function test_label_tidak_memakai_kata_ditolak(): void
    {
        foreach (['NOT_APPROVED', 'CHANGE_REQUESTED', 'DEFER'] as $event) {
            $payload = AuditLogViewPayload::forView($this->auditLog($event));

            // Istilah resmi keputusan cuti tidak mengenal kata Ditolak.
            $this->assertStringNotContainsStringIgnoringCase('ditolak', $payload['event_label']);
        }
    }

    public function test_halaman_audit_menampilkan_istilah_resmi_untuk_event_baru(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = User::factory()->superAdmin()->create();
        $keputusan = $this->auditLog('DECIDE');
        $this->auditLog('NOT_APPROVED');

        $daftar = $this->actingAs($admin)->get(route('audit-log'));
        $daftar->assertOk();
        $daftar->assertSee('Disetujui');
        $daftar->assertSee('Tidak Disetujui');

        $detail = $this->actingAs($admin)->get(route('audit-log.show', ['id' => $keputusan->id]));
        $detail->assertOk();
        $detail->assertSee('Disetujui');
    }

    public function test_approve_lama_tahap_menengah_terbaca_sebagai_diverifikasi(): void
    {
        // Sebelum kosakata dipisah, APPROVE dipakai untuk tahap menengah maupun tahap final.
        // Baris lama tidak dapat dibackfill, sehingga labelnya ditentukan dari status hasil keputusan.
        $log = $this->auditLogDenganStatus('APPROVE', 'menunggu_approval');

        $this->assertSame('Diverifikasi', AuditLogViewPayload::forView($log)['event_label']);
    }

    public function test_approve_lama_tahap_final_terbaca_sebagai_disetujui(): void
    {
        $log = $this->auditLogDenganStatus('APPROVE', 'disetujui');

        $this->assertSame('Disetujui', AuditLogViewPayload::forView($log)['event_label']);
    }

    public function test_approve_lama_tanpa_status_tetap_terbaca_sebagai_disetujui(): void
    {
        // Bila status hasil tidak tersedia, label dipertahankan seperti sebelum perubahan ini
        // agar baris lama tidak berpindah makna tanpa dasar.
        $log = $this->auditLogDenganStatus('APPROVE', null);

        $this->assertSame('Disetujui', AuditLogViewPayload::forView($log)['event_label']);
    }

    private function auditLogDenganStatus(string $event, ?string $status): AuditLog
    {
        return AuditLog::query()->create([
            'user_id' => null,
            'user_name' => 'Petugas Uji',
            'event' => $event,
            'auditable_type' => 'LeaveRequest',
            'auditable_id' => (string) Str::uuid(),
            'old_values' => ['status' => 'menunggu_approval'],
            'new_values' => $status === null ? ['step_order' => 1] : ['status' => $status],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);
    }

    private function auditLog(string $event, string $auditableType = 'LeaveRequest'): AuditLog
    {
        return AuditLog::query()->create([
            'user_id' => null,
            'user_name' => 'Petugas Uji',
            'event' => $event,
            'auditable_type' => $auditableType,
            'auditable_id' => (string) Str::uuid(),
            'old_values' => null,
            'new_values' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);
    }
}
