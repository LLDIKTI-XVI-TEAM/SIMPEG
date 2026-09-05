<?php

namespace Tests\Feature;

use App\Actions\Cuti\ReconcileAnnualLeaveUsageAction;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\Cuti\LeaveProofService;
use App\Services\LeaveApprovalService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_bukti_cuti_dapat_disimpan_dengan_event_leave_proof_generated(): void
    {
        $auditLog = AuditLog::query()->create([
            'user_id' => null,
            'user_name' => 'Sistem SIMPEG',
            'event' => 'LEAVE_PROOF_GENERATED',
            'auditable_type' => 'LeaveProof',
            'auditable_id' => '11111111-1111-4111-8111-111111111111',
            'old_values' => null,
            'new_values' => ['document_number' => 'SK-CUTI-2026-0001'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'id' => $auditLog->id,
            'event' => 'LEAVE_PROOF_GENERATED',
        ]);
    }

    public function test_service_membuat_proof_dengan_token_metadata_dan_qr_svg(): void
    {
        $fixture = $this->makeFinalApprovedRequest();
        $service = app(LeaveProofService::class);

        $proof = $service->generateForApprovedRequest(
            $fixture['request'],
            $fixture['approver_employee'],
            $fixture['approver_user'],
        );

        // Tepat satu bukti terbit untuk pengajuan ini.
        $this->assertSame(1, LeaveProof::query()->where('leave_request_id', $fixture['request']->id)->count());

        // Token acak wajib 64 karakter dan aman untuk dipakai pada URL verifikasi publik.
        $this->assertSame(64, strlen($proof->token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{64,120}$/', $proof->token);

        // Bukti cuti tidak menyimpan file, hanya snapshot metadata + token verifikasi.
        $this->assertNull($proof->document_path);
        $this->assertNull($proof->document_mime);

        // generated_by menyimpan UUID user penerbit, bukan UUID employee.
        $this->assertSame($fixture['approver_user']->id, $proof->generated_by);
        $this->assertNotSame($fixture['approver_employee']->id, $proof->generated_by);
        $this->assertNotNull($proof->generated_at);

        // Metadata publik wajib memuat identitas non-sensitif, jenis cuti, approver final, dan linimasa persetujuan.
        $metadata = $proof->metadata;
        $this->assertSame($fixture['pemohon_employee']->nama_lengkap, $metadata['employee_name']);
        $this->assertSame('Cuti Tahunan', $metadata['leave_type']);
        $this->assertArrayHasKey('final_approver', $metadata);
        $this->assertSame($fixture['approver_employee']->nama_lengkap, $metadata['final_approver']['name']);
        $this->assertArrayHasKey('approval_timeline', $metadata);
        $this->assertNotEmpty($metadata['approval_timeline']);
        $this->assertSame('Atasan Langsung', $metadata['approval_timeline'][0]['role']);
        $this->assertArrayNotHasKey('position', $metadata['final_approver']);
        $this->assertArrayNotHasKey('position', $metadata['approval_timeline'][0]);
        $this->assertArrayNotHasKey('jabatan_terakhir', $metadata['final_approver']);
        $this->assertArrayNotHasKey('jabatan_terakhir', $metadata['approval_timeline'][0]);
        $this->assertArrayNotHasKey('reason', $metadata);
        $this->assertArrayNotHasKey('alasan', $metadata);

        // Field sensitif tidak boleh bocor ke snapshot publik, termasuk di level bersarang.
        $this->assertKeysAbsentRecursively($metadata, [
            'employee_id',
            'leave_request_id',
            'nip',
            'email',
            'phone',
            'telepon',
            'no_hp',
            'address',
            'alamat',
            'salary',
            'gaji',
            'komentar',
            'decision_note',
            'raw_audit',
            'reason',
            'alasan',
        ]);

        // Audit penerbitan wajib ada dengan payload jejak yang aman.
        $audit = AuditLog::query()
            ->where('event', 'LEAVE_PROOF_GENERATED')
            ->where('auditable_type', 'LeaveProof')
            ->where('auditable_id', $proof->id)
            ->firstOrFail();
        $this->assertSame($fixture['approver_user']->id, $audit->user_id);
        $this->assertSame($fixture['request']->id, $audit->new_values['leave_request_id']);
        $this->assertSame($fixture['pemohon_employee']->id, $audit->new_values['employee_id']);
        $this->assertSame($proof->token, $audit->new_values['token']);
        $this->assertSame($fixture['approver_user']->id, $audit->new_values['generated_by']);
        $this->assertArrayHasKey('generated_at', $audit->new_values);
        // Audit tidak boleh membawa data mentah/komentar approver.
        $this->assertArrayNotHasKey('komentar', $audit->new_values);
        $this->assertArrayNotHasKey('metadata', $audit->new_values);

        // QR SVG dari URL verifikasi harus berupa SVG dan tidak menampilkan URL sebagai teks literal.
        $url = 'https://simpeg.test/cuti/verifikasi/'.$proof->token;
        $svg = $service->qrSvgForUrl($url);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString($url, $svg);
    }

    public function test_service_idempotent_untuk_satu_pengajuan_cuti(): void
    {
        $fixture = $this->makeFinalApprovedRequest();
        $service = app(LeaveProofService::class);

        $first = $service->generateForApprovedRequest(
            $fixture['request'],
            $fixture['approver_employee'],
            $fixture['approver_user'],
        );
        $second = $service->generateForApprovedRequest(
            $fixture['request']->fresh(),
            $fixture['approver_employee'],
            $fixture['approver_user'],
        );

        // Pemanggilan kedua tidak menerbitkan bukti/token/audit baru.
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->token, $second->token);
        $this->assertSame(1, LeaveProof::query()->where('leave_request_id', $fixture['request']->id)->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'LEAVE_PROOF_GENERATED')->count());
    }

    public function test_service_menolak_pengajuan_yang_belum_final_disetujui(): void
    {
        $fixture = $this->makeFinalApprovedRequest();
        $fixture['request']->forceFill(['status' => 'menunggu_approval'])->save();
        $service = app(LeaveProofService::class);

        try {
            $service->generateForApprovedRequest(
                $fixture['request']->fresh(),
                $fixture['approver_employee'],
                $fixture['approver_user'],
            );
            $this->fail('Bukti cuti seharusnya tidak boleh terbit untuk pengajuan yang belum disetujui final.');
        } catch (ValidationException $e) {
            // Tidak ada bukti maupun audit yang tertulis untuk pengajuan non-final.
            $this->assertSame(0, LeaveProof::query()->where('leave_request_id', $fixture['request']->id)->count());
            $this->assertSame(0, AuditLog::query()->where('event', 'LEAVE_PROOF_GENERATED')->count());
        }
    }

    public function test_service_pulih_dari_tabrakan_token_dan_transaksi_tetap_bisa_dipakai(): void
    {
        // Catatan: pada SQLite pelanggaran unik hanya membatalkan statement, bukan transaksi, sehingga
        // kondisi "transaksi ter-abort" khas PostgreSQL tidak bisa direproduksi di sini. Test ini mengunci
        // batas retry (percobaan pertama menabrak token, percobaan kedua memakai token berbeda) dan
        // struktur savepoint per-insert yang secara struktural diperlukan agar transaksi PostgreSQL selamat.
        $service = app(LeaveProofService::class);
        $collidingToken = str_repeat('A', 64);
        $distinctToken = str_repeat('B', 64);

        // Bukti pertama sengaja memakai token tetap agar bukti target menabrak token yang sama.
        $existingFixture = $this->makeFinalApprovedRequest();
        Str::createRandomStringsUsing(fn (): string => $collidingToken);

        try {
            $firstProof = $service->generateForApprovedRequest(
                $existingFixture['request'],
                $existingFixture['approver_employee'],
                $existingFixture['approver_user'],
            );
            $this->assertSame($collidingToken, $firstProof->token);
        } finally {
            // Reset factory agar test lain tidak mewarisi token deterministik.
            Str::createRandomStringsNormally();
        }

        // Target: percobaan pertama menabrak token lalu jatuh ke token berbeda pada percobaan kedua.
        $targetFixture = $this->makeFinalApprovedRequest();
        $calls = 0;
        Str::createRandomStringsUsing(function () use ($collidingToken, $distinctToken, &$calls): string {
            $calls++;

            return $calls === 1 ? $collidingToken : $distinctToken;
        });

        try {
            $targetProof = $service->generateForApprovedRequest(
                $targetFixture['request'],
                $targetFixture['approver_employee'],
                $targetFixture['approver_user'],
            );
        } finally {
            Str::createRandomStringsNormally();
        }

        // Bukti target tetap terbit dengan token berbeda meski percobaan pertama menabrak.
        $this->assertSame($distinctToken, $targetProof->token);
        $this->assertGreaterThanOrEqual(2, $calls);
        $this->assertSame(1, LeaveProof::query()->where('leave_request_id', $targetFixture['request']->id)->count());

        // Audit penerbitan tetap tertulis untuk bukti target setelah pemulihan tabrakan.
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'LEAVE_PROOF_GENERATED',
            'auditable_id' => $targetProof->id,
        ]);

        // Koneksi/transaksi tetap sehat: query setelah pemulihan tabrakan masih berjalan normal.
        $this->assertSame(2, LeaveProof::query()->count());
    }

    public function test_audit_memakai_nama_pegawai_sebagai_aktor_saat_user_null(): void
    {
        $fixture = $this->makeFinalApprovedRequest();
        $service = app(LeaveProofService::class);

        $proof = $service->generateForApprovedRequest(
            $fixture['request'],
            $fixture['approver_employee'],
            null,
        );

        // Tanpa user penerbit, FK generated_by tetap null (tidak boleh diisi UUID employee).
        $this->assertNull($proof->generated_by);

        $audit = AuditLog::query()
            ->where('event', 'LEAVE_PROOF_GENERATED')
            ->where('auditable_id', $proof->id)
            ->firstOrFail();

        // user_name jatuh ke nama pegawai penerbit sebagai konteks aktor manusia.
        $this->assertNull($audit->user_id);
        $this->assertSame($fixture['approver_employee']->nama_lengkap, $audit->user_name);

        // generated_by pada payload audit tetap null; tidak ada identitas sensitif employee yang bocor.
        $this->assertNull($audit->new_values['generated_by']);
        $this->assertArrayNotHasKey('nip', $audit->new_values);
        $this->assertArrayNotHasKey('email', $audit->new_values);
    }

    public function test_final_approver_memilih_step_final_dengan_order_tertinggi(): void
    {
        $fixture = $this->makeFinalApprovedRequest();

        // Step final dengan order lebih tinggi sengaja dibuat setelah step final bawaan (order 2),
        // sehingga urutan koleksi menaruh order rendah lebih dulu dan mengunci determinisme pemilihan.
        $approverTertinggi = Employee::factory()->create(['nama_lengkap' => 'Pejabat Tertinggi']);
        LeaveRequestStep::create([
            'leave_request_id' => $fixture['request']->id,
            'step_order' => 3,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC Tertinggi',
            'approver_employee_id' => $approverTertinggi->id,
            'status' => 'approved',
            'is_final' => true,
            'acted_at' => Carbon::parse('2026-07-03 11:00:00'),
        ]);

        $proof = app(LeaveProofService::class)->generateForApprovedRequest(
            $fixture['request']->fresh(),
            $fixture['approver_employee'],
            $fixture['approver_user'],
        );

        // Final approver wajib step final dengan step_order tertinggi (posisi rantai terakhir), bukan yang pertama tersimpan.
        $this->assertSame('Pejabat Tertinggi', $proof->metadata['final_approver']['name']);
        $this->assertSame('PYBMC Tertinggi', $proof->metadata['final_approver']['role']);
    }

    public function test_metadata_generated_at_sama_dengan_kolom_generated_at(): void
    {
        $fixture = $this->makeFinalApprovedRequest();
        $service = app(LeaveProofService::class);

        // Setiap panggilan now() maju satu detik; bila metadata dan kolom memakai dua now() terpisah,
        // keduanya pasti berbeda. Test ini membuktikan penerbitan memakai satu sumber waktu yang sama.
        $base = Carbon::parse('2026-07-10 10:00:00');
        $tick = 0;
        Carbon::setTestNow(function () use ($base, &$tick): Carbon {
            return $base->copy()->addSeconds($tick++);
        });

        try {
            $proof = $service->generateForApprovedRequest(
                $fixture['request'],
                $fixture['approver_employee'],
                $fixture['approver_user'],
            );
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(
            $proof->generated_at->toIso8601String(),
            $proof->metadata['generated_at'],
        );
    }

    public function test_approval_intermediate_tidak_menerbitkan_bukti_cuti(): void
    {
        $fixture = $this->makePendingApproval('Cuti Tahunan', createBalance: true);
        $service = app(LeaveApprovalService::class);

        // Approve tahap pertama (bukan final): pengajuan hanya berpindah ke step berikutnya.
        $service->approve(
            $fixture['request'],
            $fixture['kepala_bagian'],
            $fixture['request']->steps()->where('status', 'active')->valueOrFail('id'),
            (int) $fixture['request']->fresh()->revision_version,
            null,
            $fixture['kepala_bagian_user'],
        );

        $this->assertSame('menunggu_approval', $fixture['request']->fresh()->status);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $fixture['request']->id,
            'step_order' => 2,
            'status' => 'active',
        ]);

        // Bukti cuti dan auditnya hanya boleh terbit pada persetujuan final, bukan tahap antara.
        $this->assertSame(0, LeaveProof::query()->where('leave_request_id', $fixture['request']->id)->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'LEAVE_PROOF_GENERATED')->count());
    }

    public function test_approval_final_menerbitkan_bukti_dan_audit_dengan_generated_by_user_aktor(): void
    {
        $fixture = $this->makePendingApproval('Cuti Tahunan', createBalance: true);
        $service = app(LeaveApprovalService::class);

        $service->approve($fixture['request'], $fixture['kepala_bagian'], $fixture['request']->steps()->where('status', 'active')->valueOrFail('id'), (int) $fixture['request']->fresh()->revision_version, null, $fixture['kepala_bagian_user']);
        $final = $service->approve($fixture['request']->fresh(), $fixture['pybmc_employee'], $fixture['request']->steps()->where('status', 'active')->valueOrFail('id'), (int) $fixture['request']->fresh()->revision_version, null, $fixture['pybmc_user']);

        $this->assertSame('disetujui', $final->status);

        // Tepat satu bukti terbit untuk pengajuan final.
        $proof = LeaveProof::query()->where('leave_request_id', $fixture['request']->id)->firstOrFail();
        $this->assertSame(1, LeaveProof::query()->where('leave_request_id', $fixture['request']->id)->count());

        // generated_by wajib UUID user aktor persetujuan, bukan UUID employee.
        $this->assertSame($fixture['pybmc_user']->id, $proof->generated_by);
        $this->assertNotSame($fixture['pybmc_employee']->id, $proof->generated_by);

        // Audit penerbitan wajib ada dan menautkan user aktor sebagai penerbit.
        $audit = AuditLog::query()
            ->where('event', 'LEAVE_PROOF_GENERATED')
            ->where('auditable_id', $proof->id)
            ->firstOrFail();
        $this->assertSame($fixture['pybmc_user']->id, $audit->user_id);
        $this->assertSame($fixture['pybmc_user']->id, $audit->new_values['generated_by']);

        // Saldo tahunan tetap terpotong pada persetujuan final (bukti terbit setelah pemotongan).
        $balance = LeaveBalance::where('employee_id', $fixture['pemohon_employee']->id)->where('tahun', 2026)->firstOrFail();
        $this->assertSame(3, $balance->terpakai);
    }

    public function test_approval_final_non_tahunan_tetap_menerbitkan_bukti_tanpa_potong_saldo(): void
    {
        // Cuti sakit tidak mengurangi saldo tahunan, tetapi persetujuan final tetap wajib menerbitkan bukti.
        $fixture = $this->makePendingApproval('Cuti Sakit', createBalance: false);
        $service = app(LeaveApprovalService::class);

        $service->approve($fixture['request'], $fixture['kepala_bagian'], $fixture['request']->steps()->where('status', 'active')->valueOrFail('id'), (int) $fixture['request']->fresh()->revision_version, null, $fixture['kepala_bagian_user']);
        $final = $service->approve($fixture['request']->fresh(), $fixture['pybmc_employee'], $fixture['request']->steps()->where('status', 'active')->valueOrFail('id'), (int) $fixture['request']->fresh()->revision_version, null, $fixture['pybmc_user']);

        $this->assertSame('disetujui', $final->status);
        $this->assertSame(1, LeaveProof::query()->where('leave_request_id', $fixture['request']->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'LEAVE_PROOF_GENERATED',
        ]);

        // Jenis non-tahunan tetap membentuk fakta dan ledger audit, tetapi tidak membuat projection saldo tahunan.
        $usage = LeaveUsageRecord::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->firstOrFail();
        $this->assertSame(LeaveUsageRecord::SOURCE_APPROVED_REQUEST, $usage->source_type);
        $this->assertSame(3, $usage->workdays);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'leave_request_id' => $fixture['request']->id,
            'event_type' => LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED,
            'amount' => 3,
        ]);
        $this->assertSame(1, LeaveBalanceLedger::where('leave_request_id', $fixture['request']->id)->count());
        $this->assertSame(0, LeaveBalance::where('employee_id', $fixture['pemohon_employee']->id)->count());
    }

    public function test_kegagalan_audit_bukti_merollback_status_final_saldo_dan_bukti(): void
    {
        $fixture = $this->makePendingApproval('Cuti Tahunan', createBalance: true);
        $service = app(LeaveApprovalService::class);

        // Tahap pertama sukses dan commit terpisah: pengajuan berpindah ke step final aktif.
        $service->approve($fixture['request'], $fixture['kepala_bagian'], $fixture['request']->steps()->where('status', 'active')->valueOrFail('id'), (int) $fixture['request']->fresh()->revision_version, null, $fixture['kepala_bagian_user']);

        // Paksa hanya insert audit penerbitan bukti yang gagal, membuktikan audit bukti bersifat fail-closed
        // dan seluruh transaksi persetujuan final (status, saldo, ledger, bukti) wajib di-rollback bersama.
        AuditLog::creating(function (AuditLog $log): void {
            if ($log->event === 'LEAVE_PROOF_GENERATED') {
                throw new \RuntimeException('Simulasi kegagalan insert audit bukti cuti.');
            }
        });

        try {
            $service->approve($fixture['request']->fresh(), $fixture['pybmc_employee'], $fixture['request']->steps()->where('status', 'active')->valueOrFail('id'), (int) $fixture['request']->fresh()->revision_version, null, $fixture['pybmc_user']);
            $this->fail('Persetujuan final seharusnya gagal karena audit bukti tidak dapat ditulis.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Simulasi kegagalan insert audit bukti cuti', $e->getMessage());
        }

        // Status final di-rollback ke kondisi sebelum persetujuan final; step final tetap aktif.
        $this->assertSame('menunggu_approval', $fixture['request']->fresh()->status);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $fixture['request']->id,
            'step_order' => 2,
            'status' => 'active',
        ]);

        // Tidak ada catatan approval final, bukti, maupun audit bukti yang tersisa.
        $this->assertDatabaseMissing('leave_approvals', [
            'leave_request_id' => $fixture['request']->id,
            'stage' => 2,
            'action' => 'APPROVE',
        ]);
        $this->assertSame(0, LeaveProof::query()->where('leave_request_id', $fixture['request']->id)->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'LEAVE_PROOF_GENERATED')->count());

        // Saldo tahunan dan ledger ikut di-rollback: tidak ada pemotongan yang bocor.
        $this->assertSame(0, LeaveBalanceLedger::where('leave_request_id', $fixture['request']->id)->count());
        $balance = LeaveBalance::where('employee_id', $fixture['pemohon_employee']->id)->where('tahun', 2026)->firstOrFail();
        $this->assertSame(0, $balance->terpakai);
        $this->assertSame(12, $balance->sisa);
    }

    public function test_acting_user_yang_bukan_akun_approver_ditolak_tanpa_efek_samping(): void
    {
        $fixture = $this->makePendingApproval('Cuti Tahunan', createBalance: true);
        $service = app(LeaveApprovalService::class);

        // Akun user milik pegawai lain, bukan approver Employee yang berwenang atas step aktif.
        $penyusupEmployee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Lain']);
        $penyusupUser = User::factory()->create(['employee_id' => $penyusupEmployee->id]);

        try {
            // Aktor Employee sah sebagai approver step, namun akun user yang menekan aksi bukan akun approver tersebut.
            $service->approve(
                $fixture['request'],
                $fixture['kepala_bagian'],
                $fixture['request']->steps()->where('status', 'active')->valueOrFail('id'),
                (int) $fixture['request']->fresh()->revision_version,
                null,
                $penyusupUser,
            );
            $this->fail('Persetujuan seharusnya ditolak karena akun user tidak cocok dengan approver Employee.');
        } catch (AuthorizationException $e) {
            // Guard harus berjalan sebelum transaksi/mutasi apa pun.
        }

        // Tidak boleh ada perubahan status, step, approval, bukti, maupun saldo.
        $this->assertSame('menunggu_approval', $fixture['request']->fresh()->status);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $fixture['request']->id,
            'step_order' => 1,
            'status' => 'active',
        ]);
        $this->assertSame(0, LeaveApproval::query()->where('leave_request_id', $fixture['request']->id)->count());
        $this->assertSame(0, LeaveProof::query()->where('leave_request_id', $fixture['request']->id)->count());
        $this->assertSame(0, LeaveBalanceLedger::where('leave_request_id', $fixture['request']->id)->count());

        $balance = LeaveBalance::where('employee_id', $fixture['pemohon_employee']->id)->where('tahun', 2026)->firstOrFail();
        $this->assertSame(0, $balance->terpakai);
        $this->assertSame(12, $balance->sisa);
    }

    public function test_admin_detail_menampilkan_link_bukti_persetujuan_untuk_pengajuan_final_dengan_proof(): void
    {
        $this->seed(RbacSeeder::class);
        $fixture = $this->makeFinalApprovedRequest();
        $proof = app(LeaveProofService::class)->generateForApprovedRequest(
            $fixture['request'],
            $fixture['approver_employee'],
            $fixture['approver_user'],
        );
        $admin = User::factory()->superAdmin()->create();
        $expectedUrl = route('cuti.verify', ['token' => $proof->token]);

        $response = $this->actingAs($admin)->get(route('cuti.show', $fixture['request']));

        $response->assertOk();
        $response->assertSee('Bukti Persetujuan Cuti');
        $response->assertSee($expectedUrl);
        $response->assertSee(
            '<a href="'.$expectedUrl.'" target="_blank" rel="noopener noreferrer"',
            false,
        );
    }

    public function test_admin_detail_tidak_menampilkan_bukti_persetujuan_tanpa_proof(): void
    {
        $this->seed(RbacSeeder::class);
        $fixture = $this->makeFinalApprovedRequest();
        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->get(route('cuti.show', $fixture['request']));

        $response->assertOk();
        $response->assertDontSee('Bukti Persetujuan Cuti');
        $response->assertDontSee('/cuti/verifikasi/');
    }

    public function test_admin_detail_tidak_menjalankan_query_proof_saat_render_view(): void
    {
        $this->seed(RbacSeeder::class);
        $fixture = $this->makeFinalApprovedRequest();
        $admin = User::factory()->superAdmin()->create();
        $proofQueriesDuringRender = [];

        View::composer('admin.cuti.show', function () use (&$proofQueriesDuringRender): void {
            DB::listen(function (QueryExecuted $query) use (&$proofQueriesDuringRender): void {
                if (str_contains($query->sql, 'from "leave_proofs"')) {
                    $proofQueriesDuringRender[] = $query->sql;
                }
            });
        });

        $response = $this->actingAs($admin)->get(route('cuti.show', $fixture['request']));

        $response->assertOk();
        $this->assertEmpty($proofQueriesDuringRender);
    }

    public function test_public_verification_route_with_valid_token_returns_200_and_expected_content(): void
    {
        $fixture = $this->makeFinalApprovedRequest();
        $service = app(LeaveProofService::class);
        $proof = $service->generateForApprovedRequest(
            $fixture['request'],
            $fixture['approver_employee'],
            $fixture['approver_user'],
        );

        $response = $this->get('/cuti/verifikasi/'.$proof->token);
        $response->assertStatus(200);

        // Content checks
        $response->assertSee('LLDIKTI Wilayah XVI');
        $response->assertSee($fixture['pemohon_employee']->nama_lengkap);
        $response->assertSee('Cuti Tahunan');
        $response->assertSee($fixture['approver_employee']->nama_lengkap);
        $response->assertDontSee('Keperluan keluarga.');

        // Verify URL is present
        $expectedUrl = route('cuti.verify', ['token' => $proof->token]);
        $response->assertSee($expectedUrl);

        // SVG QR is present (via BaconQrCode output)
        $response->assertSee('<svg', false);

        // Exclusions:
        // Do not leak sensitive values
        $response->assertDontSee($fixture['pemohon_employee']->nip);
        $response->assertDontSee($fixture['pemohon_user']->email);
        // Exclude approval comments/decision note
        $response->assertDontSee('Disetujui kepala bagian.');
        $response->assertDontSee('Disetujui PYBMC.');
        // No UUID IDs in plaintext labels
        $response->assertDontSee($proof->id);
    }

    public function test_public_verification_route_rejects_malformed_tokens_with_404(): void
    {
        $this->get('/cuti/verifikasi/short_token')->assertStatus(404);
        $this->get('/cuti/verifikasi/not_valid_token_because_it_has_invalid_symbols$!@#')->assertStatus(404);
    }

    public function test_public_verification_route_returns_404_for_unknown_token(): void
    {
        $unknownToken = str_repeat('z', 64);
        $this->get('/cuti/verifikasi/'.$unknownToken)->assertStatus(404);
    }

    public function test_public_verification_route_with_non_final_leave_request_status_returns_404(): void
    {
        $fixture = $this->makeFinalApprovedRequest();
        $service = app(LeaveProofService::class);
        $proof = $service->generateForApprovedRequest(
            $fixture['request'],
            $fixture['approver_employee'],
            $fixture['approver_user'],
        );

        // Ubah status pengajuan menjadi non-disetujui (mis. ditangguhkan atau menunggu_approval)
        $fixture['request']->forceFill(['status' => 'menunggu_approval'])->save();

        $response = $this->get('/cuti/verifikasi/'.$proof->token);
        $response->assertStatus(404);
        $response->assertDontSee($fixture['pemohon_employee']->nama_lengkap);
    }

    public function test_route_verification_middleware_is_properly_configured(): void
    {
        $route = Route::getRoutes()->getByName('cuti.verify');

        $this->assertNotNull($route);
        $middleware = $route->gatherMiddleware();

        // Harus berisi throttle:60,1
        $this->assertContains('throttle:60,1', $middleware);

        // Tidak boleh berisi keycloak.auth, auth, atau session.timeout
        $this->assertNotContains('keycloak.auth', $middleware);
        $this->assertNotContains('auth', $middleware);
        $this->assertNotContains('session.timeout', $middleware);
    }

    public function test_public_verification_route_with_legacy_and_malformed_metadata_returns_200_without_exceptions(): void
    {
        $fixture = $this->makeFinalApprovedRequest();
        $service = app(LeaveProofService::class);
        $proof = $service->generateForApprovedRequest(
            $fixture['request'],
            $fixture['approver_employee'],
            $fixture['approver_user'],
        );

        // Modifikasi metadata menjadi data malformed/tidak lengkap untuk mensimulasikan data legacy
        $malformedMetadata = [
            'institution' => 'LLDIKTI Wilayah XVI',
            'employee_name' => 'Pegawai Legacy',
            'leave_type' => 'Cuti Tahunan',
            'start_date' => 'tanggal-tidak-valid',
            'end_date' => null,
            'workday_count' => 3,
            'reason' => 'Alasan Legacy Rahasia',
            'alasan' => 'Alasan Bahasa Indonesia Rahasia',
            'unknown_sensitive_key' => 'Metadata Asing Rahasia',
            'status_label' => 'Disetujui',
            'final_approver' => [
                'name' => 'Approver Legacy',
                'role' => null,
                'acted_at' => 'waktu-tidak-valid',
            ],
            'approval_timeline' => [
                [
                    'order' => 1,
                    'role' => 'Kepala Bagian',
                    'approver_name' => 'Atasan Legacy',
                    // missing 'status'
                    'acted_at' => 'waktu-tidak-valid',
                ],
            ],
            'generated_at' => '2026-invalid-date',
        ];

        $proof->forceFill(['metadata' => $malformedMetadata])->save();

        $response = $this->get('/cuti/verifikasi/'.$proof->token);
        $response->assertStatus(200);

        // Pastikan fallback yang aman ditampilkan di HTML
        $response->assertSee('Pegawai Legacy');
        $response->assertSee('-'); // Fallback untuk end_date yang null
        $response->assertSee('Tidak diketahui'); // Fallback untuk status timeline yang hilang
        $response->assertDontSee('Alasan Legacy Rahasia');
        $response->assertDontSee('Alasan Bahasa Indonesia Rahasia');
        $response->assertDontSee('Metadata Asing Rahasia');
    }

    public function test_public_verification_route_with_malformed_metadata_fields_returns_200_with_proper_fallbacks(): void
    {
        $fixture = $this->makeFinalApprovedRequest();
        $service = app(LeaveProofService::class);
        $proof = $service->generateForApprovedRequest(
            $fixture['request'],
            $fixture['approver_employee'],
            $fixture['approver_user'],
        );

        $malformedMetadata = [
            'institution' => 'LLDIKTI Wilayah XVI',
            'employee_name' => 'Pegawai Malformed',
            'leave_type' => 'Cuti Tahunan',
            'start_date' => 'tanggal-mulai-malformed',
            'end_date' => 'tanggal-selesai-malformed',
            'generated_at' => 'generated-at-malformed',
            'workday_count' => 5,
            'reason' => 'Alasan Malformed',
            'status_label' => 'Disetujui',
            'final_approver' => [
                'name' => 'Approver Malformed',
                'role' => 'PYBMC',
                'acted_at' => 'acted-at-malformed',
            ],
            'approval_timeline' => [
                [
                    'order' => 1,
                    'role' => 'Kepala Bagian',
                    'approver_name' => 'Atasan Kesatu',
                    'acted_at' => 'acted-at-timeline-malformed',
                ],
            ],
        ];

        $proof->forceFill(['metadata' => $malformedMetadata])->save();

        $response = $this->get('/cuti/verifikasi/'.$proof->token);

        $response->assertStatus(200);
        $response->assertSee('Pegawai Malformed');
        $response->assertSee('Tidak diketahui');
        $response->assertSee('-');
    }

    public function test_public_verification_route_with_scalar_nested_metadata_returns_200_with_proper_fallbacks(): void
    {
        $fixture = $this->makeFinalApprovedRequest();
        $service = app(LeaveProofService::class);
        $proof = $service->generateForApprovedRequest(
            $fixture['request'],
            $fixture['approver_employee'],
            $fixture['approver_user'],
        );

        $scalarMetadata = [
            'institution' => 'LLDIKTI Wilayah XVI',
            'employee_name' => 'Pegawai Skalar',
            'leave_type' => 'Cuti Tahunan',
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-08',
            'generated_at' => '2026-07-10 10:00:00',
            'workday_count' => 3,
            'reason' => 'Alasan Skalar',
            'status_label' => 'Disetujui',
            'final_approver' => 'Nama Approver Berupa String Bukan Array',
            'approval_timeline' => 'Timeline Berupa String Bukan Array',
        ];

        $proof->forceFill(['metadata' => $scalarMetadata])->save();

        $response = $this->get('/cuti/verifikasi/'.$proof->token);

        $response->assertStatus(200);
        $response->assertSee('Pegawai Skalar');
    }

    public function test_public_verification_route_with_timeline_containing_scalar_returns_200_with_proper_fallbacks(): void
    {
        $fixture = $this->makeFinalApprovedRequest();
        $service = app(LeaveProofService::class);
        $proof = $service->generateForApprovedRequest(
            $fixture['request'],
            $fixture['approver_employee'],
            $fixture['approver_user'],
        );

        $mixedTimelineMetadata = [
            'institution' => 'LLDIKTI Wilayah XVI',
            'employee_name' => 'Pegawai Mixed Timeline',
            'leave_type' => 'Cuti Tahunan',
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-08',
            'generated_at' => '2026-07-10 10:00:00',
            'workday_count' => 3,
            'reason' => 'Alasan Mixed Timeline',
            'status_label' => 'Disetujui',
            'final_approver' => [
                'name' => 'Approver Valid',
                'role' => 'PYBMC',
                'acted_at' => '2026-07-02 10:00:00',
            ],
            'approval_timeline' => [
                'Item timeline berupa string skalar',
                [
                    'order' => 2,
                    'role' => 'PYBMC',
                    'approver_name' => 'Approver Valid',
                    'status' => 'approved',
                    'acted_at' => '2026-07-02 10:00:00',
                ],
                null,
            ],
        ];

        $proof->forceFill(['metadata' => $mixedTimelineMetadata])->save();

        $response = $this->get('/cuti/verifikasi/'.$proof->token);

        $response->assertStatus(200);
        $response->assertSee('Pegawai Mixed Timeline');
        $response->assertSee('Approver Valid');
    }

    public function test_public_verification_route_with_array_leaf_values_returns_200_with_safe_fallbacks(): void
    {
        $fixture = $this->makeFinalApprovedRequest();
        $service = app(LeaveProofService::class);
        $proof = $service->generateForApprovedRequest(
            $fixture['request'],
            $fixture['approver_employee'],
            $fixture['approver_user'],
        );

        // Metadata korup: leaf yang seharusnya skalar diisi container array/objek-like JSON.
        // Ini mensimulasikan data legacy/tercemar yang, tanpa normalisasi, memaksa Blade e() menerima
        // array dan melempar TypeError sehingga rute publik 500.
        $arrayLeafMetadata = [
            'institution' => ['PENANDA_INSTITUSI_BOCOR'],
            'employee_name' => ['PENANDA_NAMA_PEGAWAI_BOCOR'],
            'leave_type' => ['PENANDA_JENIS_CUTI_BOCOR'],
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-08',
            'generated_at' => '2026-07-10 10:00:00',
            'workday_count' => ['PENANDA_JUMLAH_HARI_BOCOR'],
            'status_label' => ['PENANDA_STATUS_LABEL_BOCOR'],
            'final_approver' => [
                'name' => ['PENANDA_APPROVER_NAMA_BOCOR'],
                'role' => ['PENANDA_APPROVER_ROLE_BOCOR'],
                'acted_at' => '2026-07-02 10:00:00',
            ],
            'approval_timeline' => [
                [
                    'order' => ['PENANDA_ORDER_BOCOR'],
                    'role' => ['PENANDA_TIMELINE_ROLE_BOCOR'],
                    'approver_name' => ['PENANDA_TIMELINE_NAMA_BOCOR'],
                    'status' => ['PENANDA_TIMELINE_STATUS_BOCOR'],
                    'acted_at' => '2026-07-02 10:00:00',
                ],
            ],
        ];

        $proof->forceFill(['metadata' => $arrayLeafMetadata])->save();

        $response = $this->get('/cuti/verifikasi/'.$proof->token);

        // Leaf array pada posisi skalar tidak boleh memicu 500; rute publik harus fail-safe.
        $response->assertStatus(200);

        // Fallback aman wajib tampil: institusi jatuh ke nama institusi default, status timeline korup jadi 'Tidak diketahui'.
        $response->assertSee('LLDIKTI Wilayah XVI');
        $response->assertSee('Tidak diketahui');

        // Tidak ada isi container array yang bocor ke HTML publik pada leaf mana pun.
        $response->assertDontSee('PENANDA_INSTITUSI_BOCOR');
        $response->assertDontSee('PENANDA_NAMA_PEGAWAI_BOCOR');
        $response->assertDontSee('PENANDA_JENIS_CUTI_BOCOR');
        $response->assertDontSee('PENANDA_JUMLAH_HARI_BOCOR');
        $response->assertDontSee('PENANDA_STATUS_LABEL_BOCOR');
        $response->assertDontSee('PENANDA_APPROVER_NAMA_BOCOR');
        $response->assertDontSee('PENANDA_APPROVER_ROLE_BOCOR');
        $response->assertDontSee('PENANDA_ORDER_BOCOR');
        $response->assertDontSee('PENANDA_TIMELINE_ROLE_BOCOR');
        $response->assertDontSee('PENANDA_TIMELINE_NAMA_BOCOR');
        $response->assertDontSee('PENANDA_TIMELINE_STATUS_BOCOR');
        // Kata literal "Array" (hasil "Array to string conversion") juga tidak boleh muncul.
        $response->assertDontSee('Array');
    }

    /**
     * Membuat pengajuan cuti berstatus menunggu_approval dengan snapshot dua step:
     * step 1 (kepala bagian) aktif dan non-final, step 2 (PYBMC) pending dan final.
     * Fixture ini dipakai untuk menjalankan LeaveApprovalService::approve() sungguhan
     * dari tahap antara sampai persetujuan final.
     *
     * @return array{
     *     pemohon_employee: Employee,
     *     kepala_bagian: Employee,
     *     kepala_bagian_user: User,
     *     pybmc_employee: Employee,
     *     pybmc_user: User,
     *     jenis: RefJenisCuti,
     *     request: LeaveRequest
     * }
     */
    private function makePendingApproval(string $namaJenis, bool $createBalance): array
    {
        $pemohonEmployee = Employee::factory()->create(['nama_lengkap' => 'Pemohon Approval']);
        $kepalaBagian = Employee::factory()->create(['nama_lengkap' => 'Kepala Bagian Approval']);
        $kepalaBagianUser = User::factory()->create(['employee_id' => $kepalaBagian->id]);
        $pybmcEmployee = Employee::factory()->create(['nama_lengkap' => 'Pejabat PYBMC Approval']);
        $pybmcUser = User::factory()->create(['employee_id' => $pybmcEmployee->id]);

        $code = match ($namaJenis) {
            'Cuti Tahunan' => 'tahunan',
            'Cuti Sakit' => 'sakit',
            default => str($namaJenis)->slug('_')->toString(),
        };
        $jenis = RefJenisCuti::firstOrCreate(
            ['code' => $code],
            [
                'nama' => $namaJenis,
                'mengurangi_saldo_tahunan' => $namaJenis === 'Cuti Tahunan',
                'khusus_pns' => false,
            ],
        );

        if ($createBalance) {
            Appointment::create([
                'employee_id' => $pemohonEmployee->id,
                'jenis_pengangkatan' => 'PNS',
                'tmt_pengangkatan' => '2020-01-01',
            ]);
            $this->seed(RbacSeeder::class);
            $admin = User::factory()->adminKepegawaian()->create();
            app(ReconcileAnnualLeaveUsageAction::class)->execute(
                $pemohonEmployee->id,
                [
                    'balance_year' => 2026,
                    'usage_n2' => 12,
                    'usage_n1' => 12,
                    'usage_current' => 0,
                    'administrative_note' => 'Rekonsiliasi fixture penerbitan bukti cuti.',
                ],
                $admin,
                $this->actorRequest($admin),
            );
        }

        $request = LeaveRequest::create([
            'employee_id' => $pemohonEmployee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-08',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
        ]);

        LeaveRequestStep::create([
            'leave_request_id' => $request->id,
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $kepalaBagian->id,
            'status' => 'active',
            'is_final' => false,
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $request->id,
            'step_order' => 2,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $pybmcEmployee->id,
            'status' => 'pending',
            'is_final' => true,
        ]);

        return [
            'pemohon_employee' => $pemohonEmployee,
            'kepala_bagian' => $kepalaBagian,
            'kepala_bagian_user' => $kepalaBagianUser,
            'pybmc_employee' => $pybmcEmployee,
            'pybmc_user' => $pybmcUser,
            'jenis' => $jenis,
            'request' => $request->fresh(),
        ];
    }

    private function actorRequest(User $actor): Request
    {
        $request = Request::create('/cuti/reconciliation', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }

    /**
     * Membuat pengajuan cuti final beserta step dan approval yang sudah disetujui penuh.
     *
     * @return array{
     *     pemohon_employee: Employee,
     *     pemohon_user: User,
     *     approver_employee: Employee,
     *     approver_user: User,
     *     jenis: RefJenisCuti,
     *     request: LeaveRequest
     * }
     */
    private function makeFinalApprovedRequest(): array
    {
        $pemohonEmployee = Employee::factory()->create(['nama_lengkap' => 'Pemohon Cuti']);
        $pemohonUser = User::factory()->create(['employee_id' => $pemohonEmployee->id]);

        $kepalaBagian = Employee::factory()->create(['nama_lengkap' => 'Kepala Bagian']);

        $approverEmployee = Employee::factory()->create(['nama_lengkap' => 'Pejabat PYBMC']);
        $approverUser = User::factory()->create(['employee_id' => $approverEmployee->id]);

        // firstOrCreate agar helper aman dipanggil lebih dari sekali dalam satu test tanpa menabrak unique code.
        $jenis = RefJenisCuti::firstOrCreate(
            ['code' => 'tahunan'],
            [
                'nama' => 'Cuti Tahunan',
                'mengurangi_saldo_tahunan' => true,
                'khusus_pns' => false,
            ],
        );

        $request = LeaveRequest::create([
            'employee_id' => $pemohonEmployee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-08',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'disetujui',
        ]);

        LeaveRequestStep::create([
            'leave_request_id' => $request->id,
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $kepalaBagian->id,
            'status' => 'approved',
            'is_final' => false,
            'acted_at' => Carbon::parse('2026-07-01 09:00:00'),
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $request->id,
            'step_order' => 2,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $approverEmployee->id,
            'status' => 'approved',
            'is_final' => true,
            'acted_at' => Carbon::parse('2026-07-02 10:00:00'),
        ]);

        LeaveApproval::create([
            'leave_request_id' => $request->id,
            'approver_id' => $kepalaBagian->id,
            'stage' => 1,
            'action' => 'APPROVE',
            'komentar' => 'Disetujui kepala bagian.',
            'acted_at' => Carbon::parse('2026-07-01 09:00:00'),
        ]);
        LeaveApproval::create([
            'leave_request_id' => $request->id,
            'approver_id' => $approverEmployee->id,
            'stage' => 2,
            'action' => 'APPROVE',
            'komentar' => 'Disetujui PYBMC.',
            'acted_at' => Carbon::parse('2026-07-02 10:00:00'),
        ]);

        return [
            'pemohon_employee' => $pemohonEmployee,
            'pemohon_user' => $pemohonUser,
            'approver_employee' => $approverEmployee,
            'approver_user' => $approverUser,
            'jenis' => $jenis,
            'request' => $request->fresh(),
        ];
    }

    /**
     * Memastikan sejumlah kunci sensitif tidak muncul pada level manapun dari struktur array.
     *
     * @param  array<mixed>  $data
     * @param  list<string>  $forbiddenKeys
     */
    private function assertKeysAbsentRecursively(array $data, array $forbiddenKeys): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $this->assertNotContains($key, $forbiddenKeys, "Kunci sensitif '{$key}' tidak boleh ada di snapshot metadata publik.");
            }

            if (is_array($value)) {
                $this->assertKeysAbsentRecursively($value, $forbiddenKeys);
            }
        }
    }
}
