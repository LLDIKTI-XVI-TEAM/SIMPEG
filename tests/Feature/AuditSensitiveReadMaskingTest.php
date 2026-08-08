<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit bersifat append-only sehingga baris yang sudah menyimpan nomor identitas dalam
 * bentuk terang tidak dapat diperbaiki lagi. Permukaan baca karena itu wajib menyamarkan
 * nilainya, dan test ini menirukan baris lama dengan menulis langsung ke tabel.
 */
class AuditSensitiveReadMaskingTest extends TestCase
{
    use RefreshDatabase;

    private const NIK = '3171234567890123';

    private const NO_KK = '3171999888777666';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_halaman_daftar_audit_tidak_menampilkan_nomor_identitas_baris_lama(): void
    {
        $this->barisAuditLama();
        $admin = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($admin)->get('/dashboard/audit');

        $response->assertOk();
        $response->assertDontSee(self::NIK);
        $response->assertDontSee(self::NO_KK);
        $response->assertSee('Pegawai Contoh');
    }

    public function test_halaman_detail_audit_tidak_menampilkan_nomor_identitas_baris_lama(): void
    {
        $id = $this->barisAuditLama();
        $admin = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($admin)->get('/dashboard/audit/'.$id);

        $response->assertOk();
        $response->assertDontSee(self::NIK);
        $response->assertDontSee(self::NO_KK);
    }

    public function test_endpoint_audit_tidak_mengembalikan_nomor_identitas_baris_lama(): void
    {
        $this->barisAuditLama();
        $admin = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($admin)->getJson('/api/v1/audit-log');

        $response->assertOk();
        $isi = $response->getContent();
        $this->assertIsString($isi);
        $this->assertStringNotContainsString(self::NIK, $isi);
        $this->assertStringNotContainsString(self::NO_KK, $isi);
        $this->assertStringNotContainsString(hash('sha256', self::NIK), $isi);
    }

    public function test_pembacaan_model_menyamarkan_nomor_identitas_baris_lama(): void
    {
        $id = $this->barisAuditLama();

        $log = AuditLog::query()->findOrFail($id);

        $this->assertSame('************0123', $log->new_values['nik']);
        $this->assertSame('************7666', $log->new_values['no_kk']);
        $this->assertSame('Pegawai Contoh', $log->new_values['nama_lengkap']);
        $this->assertSame('************0123', $log->old_values['nik']);
    }

    public function test_endpoint_audit_mengabaikan_penyaring_yang_bukan_tanggal_maupun_uuid(): void
    {
        $this->barisAuditLama();
        $admin = User::factory()->adminKepegawaian()->create();

        // Kolom waktu dan pengenal bertipe ketat, sehingga nilai tidak berbentuk harus
        // diperlakukan sebagai penyaring kosong alih-alih menjatuhkan permintaan.
        $response = $this->actingAs($admin)->getJson('/api/v1/audit-log?'.http_build_query([
            'from' => 'bukan-tanggal',
            'to' => '2026-13-99',
            'user_id' => 'bukan-uuid',
        ]));

        $response->assertOk();
        $response->assertJsonPath('total', 1);
    }

    /**
     * Menulis baris audit lewat query builder supaya penyamaran sisi tulis terlewati,
     * menirukan baris yang sudah tersimpan sebelum penyamaran diberlakukan.
     */
    private function barisAuditLama(): string
    {
        $id = (string) Str::uuid();

        DB::table('audit_logs')->insert([
            'id' => $id,
            'user_id' => null,
            'user_name' => 'Admin Lama',
            'event' => 'UPDATE',
            'auditable_type' => 'Employee',
            'auditable_id' => (string) Str::uuid(),
            'old_values' => json_encode([
                'nama_lengkap' => 'Pegawai Contoh',
                'nik' => self::NIK,
                'no_kk' => self::NO_KK,
                'nik_hash' => hash('sha256', self::NIK),
            ], JSON_THROW_ON_ERROR),
            'new_values' => json_encode([
                'nama_lengkap' => 'Pegawai Contoh',
                'nik' => self::NIK,
                'no_kk' => self::NO_KK,
                'nik_hash' => hash('sha256', self::NIK),
            ], JSON_THROW_ON_ERROR),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => now(),
        ]);

        return $id;
    }
}
