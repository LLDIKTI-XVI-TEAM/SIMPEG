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
use App\Services\LeaveApprovalService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Tests\TestCase;

/**
 * Menguji kontrak snapshot kontak cuti pada tingkat request.
 * Kolom snapshot bersifat nullable agar data cuti legacy tetap dapat disimpan.
 * Pengajuan baru dan pengiriman ulang wajib menyertakan alamat selama cuti dan nomor telepon,
 * serta memangkas spasi di sekitar nilai sebelum divalidasi.
 */
class CutiContactSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE_STORE = 'cuti.store';

    private const ALAMAT_VALID = 'Jl. Sam Ratulangi No. 1, Manado';

    private const TELEPON_VALID = '+62 (431) 123-456';

    protected function setUp(): void
    {
        parent::setUp();

        // RBAC di-seed agar permission cuti.create tersedia bagi gerbang route dan FormRequest.
        // Tidak memengaruhi asersi test legacy yang hanya memeriksa skema dan nilai null.
        $this->seed(RbacSeeder::class);
    }

    /**
     * Snapshot kontak boleh null untuk data cuti legacy yang dibuat sebelum kolom kontak ada,
     * sehingga migrasi tidak memutus data lama.
     */
    public function test_kolom_snapshot_kontak_nullable_untuk_data_legacy(): void
    {
        $this->assertTrue(
            Schema::hasColumn('leave_requests', 'alamat_selama_cuti'),
            'Kolom alamat_selama_cuti wajib tersedia pada leave_requests.',
        );
        $this->assertTrue(
            Schema::hasColumn('leave_requests', 'nomor_telepon'),
            'Kolom nomor_telepon wajib tersedia pada leave_requests.',
        );

        $pemohon = Employee::factory()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan Legacy',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);

        // Simulasi pembuatan cuti gaya legacy tanpa mengisi kolom kontak baru.
        $cuti = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-06',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Uji kompatibilitas data cuti legacy.',
            'status' => 'menunggu_approval',
        ]);

        $this->assertNull($cuti->alamat_selama_cuti);
        $this->assertNull($cuti->nomor_telepon);

        $segar = $cuti->fresh();
        $this->assertNull($segar->alamat_selama_cuti);
        $this->assertNull($segar->nomor_telepon);
    }

    // --- Pengajuan baru (store) ---

    public function test_store_menolak_pengajuan_tanpa_kontak(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE_STORE), $this->storePayload($jenis));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['alamat_selama_cuti', 'nomor_telepon']);
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_store_menolak_alamat_dan_telepon_hanya_spasi(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');

        // Nilai hanya spasi harus ditolak setelah pemangkasan; membuktikan prepareForValidation memangkas nilai.
        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE_STORE), $this->storePayload($jenis, [
            'alamat_selama_cuti' => '     ',
            'nomor_telepon' => '   ',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['alamat_selama_cuti', 'nomor_telepon']);
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_store_menolak_alamat_melebihi_1000_karakter(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE_STORE), $this->storePayload($jenis, [
            'alamat_selama_cuti' => str_repeat('a', 1001),
            'nomor_telepon' => self::TELEPON_VALID,
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['alamat_selama_cuti']);
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_store_menolak_telepon_melebihi_20_karakter(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE_STORE), $this->storePayload($jenis, [
            'alamat_selama_cuti' => self::ALAMAT_VALID,
            'nomor_telepon' => str_repeat('1', 21),
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['nomor_telepon']);
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_store_menolak_telepon_mengandung_huruf(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE_STORE), $this->storePayload($jenis, [
            'alamat_selama_cuti' => self::ALAMAT_VALID,
            'nomor_telepon' => '0812ABC5678',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['nomor_telepon']);
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_store_menolak_telepon_mengandung_garis_miring(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');

        $this->actingAs($aktor['user']);
        $response = $this->postJson(route(self::ROUTE_STORE), $this->storePayload($jenis, [
            'alamat_selama_cuti' => self::ALAMAT_VALID,
            'nomor_telepon' => '0812/5678',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['nomor_telepon']);
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_store_menerima_kontak_valid(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');

        $this->actingAs($aktor['user']);
        $response = $this->post(route(self::ROUTE_STORE), $this->storePayload($jenis, [
            'alamat_selama_cuti' => self::ALAMAT_VALID,
            'nomor_telepon' => self::TELEPON_VALID,
        ]));

        $response->assertRedirect(route('cuti'));
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('leave_requests', 1);
    }

    public function test_store_memangkas_spasi_di_sekitar_kontak_valid(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');

        // Alamat 1000 karakter dibungkus spasi (total 1004): lolos max:1000 hanya bila dipangkas lebih dulu.
        $this->actingAs($aktor['user']);
        $response = $this->post(route(self::ROUTE_STORE), $this->storePayload($jenis, [
            'alamat_selama_cuti' => '  '.str_repeat('a', 1000).'  ',
            'nomor_telepon' => '  '.self::TELEPON_VALID.'  ',
        ]));

        $response->assertRedirect(route('cuti'));
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('leave_requests', 1);
    }

    public function test_store_menyimpan_snapshot_kontak_terpangkas_dan_audit_tanpa_pii_mentah(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');
        $alamat = '  Jl. Snapshot Submit No. 10, Manado  ';
        $telepon = '  +62 (431) 555-0100  ';

        $this->actingAs($aktor['user']);
        $response = $this->post(route(self::ROUTE_STORE), $this->storePayload($jenis, [
            'alamat_selama_cuti' => $alamat,
            'nomor_telepon' => $telepon,
        ]));

        $response->assertRedirect(route('cuti'));
        $leave = LeaveRequest::query()->firstOrFail();
        $this->assertSame(trim($alamat), $leave->alamat_selama_cuti);
        $this->assertSame(trim($telepon), $leave->nomor_telepon);

        $aktor['employee']->forceFill([
            'alamat' => 'Alamat profil yang berubah.',
            'no_hp' => '081299990000',
        ])->save();
        $leave->refresh();

        $this->assertSame(trim($alamat), $leave->alamat_selama_cuti);
        $this->assertSame(trim($telepon), $leave->nomor_telepon);

        $audit = $this->auditFor($leave, 'CREATE');
        $this->assertSame($aktor['employee']->id, $audit->new_values['employee_id']);
        $this->assertSame('menunggu_approval', $audit->new_values['status']);
        $this->assertSame('Keperluan keluarga.', $audit->new_values['alasan']);
        $this->assertSame('2026-07-06', $this->auditDate($audit->new_values['tanggal_mulai']));
        $this->assertSame('2026-07-10', $this->auditDate($audit->new_values['tanggal_selesai']));
        $this->assertTrue($audit->new_values['alamat_selama_cuti_diisi']);
        $this->assertTrue($audit->new_values['nomor_telepon_diisi']);
        $this->assertArrayNotHasKey('alamat_selama_cuti', $audit->new_values);
        $this->assertArrayNotHasKey('nomor_telepon', $audit->new_values);
        $this->assertStringNotContainsString(trim($alamat), json_encode($audit->new_values, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(trim($telepon), json_encode($audit->new_values, JSON_THROW_ON_ERROR));
    }

    public function test_form_pengajuan_merender_kontrol_kontak_wajib_dengan_nilai_lama_dan_error_tereskape(): void
    {
        $aktor = $this->makePemohon();
        $alamatLama = 'Jl. <Kontak> & Anak, Manado';
        $teleponLama = '+62 <431> & 123';

        $this->actingAs($aktor['user']);
        $this->withoutMiddleware(ShareErrorsFromSession::class);
        $this->withViewErrors([
            'alamat_selama_cuti' => ['Alamat selama cuti wajib diisi.'],
            'nomor_telepon' => ['Nomor telepon tidak valid.'],
        ]);
        $response = $this->withSession([
            '_old_input' => [
                'alamat_selama_cuti' => $alamatLama,
                'nomor_telepon' => $teleponLama,
            ],
        ])->get(route('cuti.create'));

        $response->assertOk();
        $response->assertSee('<label for="alamat_selama_cuti"', false);
        $response->assertSee('<label for="nomor_telepon"', false);
        $response->assertSee('Alamat Selama Cuti <span class="text-danger">*</span>', false);
        $response->assertSee('Nomor Telepon <span class="text-danger">*</span>', false);
        $this->assertContactControlAccessibility($response->getContent(), true);
        $response->assertSee('<p id="alamat_selama_cuti-error"', false);
        $response->assertSee('<p id="nomor_telepon-error"', false);
        $response->assertSee('Alamat selama cuti wajib diisi.</p>', false);
        $response->assertSee('Nomor telepon tidak valid.</p>', false);
        $response->assertSee(e($alamatLama), false);
        $response->assertSee(e($teleponLama), false);
        $response->assertDontSee($alamatLama, false);
        $response->assertDontSee($teleponLama, false);
        $response->assertSee('formulir Cuti resmi', false);
    }

    // --- Pengiriman ulang (resubmit) ---

    public function test_resubmit_menolak_pengajuan_tanpa_kontak(): void
    {
        [$aktor, $leave] = $this->makePerluPerubahan();

        $this->actingAs($aktor['user']);
        $response = $this->patchJson(route('cuti.resubmit', $leave), $this->resubmitPayload());

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['alamat_selama_cuti', 'nomor_telepon']);
        $leave->refresh();
        $this->assertSame('perlu_perubahan', $leave->status);
    }

    public function test_resubmit_menolak_alamat_dan_telepon_hanya_spasi(): void
    {
        [$aktor, $leave] = $this->makePerluPerubahan();

        $this->actingAs($aktor['user']);
        $response = $this->patchJson(route('cuti.resubmit', $leave), $this->resubmitPayload([
            'alamat_selama_cuti' => '     ',
            'nomor_telepon' => '   ',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['alamat_selama_cuti', 'nomor_telepon']);
        $leave->refresh();
        $this->assertSame('perlu_perubahan', $leave->status);
    }

    public function test_resubmit_menolak_alamat_melebihi_1000_karakter(): void
    {
        [$aktor, $leave] = $this->makePerluPerubahan();

        $this->actingAs($aktor['user']);
        $response = $this->patchJson(route('cuti.resubmit', $leave), $this->resubmitPayload([
            'alamat_selama_cuti' => str_repeat('a', 1001),
            'nomor_telepon' => self::TELEPON_VALID,
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['alamat_selama_cuti']);
        $leave->refresh();
        $this->assertSame('perlu_perubahan', $leave->status);
    }

    public function test_resubmit_menolak_telepon_melebihi_20_karakter(): void
    {
        [$aktor, $leave] = $this->makePerluPerubahan();

        $this->actingAs($aktor['user']);
        $response = $this->patchJson(route('cuti.resubmit', $leave), $this->resubmitPayload([
            'alamat_selama_cuti' => self::ALAMAT_VALID,
            'nomor_telepon' => str_repeat('1', 21),
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['nomor_telepon']);
        $leave->refresh();
        $this->assertSame('perlu_perubahan', $leave->status);
    }

    public function test_resubmit_menolak_telepon_mengandung_huruf(): void
    {
        [$aktor, $leave] = $this->makePerluPerubahan();

        $this->actingAs($aktor['user']);
        $response = $this->patchJson(route('cuti.resubmit', $leave), $this->resubmitPayload([
            'alamat_selama_cuti' => self::ALAMAT_VALID,
            'nomor_telepon' => '0812ABC5678',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['nomor_telepon']);
        $leave->refresh();
        $this->assertSame('perlu_perubahan', $leave->status);
    }

    public function test_resubmit_menolak_telepon_mengandung_garis_miring(): void
    {
        [$aktor, $leave] = $this->makePerluPerubahan();

        $this->actingAs($aktor['user']);
        $response = $this->patchJson(route('cuti.resubmit', $leave), $this->resubmitPayload([
            'alamat_selama_cuti' => self::ALAMAT_VALID,
            'nomor_telepon' => '0812/5678',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['nomor_telepon']);
        $leave->refresh();
        $this->assertSame('perlu_perubahan', $leave->status);
    }

    public function test_resubmit_menerima_kontak_valid(): void
    {
        [$aktor, $leave] = $this->makePerluPerubahan();

        $this->actingAs($aktor['user']);
        $response = $this->patch(route('cuti.resubmit', $leave), $this->resubmitPayload([
            'alamat_selama_cuti' => self::ALAMAT_VALID,
            'nomor_telepon' => self::TELEPON_VALID,
        ]));

        $response->assertRedirect(route('cuti.show', $leave));
        $response->assertSessionHasNoErrors();
        $leave->refresh();
        $this->assertSame('menunggu_approval', $leave->status);
    }

    public function test_resubmit_memangkas_spasi_di_sekitar_kontak_valid(): void
    {
        [$aktor, $leave] = $this->makePerluPerubahan();

        // Alamat 1000 karakter dibungkus spasi (total 1004): lolos max:1000 hanya bila dipangkas lebih dulu.
        $this->actingAs($aktor['user']);
        $response = $this->patch(route('cuti.resubmit', $leave), $this->resubmitPayload([
            'alamat_selama_cuti' => '  '.str_repeat('a', 1000).'  ',
            'nomor_telepon' => '  '.self::TELEPON_VALID.'  ',
        ]));

        $response->assertRedirect(route('cuti.show', $leave));
        $response->assertSessionHasNoErrors();
        $leave->refresh();
        $this->assertSame('menunggu_approval', $leave->status);
    }

    public function test_resubmit_mengganti_snapshot_kontak_terpangkas_tanpa_mengubah_snapshot_step_dan_audit_tanpa_pii_mentah(): void
    {
        [$aktor, $leave] = $this->makePerluPerubahan();
        $piiProfil = [
            'nip' => '198001012006041999',
            'email_pribadi' => 'audit-rahasia@example.test',
            'alamat' => 'Alamat profil tidak boleh masuk audit resubmit.',
            'no_hp' => '081299998888',
        ];
        $aktor['employee']->forceFill($piiProfil)->save();
        $stepSnapshot = $leave->steps()
            ->orderBy('step_order')
            ->get(['id', 'step_order'])
            ->map(fn ($step) => ['id' => $step->id, 'step_order' => $step->step_order])
            ->all();
        $alamat = '  Jl. Snapshot Resubmit No. 20, Manado  ';
        $telepon = '  +62 (431) 555-0200  ';

        $this->actingAs($aktor['user']);
        $response = $this->patch(route('cuti.resubmit', $leave), $this->resubmitPayload([
            'alamat_selama_cuti' => $alamat,
            'nomor_telepon' => $telepon,
        ]));

        $response->assertRedirect(route('cuti.show', $leave));
        $leave->refresh();
        $this->assertSame(trim($alamat), $leave->alamat_selama_cuti);
        $this->assertSame(trim($telepon), $leave->nomor_telepon);
        $this->assertSame($stepSnapshot, $leave->steps()
            ->orderBy('step_order')
            ->get(['id', 'step_order'])
            ->map(fn ($step) => ['id' => $step->id, 'step_order' => $step->step_order])
            ->all());

        $audit = $this->auditFor($leave, 'UPDATE');
        $oldValues = $audit->old_values;
        $newValues = $audit->new_values;
        $this->assertSame('pegawai', $newValues['_effective_role'] ?? null);
        unset($newValues['_effective_role']);
        $oldKeys = array_keys($oldValues);
        $newKeys = array_keys($newValues);
        sort($oldKeys);
        sort($newKeys);

        $this->assertSame($oldKeys, $newKeys, 'Snapshot before/after audit UPDATE wajib memakai kontrak key yang simetris.');
        foreach (['employee_id', 'jenis_cuti_id', 'leave_request_case_id'] as $contextKey) {
            $this->assertArrayHasKey($contextKey, $oldValues);
            $this->assertSame($oldValues[$contextKey], $newValues[$contextKey]);
        }
        $this->assertSame($leave->employee_id, $oldValues['employee_id']);
        $this->assertSame($leave->jenis_cuti_id, $oldValues['jenis_cuti_id']);
        $this->assertSame('perlu_perubahan', $oldValues['status']);
        $this->assertSame('Keperluan keluarga.', $oldValues['alasan']);
        $this->assertSame('2026-07-06', $this->auditDate($oldValues['tanggal_mulai']));
        $this->assertSame('2026-07-10', $this->auditDate($oldValues['tanggal_selesai']));
        $this->assertSame('menunggu_approval', $newValues['status']);
        $this->assertSame('Revisi tanggal sesuai arahan approver.', $newValues['alasan']);
        $this->assertSame('2026-07-13', $this->auditDate($newValues['tanggal_mulai']));
        $this->assertSame('2026-07-15', $this->auditDate($newValues['tanggal_selesai']));
        foreach ([$oldValues, $newValues] as $auditValues) {
            $this->assertTrue($auditValues['alamat_selama_cuti_diisi']);
            $this->assertTrue($auditValues['nomor_telepon_diisi']);
            $this->assertFalse($auditValues['lampiran_diisi']);
            $this->assertTrue($auditValues['alamat_selama_cuti_diubah']);
            $this->assertTrue($auditValues['nomor_telepon_diubah']);
            $this->assertFalse($auditValues['lampiran_diubah']);
        }

        foreach ([$oldValues, $newValues] as $auditValues) {
            $serialized = json_encode($auditValues, JSON_THROW_ON_ERROR);
            foreach (['employee', 'jenis_cuti', 'alamat_selama_cuti', 'nomor_telepon', 'lampiran_path'] as $forbiddenKey) {
                $this->assertArrayNotHasKey($forbiddenKey, $auditValues);
            }
            foreach ([
                self::ALAMAT_VALID,
                self::TELEPON_VALID,
                trim($alamat),
                trim($telepon),
                ...array_values($piiProfil),
                'cuti/lampiran/',
            ] as $forbiddenValue) {
                $this->assertStringNotContainsString($forbiddenValue, $serialized);
            }
        }
    }

    public function test_form_kirim_ulang_merender_kontrol_kontak_wajib_dengan_fallback_model_dan_error_tereskape(): void
    {
        [$aktor, $leave] = $this->makePerluPerubahan();
        $alamatSnapshot = 'Jl. <Snapshot> & Keluarga, Manado';
        $teleponSnapshot = '+62 <431> & 456';
        $alamatLama = 'Jl. <Revisi> & Keluarga, Manado';
        $teleponLama = '+62 <431> & 789';
        $leave->forceFill([
            'alamat_selama_cuti' => $alamatSnapshot,
            'nomor_telepon' => $teleponSnapshot,
        ])->save();

        $this->actingAs($aktor['user']);
        $fallbackResponse = $this->get(route('cuti.show', $leave));

        $fallbackResponse->assertOk();
        $fallbackResponse->assertSee(e($alamatSnapshot), false);
        $fallbackResponse->assertSee(e($teleponSnapshot), false);
        $fallbackResponse->assertDontSee($alamatSnapshot, false);
        $fallbackResponse->assertDontSee($teleponSnapshot, false);
        $this->assertContactControlAccessibility($fallbackResponse->getContent(), false);

        $this->withoutMiddleware(ShareErrorsFromSession::class);
        $this->withViewErrors([
            'alamat_selama_cuti' => ['Alamat selama cuti wajib diisi.'],
            'nomor_telepon' => ['Nomor telepon tidak valid.'],
        ]);
        $response = $this->withSession([
            '_old_input' => [
                'alamat_selama_cuti' => $alamatLama,
                'nomor_telepon' => $teleponLama,
            ],
        ])->get(route('cuti.show', $leave));

        $response->assertOk();
        $response->assertSee('<label for="alamat_selama_cuti"', false);
        $response->assertSee('<label for="nomor_telepon"', false);
        $response->assertSee('Alamat Selama Cuti <span class="text-danger">*</span>', false);
        $response->assertSee('Nomor Telepon <span class="text-danger">*</span>', false);
        $this->assertContactControlAccessibility($response->getContent(), true);
        $response->assertSee('<p id="alamat_selama_cuti-error"', false);
        $response->assertSee('<p id="nomor_telepon-error"', false);
        $response->assertSee('Alamat selama cuti wajib diisi.</p>', false);
        $response->assertSee('Nomor telepon tidak valid.</p>', false);
        $response->assertSee(e($alamatLama), false);
        $response->assertSee(e($teleponLama), false);
        $response->assertDontSee($alamatLama, false);
        $response->assertDontSee($teleponLama, false);
        $response->assertSee('formulir Cuti resmi', false);
    }

    // --- Fixture helpers ---

    /**
     * Membuat pegawai pemohon lengkap dengan akun, jenis pegawai, atasan langsung aktif, dan chain approval.
     *
     * @return array{user: User, employee: Employee, supervisor: Employee, pybmc: Employee}
     */
    private function makePemohon(string $jenisPegawai = 'PNS'): array
    {
        $jenis = RefJenisPegawai::firstOrCreate(['nama' => $jenisPegawai]);

        $employee = Employee::factory()->create(['jenis_pegawai_id' => $jenis->id]);
        $supervisor = Employee::factory()->create();
        $pybmc = Employee::factory()->create();

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => $jenisPegawai === 'PPPK' ? 'PPPK' : 'PNS',
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

        $this->createApprovalChain($employee, $supervisor, $pybmc);

        return ['user' => $user, 'employee' => $employee, 'supervisor' => $supervisor, 'pybmc' => $pybmc];
    }

    private function createApprovalChain(Employee $employee, Employee $kepalaBagian, Employee $pybmc): LeaveApprovalChain
    {
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
                'approver_employee_id' => $kepalaBagian->id,
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

        return $chain;
    }

    private function jenisCuti(string $nama, bool $khususPns = false): RefJenisCuti
    {
        return RefJenisCuti::create([
            'nama' => $nama,
            'code' => str($nama)->slug('_')->toString(),
            'mengurangi_saldo_tahunan' => $nama === 'Cuti Tahunan',
            'khusus_pns' => $khususPns,
        ]);
    }

    /**
     * Payload dasar pengajuan cuti tanpa kontak; kontak sengaja dipisah agar test kekurangan/isian buruk
     * dapat menambahkannya secara eksplisit.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function storePayload(RefJenisCuti $jenis, array $override = []): array
    {
        return array_merge([
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-10',
            'alasan' => 'Keperluan keluarga.',
        ], $override);
    }

    /**
     * Payload dasar pengiriman ulang tanpa kontak; jenis cuti terkunci sehingga hanya tanggal, alasan,
     * dan kontak yang dikirim ulang.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function resubmitPayload(array $override = []): array
    {
        return array_merge([
            'tanggal_mulai' => '2026-07-13',
            'tanggal_selesai' => '2026-07-15',
            'alasan' => 'Revisi tanggal sesuai arahan approver.',
        ], $override);
    }

    /**
     * Menyiapkan pengajuan berstatus perlu_perubahan milik pemohon agar gerbang otorisasi resubmit terpenuhi.
     * Pengajuan awal disimpan dengan kontak valid supaya store yang kini mewajibkan kontak tetap lolos.
     *
     * @return array{0: array{user: User, employee: Employee, supervisor: Employee, pybmc: Employee}, 1: LeaveRequest}
     */
    private function makePerluPerubahan(): array
    {
        $aktor = $this->makePemohon();
        $jenis = $this->jenisCuti('Cuti Sakit');

        $this->actingAs($aktor['user']);
        $this->post(route(self::ROUTE_STORE), $this->storePayload($jenis, [
            'alamat_selama_cuti' => self::ALAMAT_VALID,
            'nomor_telepon' => self::TELEPON_VALID,
        ]));

        $leave = LeaveRequest::with('steps')->firstOrFail();
        app(LeaveApprovalService::class)->requestChanges(
            $leave,
            $aktor['supervisor'],
            $leave->steps()->where('status', 'active')->valueOrFail('id'),
            'Tanggal harus diperbaiki.',
        );
        $leave->refresh();

        return [$aktor, $leave];
    }

    private function auditFor(LeaveRequest $leaveRequest, string $event): AuditLog
    {
        return AuditLog::query()
            ->where('event', $event)
            ->where('auditable_type', 'LeaveRequest')
            ->where('auditable_id', $leaveRequest->id)
            ->firstOrFail();
    }

    private function auditDate(mixed $value): string
    {
        return Carbon::parse((string) $value)->setTimezone(config('app.timezone'))->toDateString();
    }

    /**
     * Memastikan atribut aksesibilitas terikat pada kontrol kontak yang tepat,
     * tanpa bergantung pada urutan atau baris atribut hasil kompilasi Blade.
     */
    private function assertContactControlAccessibility(string $html, bool $hasErrors): void
    {
        $addressDescription = $hasErrors
            ? 'alamat_selama_cuti-help alamat_selama_cuti-error'
            : 'alamat_selama_cuti-help';
        $phoneDescription = $hasErrors
            ? 'nomor_telepon-help nomor_telepon-error'
            : 'nomor_telepon-help';
        $invalidAttribute = $hasErrors ? '(?=[^>]*\baria-invalid="true")' : '(?![^>]*\baria-invalid="true")';

        $this->assertMatchesRegularExpression(
            '#<textarea(?=[^>]*\bid="alamat_selama_cuti")(?=[^>]*\bname="alamat_selama_cuti")(?=[^>]*\brows="2")(?=[^>]*\bmaxlength="1000")(?=[^>]*\brequired)(?=[^>]*\bautocomplete="street-address")(?=[^>]*\baria-describedby="'.$addressDescription.'")'.$invalidAttribute.'[^>]*>#s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '#<input(?=[^>]*\bid="nomor_telepon")(?=[^>]*\bname="nomor_telepon")(?=[^>]*\btype="tel")(?=[^>]*\binputmode="tel")(?=[^>]*\bmaxlength="20")(?=[^>]*\brequired)(?=[^>]*\bautocomplete="tel")(?=[^>]*\baria-describedby="'.$phoneDescription.'")'.$invalidAttribute.'[^>]*>#s',
            $html,
        );
    }
}
