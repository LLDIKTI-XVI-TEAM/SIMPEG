<?php

namespace Tests\Feature;

use App\Actions\Cuti\ApplyChainTemplateToUnitAction;
use App\Actions\Cuti\SaveEmployeeApprovalChainAction;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveRequest;
use App\Models\PositionHistory;
use App\Models\RefJenisCuti;
use App\Models\RefUnitKerja;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Menguji penyalinan konfigurasi rantai approval cuti dari satu pegawai ke seluruh anggota unit kerja.
 * Keanggotaan unit diturunkan dari riwayat jabatan terkini karena pegawai tidak menyimpan unit kerja.
 */
class ApplyChainTemplateToUnitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Rute konfigurasi chain digerbang permission, jadi matriks RBAC wajib terisi lebih dulu.
        $this->seed(RbacSeeder::class);
    }

    public function test_seluruh_anggota_unit_menerima_rantai_dari_pegawai_sumber(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $unitLain = $this->unit('Bagian Umum');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $anggota = $this->pegawaiUnit($unit, 'Anggota Unit');
        $luar = $this->pegawaiUnit($unitLain, 'Pegawai Unit Lain');

        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);

        $hasil = $this->terapkan($unit, $sumber, $aktor);

        $this->assertSame([$anggota->id], $hasil['applied_employee_ids']);
        $this->assertDatabaseHas('leave_approval_chains', ['employee_id' => $anggota->id, 'is_active' => true]);
        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $luar->id]);

        $langkah = LeaveApprovalChain::query()
            ->where('employee_id', $anggota->id)
            ->where('is_active', true)
            ->sole()
            ->steps()
            ->orderBy('step_order')
            ->get();

        $this->assertSame(['kepala_bagian', 'verifier', 'pybmc'], $langkah->pluck('step_type')->all());
        $this->assertSame($verifikator->id, $langkah[1]->approver_employee_id);
        $this->assertSame($pybmc->id, $langkah[2]->approver_employee_id);
    }

    public function test_unit_lebih_dari_satu_chunk_diproses_seluruhnya(): void
    {
        // chunkById memaginasi dengan kondisi id lebih besar dari id terakhir, sehingga urutan lain
        // pada kueri akan membuat sebagian pegawai terlewat begitu unit melewati satu chunk.
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');

        $jumlahAnggota = 150;

        for ($i = 1; $i <= $jumlahAnggota; $i++) {
            $this->pegawaiUnit($unit, sprintf('Anggota %03d', $i));
        }

        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);

        $hasil = $this->terapkan($unit, $sumber, $aktor);

        $this->assertCount($jumlahAnggota, $hasil['applied_employee_ids']);
        $this->assertSame(
            $jumlahAnggota,
            LeaveApprovalChain::query()->where('is_active', true)->where('employee_id', '!=', $sumber->id)->count(),
        );
    }

    public function test_penerapan_ditolak_bila_approver_template_sudah_nonaktif(): void
    {
        // Form konfigurasi per pegawai hanya menerima approver aktif, jadi penyalinan massal tidak
        // boleh menyebarkan approver nonaktif ke seluruh unit lewat template yang sudah kedaluwarsa.
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $anggota = $this->pegawaiUnit($unit, 'Anggota Unit');

        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);

        $verifikator->update(['status_aktif' => 'Pensiun']);

        try {
            $this->terapkan($unit, $sumber, $aktor);
            $this->fail('Penerapan seharusnya ditolak karena approver template sudah nonaktif.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nonaktif', $exception->getMessage());
        }

        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $anggota->id]);
    }

    public function test_endpoint_menolak_template_dengan_approver_nonaktif_sebagai_galat_validasi(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $anggota = $this->pegawaiUnit($unit, 'Anggota Unit');

        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);

        $pybmc->update(['status_aktif' => 'Pensiun']);

        $this->actingAs($aktor)
            ->post(route('cuti.config.unit-template.apply'), [
                'unit_kerja_id' => $unit->id,
                'source_employee_id' => $sumber->id,
                'template_reason' => 'Menyeragamkan chain unit keuangan.',
            ])
            ->assertSessionHasErrors('source_employee_id');

        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $anggota->id]);
    }

    public function test_pegawai_dengan_kepala_bagian_nonaktif_dilewati(): void
    {
        // Penugasan atasan bisa tetap efektif walau orangnya sudah pensiun. Form per pegawai hanya
        // menerima approver aktif, jadi rantai dengan kepala bagian nonaktif tidak boleh dibuat di
        // sini karena admin sendiri tidak dapat menyimpan rantai serupa secara manual.
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $anggota = $this->pegawaiUnit($unit, 'Anggota Unit');

        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);

        Employee::query()->whereKey($anggota->kepala_bagian_id)->update(['status_aktif' => 'Pensiun']);

        $hasil = $this->terapkan($unit, $sumber, $aktor);

        $this->assertSame([], $hasil['applied_employee_ids']);
        $this->assertContains($anggota->id, $hasil['skipped_missing_kepala_bagian_employee_ids']);
        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $anggota->id]);
    }

    public function test_pengenal_sumber_cacat_tidak_menjadi_galat_basis_data(): void
    {
        // PostgreSQL menolak perbandingan kolom uuid dengan teks sembarang, jadi pengenal cacat harus
        // berhenti pada validasi sebelum menyentuh kueri apa pun.
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');

        $this->actingAs($aktor)
            ->post(route('cuti.config.unit-template.apply'), [
                'unit_kerja_id' => $unit->id,
                'source_employee_id' => 'bukan-uuid',
                'template_reason' => 'Alasan cukup panjang.',
            ])
            ->assertSessionHasErrors('source_employee_id');
    }

    public function test_penerapan_ditolak_bila_langkah_template_kehilangan_approver(): void
    {
        // Kunci asing approver memakai SET NULL, jadi penghapusan permanen pegawai meninggalkan
        // langkah tanpa approver. Langkah seperti itu tidak dapat disalin dan harus menghentikan
        // penerapan dengan galat yang terbaca, bukan galat kolom uuid di tengah penyimpanan.
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $anggota = $this->pegawaiUnit($unit, 'Anggota Unit');

        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);

        DB::table('leave_approval_chain_steps')
            ->where('approver_employee_id', $verifikator->id)
            ->update(['approver_employee_id' => null]);

        try {
            $this->terapkan($unit, $sumber, $aktor);
            $this->fail('Penerapan seharusnya ditolak karena ada langkah tanpa approver.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('approver', $exception->getMessage());
        }

        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $anggota->id]);
    }

    public function test_endpoint_menolak_template_yang_kehilangan_approver(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $anggota = $this->pegawaiUnit($unit, 'Anggota Unit');

        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);

        DB::table('leave_approval_chain_steps')
            ->where('approver_employee_id', $pybmc->id)
            ->update(['approver_employee_id' => null]);

        $this->actingAs($aktor)
            ->post(route('cuti.config.unit-template.apply'), [
                'unit_kerja_id' => $unit->id,
                'source_employee_id' => $sumber->id,
                'template_reason' => 'Menyeragamkan chain unit keuangan.',
            ])
            ->assertSessionHasErrors('source_employee_id');

        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $anggota->id]);
    }

    public function test_kepala_bagian_sumber_yang_nonaktif_tidak_memblokir_penerapan(): void
    {
        // Approver pada langkah kepala bagian selalu diganti dengan atasan pegawai tujuan, baik oleh
        // aksi ini maupun oleh resolver saat pengajuan dibentuk, sehingga id lama pada chain sumber
        // tidak pernah disalin. Snapshot kepala bagian sumber yang usang karena rotasi jabatan tidak
        // boleh membatalkan penerapan yang sebenarnya sah.
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $anggota = $this->pegawaiUnit($unit, 'Anggota Unit');

        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);

        Employee::query()->whereKey($sumber->kepala_bagian_id)->update(['status_aktif' => 'Pensiun']);

        $hasil = $this->terapkan($unit, $sumber, $aktor);

        $this->assertSame([$anggota->id], $hasil['applied_employee_ids']);

        $langkahPertama = LeaveApprovalChain::query()
            ->where('employee_id', $anggota->id)
            ->where('is_active', true)
            ->sole()
            ->steps()
            ->orderBy('step_order')
            ->first();

        $this->assertSame($anggota->kepala_bagian_id, $langkahPertama->approver_employee_id);
    }

    public function test_langkah_kepala_bagian_sumber_tanpa_approver_tidak_memblokir_penerapan(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $anggota = $this->pegawaiUnit($unit, 'Anggota Unit');

        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);

        DB::table('leave_approval_chain_steps')
            ->where('approver_employee_id', $sumber->kepala_bagian_id)
            ->update(['approver_employee_id' => null]);

        $hasil = $this->terapkan($unit, $sumber, $aktor);

        $this->assertSame([$anggota->id], $hasil['applied_employee_ids']);
    }

    public function test_langkah_approver_duplikat_dipertahankan_saat_disalin(): void
    {
        // Snapshot pengajuan mempertahankan seluruh langkah lalu menandai kemunculan lebih awal
        // sebagai dilewati, sehingga kemunculan terakhir yang menjadi otoritas efektif. Penyalinan
        // tidak boleh membuang langkah duplikat karena itu akan menghilangkan label dan peran yang
        // dipakai snapshot serta audit keputusan seluruh anggota unit.
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $verifikator = Employee::factory()->create();
        $pybmc = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $anggota = $this->pegawaiUnit($unit, 'Anggota Unit');

        $this->actingAs($aktor)->app->make(SaveEmployeeApprovalChainAction::class)->execute($sumber, [
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => (string) $sumber->kepala_bagian_id, 'is_final' => false],
            ['step_type' => 'verifier', 'role_label' => 'Verifikasi Awal', 'approver_employee_id' => $verifikator->id, 'is_final' => false],
            ['step_type' => 'verifier', 'role_label' => 'Verifikasi Akhir', 'approver_employee_id' => $verifikator->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id, 'is_final' => true],
        ], $aktor, 'Rantai dengan verifikator berulang.');

        $this->terapkan($unit, $sumber, $aktor);

        $langkah = LeaveApprovalChain::query()
            ->where('employee_id', $anggota->id)
            ->where('is_active', true)
            ->sole()
            ->steps()
            ->orderBy('step_order')
            ->get();

        $this->assertSame(
            ['kepala_bagian', 'verifier', 'verifier', 'pybmc'],
            $langkah->pluck('step_type')->all(),
        );
        $this->assertSame(
            ['Kepala Bagian', 'Verifikasi Awal', 'Verifikasi Akhir', 'PYBMC'],
            $langkah->pluck('role_label')->all(),
        );
        $this->assertSame($verifikator->id, $langkah[1]->approver_employee_id);
        $this->assertSame($verifikator->id, $langkah[2]->approver_employee_id);
    }

    public function test_langkah_kepala_bagian_memakai_atasan_pegawai_tujuan_bukan_atasan_sumber(): void
    {
        // Rantai hasil penyalinan wajib menunjuk kepala bagian pegawai tujuan supaya tidak melanggar
        // aturan yang ditegakkan form konfigurasi per pegawai.
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $anggota = $this->pegawaiUnit($unit, 'Anggota Unit');

        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);
        $this->terapkan($unit, $sumber, $aktor);

        $langkahPertama = LeaveApprovalChain::query()
            ->where('employee_id', $anggota->id)
            ->where('is_active', true)
            ->sole()
            ->steps()
            ->orderBy('step_order')
            ->first();

        $this->assertSame('kepala_bagian', $langkahPertama->step_type);
        $this->assertSame($anggota->kepala_bagian_id, $langkahPertama->approver_employee_id);
        $this->assertNotSame($sumber->kepala_bagian_id, $langkahPertama->approver_employee_id);
    }

    public function test_rantai_lama_anggota_ditimpa_dengan_menonaktifkan_bukan_menghapus(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $anggota = $this->pegawaiUnit($unit, 'Anggota Unit');

        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);
        $rantaiLama = $this->rantaiAwal($anggota, $aktor, $verifikator, $pybmc);

        $hasil = $this->terapkan($unit, $sumber, $aktor);

        $this->assertSame([$anggota->id], $hasil['overwritten_employee_ids']);
        $this->assertSame([], $hasil['applied_employee_ids']);
        $this->assertDatabaseHas('leave_approval_chains', ['id' => $rantaiLama->id, 'is_active' => false]);
        $this->assertSame(1, LeaveApprovalChain::query()->where('employee_id', $anggota->id)->where('is_active', true)->count());
    }

    public function test_pegawai_sumber_tidak_tertimpa_oleh_dirinya_sendiri(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $rantaiSumber = $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);

        $hasil = $this->terapkan($unit, $sumber, $aktor);

        $this->assertNotContains($sumber->id, $hasil['applied_employee_ids']);
        $this->assertNotContains($sumber->id, $hasil['overwritten_employee_ids']);
        $this->assertDatabaseHas('leave_approval_chains', ['id' => $rantaiSumber->id, 'is_active' => true]);
    }

    public function test_pegawai_nonaktif_dan_tanpa_kepala_bagian_dilaporkan_terlewat(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $nonaktif = $this->pegawaiUnit($unit, 'Pegawai Nonaktif');
        $nonaktif->update(['status_aktif' => 'Tidak Aktif']);
        $tanpaAtasan = $this->pegawaiUnit($unit, 'Pegawai Tanpa Atasan', supervisor: false);

        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);
        $hasil = $this->terapkan($unit, $sumber, $aktor);

        $this->assertSame([$nonaktif->id], $hasil['skipped_inactive_employee_ids']);
        $this->assertSame([$tanpaAtasan->id], $hasil['skipped_missing_kepala_bagian_employee_ids']);
        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $nonaktif->id]);
        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $tanpaAtasan->id]);
    }

    public function test_pegawai_tanpa_riwayat_jabatan_terkini_dilaporkan_tidak_terjangkau(): void
    {
        // Keanggotaan unit hanya dapat diturunkan dari riwayat jabatan terkini, sehingga pegawai tanpa
        // riwayat tidak dapat dipetakan ke unit mana pun. Jumlahnya dilaporkan agar tidak hilang senyap.
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);

        $sebelum = $this->terapkan($unit, $sumber, $aktor)['unreachable_without_latest_position_count'];

        Employee::factory()->create(['nama_lengkap' => 'Pegawai Tanpa Riwayat', 'status_aktif' => 'Aktif']);
        $sesudah = $this->terapkan($unit, $sumber, $aktor)['unreachable_without_latest_position_count'];

        $this->assertSame($sebelum + 1, $sesudah);

        // Pegawai nonaktif tidak menambah peringatan karena aksi ini memang tidak menyasar mereka.
        Employee::factory()->create(['nama_lengkap' => 'Pegawai Nonaktif Tanpa Riwayat', 'status_aktif' => 'Tidak Aktif']);

        $this->assertSame($sesudah, $this->terapkan($unit, $sumber, $aktor)['unreachable_without_latest_position_count']);
    }

    public function test_sub_unit_tidak_ikut_menerima_rantai(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $subUnit = $this->unit('Subbagian Perbendaharaan', parent: $unit);
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $pegawaiSubUnit = $this->pegawaiUnit($subUnit, 'Pegawai Subbagian');

        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);
        $this->terapkan($unit, $sumber, $aktor);

        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $pegawaiSubUnit->id]);
    }

    public function test_aksi_menulis_satu_audit_konfigurasi_berisi_ringkasan_kategori(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $anggota = $this->pegawaiUnit($unit, 'Anggota Unit');

        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);
        $this->terapkan($unit, $sumber, $aktor, 'Menyeragamkan rantai Bagian Keuangan.');

        $audit = AuditLog::query()->where('event', 'CONFIG_UPDATE')->sole();

        $this->assertSame('RefUnitKerja', $audit->auditable_type);
        $this->assertSame($unit->id, $audit->auditable_id);
        $this->assertSame($sumber->id, $audit->new_values['source_employee_id']);
        $this->assertSame('Menyeragamkan rantai Bagian Keuangan.', $audit->new_values['reason']);
        $this->assertSame([$anggota->id], $audit->new_values['applied_employee_ids']);
        $this->assertSame(1, $audit->new_values['applied_count']);
        $this->assertSame(0, $audit->new_values['overwritten_count']);
    }

    public function test_audit_aksi_menyimpan_jejak_forensik_permintaan(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $this->pegawaiUnit($unit, 'Anggota Unit');
        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);

        $permintaan = Request::create('/dashboard/cuti/konfigurasi-approval/unit', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.24',
            'HTTP_USER_AGENT' => 'PengujiUnit/1.0',
        ]);

        $this->actingAs($aktor)->app->make(ApplyChainTemplateToUnitAction::class)
            ->execute($unit, $sumber, $aktor, 'Uji jejak forensik.', $permintaan);

        $audit = AuditLog::query()->where('event', 'CONFIG_UPDATE')->sole();

        $this->assertSame('203.0.113.24', $audit->ip_address);
        $this->assertSame('PengujiUnit/1.0', $audit->user_agent);
    }

    public function test_kegagalan_audit_membatalkan_seluruh_penerapan(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $anggota = $this->pegawaiUnit($unit, 'Anggota Unit');
        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION uji_tolak_audit_unit() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Penulisan audit unit ditolak untuk pengujian.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER uji_tolak_audit_unit
            BEFORE INSERT ON audit_logs
            FOR EACH ROW
            WHEN (NEW.event = 'CONFIG_UPDATE')
            EXECUTE FUNCTION uji_tolak_audit_unit();
        SQL);

        try {
            $this->terapkan($unit, $sumber, $aktor);
            $this->fail('Penerapan seharusnya dibatalkan ketika audit aksi gagal ditulis.');
        } catch (QueryException) {
            // Kegagalan memang diharapkan; yang diuji adalah keadaan basis data sesudahnya.
        }

        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $anggota->id]);
    }

    public function test_snapshot_pengajuan_berjalan_tidak_berubah(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmcLama = Employee::factory()->create();
        $pybmcBaru = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $anggota = $this->pegawaiUnit($unit, 'Anggota Unit');

        $rantaiAnggota = $this->rantaiAwal($anggota, $aktor, $verifikator, $pybmcLama);
        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmcBaru);

        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Sakit',
            'code' => 'cuti_sakit',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $pengajuan = LeaveRequest::create([
            'employee_id' => $anggota->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-07-20',
            'tanggal_selesai' => '2026-07-21',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Pengujian snapshot penerapan unit.',
            'status' => 'menunggu_approval',
        ]);
        $pengajuan->steps()->createMany($rantaiAnggota->steps->map(fn ($step): array => [
            'step_order' => $step->step_order,
            'step_type' => $step->step_type,
            'role_label' => $step->role_label,
            'approver_employee_id' => $step->approver_employee_id,
            'is_final' => $step->is_final,
            'status' => 'menunggu',
        ])->all());

        $sebelum = $pengajuan->steps()->orderBy('step_order')->pluck('approver_employee_id')->all();

        $this->terapkan($unit, $sumber, $aktor);

        $this->assertSame($sebelum, $pengajuan->steps()->orderBy('step_order')->pluck('approver_employee_id')->all());
    }

    public function test_penerapan_mengambil_advisory_lock_per_unit(): void
    {
        // Dua penerapan massal yang berjalan bersamaan tidak boleh menghasilkan unit setengah tersalin.
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $this->pegawaiUnit($unit, 'Anggota Unit');
        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);

        $kueri = [];
        DB::listen(function ($event) use (&$kueri): void {
            $kueri[] = $event->sql;
        });

        $this->terapkan($unit, $sumber, $aktor);

        $this->assertNotEmpty(
            array_filter($kueri, fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock')),
            'Penerapan template ke unit wajib mengambil advisory lock.',
        );
    }

    public function test_super_admin_menerapkan_template_ke_unit_melalui_route(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $anggota = $this->pegawaiUnit($unit, 'Anggota Unit');
        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);

        $this->actingAs($aktor)
            ->post(route('cuti.config.unit-template.apply'), [
                'unit_kerja_id' => $unit->id,
                'source_employee_id' => $sumber->id,
                'template_reason' => 'Menyeragamkan rantai melalui halaman konfigurasi.',
            ])
            ->assertRedirect(route('cuti.config'));

        $this->assertDatabaseHas('leave_approval_chains', ['employee_id' => $anggota->id, 'is_active' => true]);
    }

    public function test_admin_kepegawaian_tanpa_permission_chain_ditolak(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $unit = $this->unit('Bagian Keuangan');
        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');

        $this->actingAs($admin)
            ->post(route('cuti.config.unit-template.apply'), [
                'unit_kerja_id' => $unit->id,
                'source_employee_id' => $sumber->id,
                'template_reason' => 'Percobaan tanpa permission.',
            ])
            ->assertForbidden();
    }

    public function test_tamu_tidak_dapat_menerapkan_template(): void
    {
        $unit = $this->unit('Bagian Keuangan');
        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');

        $this->post(route('cuti.config.unit-template.apply'), [
            'unit_kerja_id' => $unit->id,
            'source_employee_id' => $sumber->id,
            'template_reason' => 'Percobaan tanpa sesi.',
        ])->assertRedirect(route('login'));
    }

    public function test_permintaan_tidak_valid_ditolak_dengan_galat_validasi(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unitNonaktif = $this->unit('Bagian Nonaktif');
        $unitNonaktif->update(['is_active' => false]);
        $unit = $this->unit('Bagian Keuangan');
        $sumberTanpaRantai = $this->pegawaiUnit($unit, 'Pegawai Tanpa Rantai');

        // Pengenal cacat harus tertangkap validasi, bukan menjadi galat basis data.
        $this->actingAs($aktor)
            ->post(route('cuti.config.unit-template.apply'), [
                'unit_kerja_id' => 'bukan-uuid',
                'source_employee_id' => $sumberTanpaRantai->id,
                'template_reason' => 'Alasan cukup panjang.',
            ])
            ->assertSessionHasErrors('unit_kerja_id');

        $this->actingAs($aktor)
            ->post(route('cuti.config.unit-template.apply'), [
                'unit_kerja_id' => $unitNonaktif->id,
                'source_employee_id' => $sumberTanpaRantai->id,
                'template_reason' => 'Alasan cukup panjang.',
            ])
            ->assertSessionHasErrors('unit_kerja_id');

        $this->actingAs($aktor)
            ->post(route('cuti.config.unit-template.apply'), [
                'unit_kerja_id' => $unit->id,
                'source_employee_id' => $sumberTanpaRantai->id,
                'template_reason' => 'Alasan cukup panjang.',
            ])
            ->assertSessionHasErrors('source_employee_id');

        $this->actingAs($aktor)
            ->post(route('cuti.config.unit-template.apply'), [
                'unit_kerja_id' => $unit->id,
                'source_employee_id' => $sumberTanpaRantai->id,
                'template_reason' => 'x',
            ])
            ->assertSessionHasErrors('template_reason');

        $this->assertDatabaseCount('leave_approval_chains', 0);
    }

    public function test_halaman_konfigurasi_menampilkan_panel_penerapan_unit_dengan_unit_pegawai_terpilih(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();
        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);

        $this->actingAs($aktor)
            ->get(route('cuti.config', ['employee_id' => $sumber->id]))
            ->assertOk()
            ->assertSee('Terapkan Chain ke Unit Kerja')
            ->assertSee('tidak termasuk sub-unit', false)
            ->assertViewHas('templateSourceHasActiveChain', true)
            ->assertViewHas('templateSourceUnitKerjaId', $unit->id);
    }

    public function test_panel_penerapan_unit_menolak_sumber_tanpa_chain_aktif(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $tanpaRantai = $this->pegawaiUnit($unit, 'Pegawai Tanpa Rantai');

        $this->actingAs($aktor)
            ->get(route('cuti.config', ['employee_id' => $tanpaRantai->id]))
            ->assertOk()
            ->assertViewHas('templateSourceHasActiveChain', false)
            ->assertSee('belum memiliki chain aktif', false);
    }

    public function test_audit_penerapan_unit_tampil_pada_log_konfigurasi(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();
        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $this->pegawaiUnit($unit, 'Anggota Unit');
        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);
        $this->terapkan($unit, $sumber, $aktor, 'Menyeragamkan rantai unit keuangan.');

        $response = $this->actingAs($aktor)->get(route('cuti.config'))->assertOk();

        $baris = collect($response->viewData('auditRows'))->firstWhere('source', 'Template unit');

        $this->assertNotNull($baris, 'Audit penerapan template unit wajib tampil pada log konfigurasi.');
        $this->assertSame('CONFIG_UPDATE', $baris['event']);
        $this->assertSame('Menyeragamkan rantai unit keuangan.', $baris['reason']);
    }

    public function test_approver_role_key_ikut_tersalin_ke_rantai_anggota(): void
    {
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $anggota = $this->pegawaiUnit($unit, 'Anggota Unit');

        $rantaiSumber = $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);
        $rantaiSumber->steps()->where('step_type', 'verifier')->update(['approver_role_key' => 'verifikator_kepegawaian']);

        $this->terapkan($unit, $sumber, $aktor);

        $langkahVerifier = LeaveApprovalChain::query()
            ->where('employee_id', $anggota->id)
            ->where('is_active', true)
            ->sole()
            ->steps()
            ->where('step_type', 'verifier')
            ->sole();

        $this->assertSame('verifikator_kepegawaian', $langkahVerifier->approver_role_key);
    }

    public function test_anggota_yang_menjadi_approver_wajib_pada_template_dilewati_dan_dilaporkan(): void
    {
        // Bila pegawai tujuan justru approver final pada template, rantainya tidak boleh dibuat.
        // Snapshot pengajuan membuang step approver yang sama dengan pemohon lalu menandai step
        // terakhir yang tersisa sebagai final, sehingga otoritas final berpindah ke orang yang
        // tidak ditunjuk. Pegawai seperti ini dilaporkan agar rantainya diatur manual.
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $pybmcSekaligusAnggota = $this->pegawaiUnit($unit, 'Pejabat Berwenang');

        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmcSekaligusAnggota);

        $hasil = $this->terapkan($unit, $sumber, $aktor);

        $this->assertSame([$pybmcSekaligusAnggota->id], $hasil['skipped_self_approval_employee_ids']);
        $this->assertNotContains($pybmcSekaligusAnggota->id, $hasil['applied_employee_ids']);
        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $pybmcSekaligusAnggota->id]);
    }

    public function test_kepala_bagian_diambil_dari_penugasan_efektif_bukan_kolom_pegawai(): void
    {
        // Kolom kepala_bagian_id pada pegawai bisa tertinggal dari penugasan efektif. Aksi wajib
        // memakai penugasan efektif, sumber yang sama dengan resolver dan form konfigurasi.
        $aktor = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Keuangan');
        $pybmc = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $sumber = $this->pegawaiUnit($unit, 'Pegawai Sumber');
        $anggota = $this->pegawaiUnit($unit, 'Anggota Unit');
        $atasanBaru = Employee::factory()->create(['nama_lengkap' => 'Atasan Efektif Baru']);

        SupervisorAssignment::query()
            ->where('employee_id', $anggota->id)
            ->update(['tanggal_berakhir' => today()->subDay()]);
        SupervisorAssignment::create([
            'employee_id' => $anggota->id,
            'kepala_bagian_id' => $atasanBaru->id,
            'tanggal_mulai' => today(),
            'tanggal_berakhir' => null,
        ]);

        $this->rantaiAwal($sumber, $aktor, $verifikator, $pybmc);
        $this->terapkan($unit, $sumber, $aktor);

        $langkahPertama = LeaveApprovalChain::query()
            ->where('employee_id', $anggota->id)
            ->where('is_active', true)
            ->sole()
            ->steps()
            ->orderBy('step_order')
            ->first();

        $this->assertSame($atasanBaru->id, $langkahPertama->approver_employee_id);
        $this->assertNotSame($anggota->kepala_bagian_id, $langkahPertama->approver_employee_id);
    }

    private function unit(string $nama, ?RefUnitKerja $parent = null): RefUnitKerja
    {
        return RefUnitKerja::create([
            'nama' => $nama,
            'jenis_unit' => 'bagian',
            'is_active' => true,
            'parent_id' => $parent?->id,
        ]);
    }

    /**
     * Membuat pegawai yang keanggotaan unitnya berasal dari riwayat jabatan terkini.
     */
    private function pegawaiUnit(RefUnitKerja $unit, string $nama, bool $supervisor = true): Employee
    {
        $kepalaBagian = Employee::factory()->create(['nama_lengkap' => 'Kepala '.$nama]);
        $pegawai = Employee::factory()->create([
            'nama_lengkap' => $nama,
            'status_aktif' => 'Aktif',
            'kepala_bagian_id' => $supervisor ? $kepalaBagian->id : null,
        ]);

        PositionHistory::create([
            'employee_id' => $pegawai->id,
            'nama_jabatan' => 'Jabatan '.$nama,
            'unit_kerja_id' => $unit->id,
            'tmt_jabatan' => '2025-01-01',
            'is_latest' => true,
        ]);

        if ($supervisor) {
            SupervisorAssignment::create([
                'employee_id' => $pegawai->id,
                'kepala_bagian_id' => $kepalaBagian->id,
                'tanggal_mulai' => today()->subDay(),
                'tanggal_berakhir' => null,
            ]);
        }

        return $pegawai->refresh();
    }

    private function rantaiAwal(Employee $pegawai, User $aktor, Employee $verifikator, Employee $pybmc): LeaveApprovalChain
    {
        return $this->actingAs($aktor)->app->make(SaveEmployeeApprovalChainAction::class)->execute($pegawai, [
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => (string) $pegawai->kepala_bagian_id, 'is_final' => false],
            ['step_type' => 'verifier', 'role_label' => 'Verifikator Kepegawaian', 'approver_employee_id' => $verifikator->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id, 'is_final' => true],
        ], $aktor, 'Rantai awal untuk pengujian.');
    }

    /**
     * @return array<string, mixed>
     */
    private function terapkan(RefUnitKerja $unit, Employee $sumber, User $aktor, string $alasan = 'Menyeragamkan rantai unit.'): array
    {
        return $this->actingAs($aktor)->app->make(ApplyChainTemplateToUnitAction::class)
            ->execute($unit, $sumber, $aktor, $alasan);
    }
}
