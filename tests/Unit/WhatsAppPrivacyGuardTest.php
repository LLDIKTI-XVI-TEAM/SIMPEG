<?php

namespace Tests\Unit;

use App\Services\Notifications\WhatsApp\WhatsAppPrivacyGuard;
use PHPUnit\Framework\TestCase;

class WhatsAppPrivacyGuardTest extends TestCase
{
    private WhatsAppPrivacyGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new WhatsAppPrivacyGuard;
    }

    public function test_teks_aman_dibersihkan_dan_lolos_validasi(): void
    {
        $input = "  Permohonan cuti tahunan telah disetujui sesuai usulan pimpinan. \n\n ";
        $sanitized = $this->guard->sanitize($input);

        $this->assertSame('Permohonan cuti tahunan telah disetujui sesuai usulan pimpinan.', $sanitized);
        $this->assertTrue($this->guard->isSafe($sanitized));
        $this->assertTrue($this->guard->isSafe('Cuti 3 hari mulai 2026-09-01 sampai 2026-09-03'));
    }

    public function test_pola_16_digit_nik_atau_kk_ditolak_fail_closed(): void
    {
        $textWithNik = 'Persetujuan cuti untuk NIK 7171012304950001 ditangguhkan.';

        $this->assertFalse($this->guard->isSafe($textWithNik));
        $this->assertFalse($this->guard->isSafe('7171012304950001'));
        $this->assertFalse($this->guard->isSafe('NIK: 7171-0123-0495-0001'));
        $this->assertFalse($this->guard->isSafe('KTP: 7171 0123 0495 0001'));
        $this->assertFalse($this->guard->isSafe('No KK: 7171.0123.0495.0001'));
        $this->assertFalse($this->guard->isSafe('Catatan 717101-230495-0001'));
    }

    public function test_pola_token_atau_kata_sandi_ditolak(): void
    {
        $this->assertFalse($this->guard->isSafe('Token akses Anda: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'));
        $this->assertFalse($this->guard->isSafe('Gunakan password: SuperSecret123! untuk login'));
        $this->assertFalse($this->guard->isSafe('Bearer 1234567890abcdef1234567890abcdef'));
    }

    public function test_pola_data_finansial_rahasia_ditolak(): void
    {
        $this->assertFalse($this->guard->isSafe('Nomor rekening 1234567890123'));
        $this->assertFalse($this->guard->isSafe('No. Rekening BCA: 123-456-7890'));
        $this->assertFalse($this->guard->isSafe('No Rek: 987654321'));
        $this->assertFalse($this->guard->isSafe('Pembayaran via VA: 880812345678'));
        $this->assertFalse($this->guard->isSafe('PIN: 123456'));
        $this->assertFalse($this->guard->isSafe('CVV: 123'));
        $this->assertFalse($this->guard->isSafe('Kartu Kredit: 4111 1111 1111 1111'));
    }

    public function test_variabel_map_aman_diverifikasi(): void
    {
        $safeVariables = [
            'nama_pegawai' => 'Dr. Jane Doe, M.Pd.',
            'jenis_cuti' => 'Cuti Tahunan',
            'status' => 'Disetujui',
            'keterangan' => 'Disetujui sesuai usulan.',
            'tautan_detail' => 'https://simpeg.lldikti16.kemdikbud.go.id/dashboard/cuti/abc-123',
        ];

        $this->assertTrue($this->guard->areVariablesSafe($safeVariables));

        $unsafeVariables = $safeVariables;
        $unsafeVariables['keterangan'] = 'KTP pemohon: 3201-0123-0490-0005';

        $this->assertFalse($this->guard->areVariablesSafe($unsafeVariables));
    }

    public function test_sanitasi_menghapus_karakter_kontrol_dan_tag_html(): void
    {
        $input = "<script>alert('xss')</script>Disetujui <b>resmi</b>\r\nCatatan khusus.";
        $sanitized = $this->guard->sanitize($input);

        $this->assertSame('Disetujui resmi Catatan khusus.', $sanitized);
    }
}
