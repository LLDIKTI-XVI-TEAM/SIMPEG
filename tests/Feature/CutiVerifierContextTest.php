<?php

namespace Tests\Feature;

use App\Actions\Cuti\BuildVerifierLeaveContextAction;
use App\Models\Employee;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\RefHariLibur;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceReservationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mengunci konteks keputusan pada halaman detail cuti: approver aktif dan pemantau
 * ber-hak baca penuh wajib melihat saldo berjalan, sisa hak N-1/N-2, cuti bersama,
 * dan riwayat cuti tahunan pemohon. Pemohon biasa tidak boleh melihat panel ini
 * karena datanya bukan milik konteks pengambilan keputusan dirinya.
 */
class CutiVerifierContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_approver_aktif_melihat_konteks_saldo_cuti_bersama_dan_riwayat_pemohon(): void
    {
        $jenis = $this->jenisTahunan();
        $employee = Employee::factory()->create();
        $approver = Employee::factory()->create();
        $approverUser = User::factory()->kepalaBagian()->create(['employee_id' => $approver->id]);

        // Saldo tahun acuan dengan bucket N-2/N-1 terpisah agar keduanya wajib tampil sendiri.
        LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 9,
            'terpakai' => 2,
            'sisa' => 19,
            'sisa_n2' => 3,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 10,
            'terpakai_tahun_berjalan' => 2,
            'hangus' => 0,
        ]);
        LeaveBalance::create(['employee_id' => $employee->id, 'tahun' => 2025, 'jatah_awal' => 12, 'carry_over' => 0, 'terpakai' => 4, 'sisa' => 8]);
        LeaveBalance::create(['employee_id' => $employee->id, 'tahun' => 2024, 'jatah_awal' => 12, 'carry_over' => 0, 'terpakai' => 5, 'sisa' => 7]);

        RefHariLibur::create([
            'tanggal' => '2026-03-30',
            'nama' => 'Cuti Bersama Uji Verifikator',
            'tahun' => 2026,
            'is_cuti_bersama' => true,
        ]);

        // Riwayat final yang wajib tampil; pengajuan menunggu tidak boleh masuk daftar ini.
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2025-06-02',
            'tanggal_selesai' => '2025-06-10',
            'jumlah_hari_kerja' => 7,
            'alasan' => 'Riwayat tahun lalu',
            'status' => 'disetujui',
        ]);

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-11',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Pengajuan yang sedang diputus',
            'status' => 'menunggu_approval',
        ]);
        $leaveRequest->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);
        LeaveApproval::create([
            'leave_request_id' => $leaveRequest->id,
            'approver_id' => $approver->id,
            'stage' => 1,
            'action' => 'APPROVE',
            'komentar' => 'Persetujuan sebelumnya.',
            'acted_at' => now(),
        ]);

        $response = $this->actingAs($approverUser)->get(route('cuti.show', $leaveRequest->id));

        $response->assertOk();
        $response->assertSee('Informasi Saldo & Riwayat Cuti Pemohon', false);
        $response->assertSee('Saldo Dapat Diajukan (N=2026)', false);
        $response->assertSee('19 Hari', false);
        $response->assertSee('Sisa Hak N-1 (2025)', false);
        $response->assertSee('Sisa Hak N-2 (2024)', false);
        $response->assertSee('Cuti Bersama Uji Verifikator', false);
        $response->assertSee('Riwayat Cuti Tahunan Disetujui', false);
        $response->assertSee('7 hari kerja', false);
        $response->assertSee('Tidak ada lampiran yang dilampirkan pemohon.', false);
        $response->assertSee('Ditangguhkan', false);
        $response->assertSee('Perubahan', false);
        $response->assertSee('Tidak Disetujui', false);
        $response->assertSee('Disetujui', false);
        $response->assertSee('Riwayat Tindakan Approval', false);
        $response->assertDontSee('Tunda Sementara', false);
        $response->assertDontSee('Tidak Setujui', false);

        $content = $response->getContent();
        $verifierContextPosition = strpos($content, 'Informasi Saldo &amp; Riwayat Cuti Pemohon');
        $decisionActionPosition = strpos($content, 'Ditangguhkan');

        $this->assertNotFalse($verifierContextPosition);
        $this->assertNotFalse($decisionActionPosition);
        $this->assertLessThan($decisionActionPosition, $verifierContextPosition);
        $this->assertSame(3, preg_match_all('/<textarea\\b(?=[^>]*\\bname="komentar")(?=[^>]*\\brequired\\b)[^>]*>/', $content));
        $this->assertMatchesRegularExpression('/>\\s*Disetujui\\s*</', $content);
        $this->assertDoesNotMatchRegularExpression('/>\\s*Setuju\\s*</', $content);
    }

    public function test_verifikator_melihat_tautan_lampiran_bila_pemohon_mengunggahkannya(): void
    {
        $jenis = $this->jenisTahunan();
        $employee = Employee::factory()->create();
        $approver = Employee::factory()->create();
        $approverUser = User::factory()->kepalaBagian()->create(['employee_id' => $approver->id]);

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-11',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Pengajuan dengan lampiran.',
            'lampiran_path' => 'cuti/lampiran-verifikator.pdf',
            'status' => 'menunggu_approval',
        ]);
        $leaveRequest->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        $response = $this->actingAs($approverUser)->get(route('cuti.show', $leaveRequest->id));

        $response->assertOk();
        $response->assertSee('Lihat lampiran', false);
        $response->assertDontSee('Tidak ada lampiran yang dilampirkan pemohon.', false);
        $response->assertSee('storage/cuti/lampiran-verifikator.pdf', false);
    }

    public function test_pemantau_berhak_baca_semua_tetap_melihat_konteks_meski_bukan_approver(): void
    {
        $jenis = $this->jenisTahunan();
        $employee = Employee::factory()->create();
        $approver = Employee::factory()->create();

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-11',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Pengajuan untuk pemantau',
            'status' => 'menunggu_approval',
        ]);
        $leaveRequest->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('cuti.show', $leaveRequest->id));

        $response->assertOk();
        $response->assertSee('Informasi Saldo & Riwayat Cuti Pemohon', false);
        // Tanpa baris saldo sama sekali, panel tetap jujur menampilkan keadaan kosong.
        $response->assertSee('Tidak ada cuti bersama terdaftar pada tahun ini.', false);
        $response->assertSee('Belum ada riwayat cuti tahunan yang disetujui.', false);
    }

    public function test_saldo_verifikator_mengecualikan_reservasi_pengajuan_yang_sedang_diperiksa(): void
    {
        $jenis = $this->jenisTahunan();
        $employee = Employee::factory()->create();
        $approver = Employee::factory()->create();
        $approverUser = User::factory()->kepalaBagian()->create(['employee_id' => $approver->id]);

        LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);

        $otherLeaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-09-07',
            'tanggal_selesai' => '2026-09-08',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Pengajuan aktif lain',
            'status' => 'menunggu_approval',
        ]);
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-21',
            'jumlah_hari_kerja' => 10,
            'alasan' => 'Pengajuan dengan reservasi aktif',
            'status' => 'menunggu_approval',
        ]);
        $leaveRequest->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        // Gunakan service produksi agar regresi mencakup event reservasi yang dibuat saat submit.
        $reservations = app(LeaveBalanceReservationService::class);
        $reservations->reserveForNewRequest($otherLeaveRequest);
        $reservations->reserveForNewRequest($leaveRequest);

        $response = $this->actingAs($approverUser)->get(route('cuti.show', $leaveRequest->id));

        $response->assertOk();
        $response->assertViewHas('verifierContext', function (array $context): bool {
            return $context['balance']['saldo_aktual'] === 12
                && $context['balance']['dialokasikan_aktif'] === 2
                && $context['balance']['saldo_dapat_diajukan'] === 10;
        });
        $response->assertSee('Saldo aktual 12 · 2 dialokasikan · 0 terpakai', false);

        $this->assertDatabaseHas('leave_balance_reservation_events', [
            'leave_request_id' => $leaveRequest->id,
            'amount' => 10,
        ]);
        $this->assertDatabaseHas('leave_balance_reservation_events', [
            'leave_request_id' => $otherLeaveRequest->id,
            'amount' => 2,
        ]);
    }

    public function test_pemohon_biasa_tidak_melihat_panel_konteks_verifikator(): void
    {
        $jenis = $this->jenisTahunan();
        $employee = Employee::factory()->create();
        $approver = Employee::factory()->create();

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-11',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Pengajuan milik sendiri',
            'status' => 'menunggu_approval',
        ]);
        $leaveRequest->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        $pemohon = User::factory()->create(['employee_id' => $employee->id]);
        $pemohon->update(['role' => 'pegawai']);

        $response = $this->actingAs($pemohon)->get(route('cuti.show', $leaveRequest->id));

        $response->assertOk();
        $response->assertDontSee('Informasi Saldo & Riwayat Cuti Pemohon', false);
    }

    public function test_riwayat_verifikator_tetap_memuat_tahun_n1_dan_n2_saat_tahun_berjalan_melebihi_lima_record(): void
    {
        $jenis = $this->jenisTahunan();
        $employee = Employee::factory()->create();

        foreach (range(1, 6) as $month) {
            LeaveRequest::create([
                'employee_id' => $employee->id,
                'jenis_cuti_id' => $jenis->id,
                'tanggal_mulai' => sprintf('2026-%02d-10', $month),
                'tanggal_selesai' => sprintf('2026-%02d-10', $month),
                'jumlah_hari_kerja' => 1,
                'alasan' => "Riwayat tahun berjalan {$month}",
                'status' => 'disetujui',
            ]);
        }

        foreach ([2025, 2024] as $year) {
            LeaveRequest::create([
                'employee_id' => $employee->id,
                'jenis_cuti_id' => $jenis->id,
                'tanggal_mulai' => "{$year}-01-10",
                'tanggal_selesai' => "{$year}-01-10",
                'jumlah_hari_kerja' => 1,
                'alasan' => "Riwayat tahun {$year}",
                'status' => 'disetujui',
            ]);
        }

        $context = app(BuildVerifierLeaveContextAction::class)
            ->execute($employee, now()->setDate(2026, 8, 10));
        $years = $context['riwayatTahunan']
            ->map(fn (LeaveRequest $request): int => $request->tanggal_mulai->year)
            ->unique()
            ->values()
            ->all();

        $this->assertSame([2026, 2025, 2024], $years);
    }

    private function jenisTahunan(): RefJenisCuti
    {
        return RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
    }
}
