<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\Permission;
use App\Models\RefJenisCuti;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Menguji penegakan RBAC modul cuti pada level seeder dan model.
 * Otorisasi cuti harus ditegakkan di backend; permission level-aksi menjadi gerbang kasar
 * sebelum otorisasi inti berbasis orang (approver terkonfigurasi) diterapkan di approval engine.
 */
class CutiRbacTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Daftar permission cuti yang wajib tersedia setelah seeding RBAC.
     * Mengikuti konvensi module.action yang sudah dipakai seluruh permission lain.
     */
    private const CUTI_PERMISSIONS = [
        'cuti.create',
        'cuti.read_own',
        'cuti.read_all',
        'cuti.approve',
        'cuti.approve_stage1',
        'cuti.approve_stage2',
        'cuti.approve_stage3',
        'cuti.configure',
        'cuti.configure_chain',
        'cuti.balance.read',
        'cuti.balance.reconcile',
        'cuti.manual.manage',
        'cuti.proof.generate',
        'cuti.kepala_lembaga_documents.manage',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_seeding_membuat_semua_permission_cuti(): void
    {
        foreach (self::CUTI_PERMISSIONS as $permission) {
            $this->assertDatabaseHas('permissions', [
                'name' => $permission,
                'module' => 'cuti',
            ]);
        }
    }

    public function test_reseed_memperbarui_metadata_role_dan_permission_yang_sudah_ada(): void
    {
        Role::query()->where('name', 'kepala_bagian')->update([
            'description' => 'Deskripsi role lama.',
        ]);
        Permission::query()->where('name', 'cuti.create')->update([
            'module' => 'legacy',
            'description' => 'Deskripsi permission lama.',
        ]);

        $this->seed(RbacSeeder::class);

        $this->assertDatabaseHas('roles', [
            'name' => 'kepala_bagian',
            'description' => 'Kepala Bagian — approval cuti bawahan dan pengajuan cuti sendiri',
        ]);
        $this->assertDatabaseHas('permissions', [
            'name' => 'cuti.create',
            'module' => 'cuti',
            'description' => 'Mengajukan permohonan cuti',
        ]);
    }

    public function test_super_admin_tidak_mewarisi_permission_mutasi_khusus_admin_kepegawaian(): void
    {
        $user = User::factory()->superAdmin()->create();
        $excluded = ['cuti.create', 'cuti.balance.reconcile', 'cuti.manual.manage'];

        foreach (self::CUTI_PERMISSIONS as $permission) {
            $this->assertSame(
                ! in_array($permission, $excluded, true),
                $user->hasPermission($permission),
                "mapping cuti super_admin tidak sesuai untuk {$permission}",
            );
        }
    }

    public function test_permission_rekonsiliasi_dan_manual_hanya_dipetakan_ke_admin_kepegawaian(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $adminRole = Role::query()->where('name', 'admin_kepegawaian')->firstOrFail();
        $superAdminRole = Role::query()->where('name', 'super_admin')->firstOrFail();

        foreach (['cuti.balance.reconcile', 'cuti.manual.manage'] as $permissionName) {
            $permission = Permission::query()->where('name', $permissionName)->firstOrFail();

            $this->assertTrue($admin->hasPermission($permissionName));
            $this->assertFalse($superAdmin->hasPermission($permissionName));
            $this->assertDatabaseHas('role_permissions', [
                'role_id' => $adminRole->id,
                'permission_id' => $permission->id,
            ]);
            $this->assertDatabaseMissing('role_permissions', [
                'role_id' => $superAdminRole->id,
                'permission_id' => $permission->id,
            ]);
        }
    }

    public static function rolePemohonProvider(): array
    {
        return [
            'admin kepegawaian' => ['admin_kepegawaian'],
            'pimpinan' => ['pimpinan'],
            'kepala bagian' => ['kepala_bagian'],
            'pegawai' => ['pegawai'],
        ];
    }

    #[DataProvider('rolePemohonProvider')]
    public function test_role_self_service_memiliki_permission_cuti_create(string $role): void
    {
        $user = User::factory()->state(['role' => $role])->create();

        $this->assertTrue($user->hasPermission('cuti.create'), "role {$role} harus memiliki cuti.create");
    }

    public function test_pegawai_bisa_ajukan_cuti_tetapi_tidak_bisa_approve_atau_konfigurasi(): void
    {
        $user = User::factory()->pegawai()->create();

        // Pegawai sebagai pemohon cuti hanya boleh membuat pengajuan.
        $this->assertTrue($user->hasPermission('cuti.create'));

        // Pegawai tidak boleh menyetujui pengajuan pada stage manapun.
        $this->assertFalse($user->hasPermission('cuti.approve_stage1'));
        $this->assertFalse($user->hasPermission('cuti.approve_stage2'));
        $this->assertFalse($user->hasPermission('cuti.approve_stage3'));

        // Pegawai tidak boleh melihat seluruh pengajuan maupun mengonfigurasi approval chain.
        $this->assertFalse($user->hasPermission('cuti.read_all'));
        $this->assertFalse($user->hasPermission('cuti.configure'));
    }

    public function test_pegawai_yang_menjadi_approver_snapshot_bisa_mengambil_keputusan(): void
    {
        $approver = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $approver->id]);
        $pemohon = Employee::factory()->create();
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Sakit',
            'code' => 'cuti_sakit_rbac',
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

        $response = $this->actingAs($user)->post(route('cuti.approve', $cuti), [
            'active_step_id' => $cuti->steps()->where('status', 'active')->valueOrFail('id'),
        ]);

        $response->assertRedirect(route('cuti.approval'));
        $this->assertSame('disetujui', $cuti->fresh()->status);

        $audit = AuditLog::query()
            ->where('auditable_type', 'LeaveRequest')
            ->where('auditable_id', $cuti->id)
            ->where('event', 'DECIDE')
            ->firstOrFail();

        $this->assertSame([
            'leave_request_id' => $cuti->id,
            'employee_id' => $pemohon->id,
            'status' => 'menunggu_approval',
            'step_order' => 1,
            'step_label' => 'Verifikator',
            'approver_id' => $approver->id,
        ], $audit->old_values);
        $this->assertSame([
            'leave_request_id' => $cuti->id,
            'employee_id' => $pemohon->id,
            'status' => 'disetujui',
            'decision' => 'DECIDE',
            'step_order' => 1,
            'step_label' => 'Verifikator',
            'approver_id' => $approver->id,
            'acted_at' => $audit->new_values['acted_at'],
            'komentar' => null,
            'actor_role' => 'pegawai',
        ], $audit->new_values);
    }

    public function test_pegawai_yang_menjadi_approver_snapshot_bisa_membuka_detail_pengajuan(): void
    {
        $approver = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $approver->id]);
        $pemohon = Employee::factory()->create();
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Sakit Detail',
            'code' => 'cuti_sakit_detail_rbac',
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

        $response = $this->actingAs($user)->get(route('cuti.show', $cuti));

        $response->assertOk();
        $response->assertSee('Keperluan keluarga.');
        $response->assertSee('Setujui');
    }

    public function test_kepala_bagian_hanya_bisa_approve_stage1(): void
    {
        $user = User::factory()->kepalaBagian()->create();

        // Kepala bagian memegang gerbang stage 1 (mengetahui pengajuan bawahan).
        $this->assertTrue($user->hasPermission('cuti.approve_stage1'));

        // Atasan langsung bukan approver stage 2/3 dan tidak mengonfigurasi approval chain.
        $this->assertFalse($user->hasPermission('cuti.approve_stage2'));
        $this->assertFalse($user->hasPermission('cuti.approve_stage3'));
        $this->assertFalse($user->hasPermission('cuti.configure'));
    }

    public function test_pimpinan_bisa_approve_stage3_dan_lihat_semua_cuti(): void
    {
        $user = User::factory()->pimpinan()->create();

        // Pimpinan (Kepala Lembaga/PYBMC) adalah approver final dan dapat memonitor seluruh pengajuan.
        $this->assertTrue($user->hasPermission('cuti.approve_stage3'));
        $this->assertTrue($user->hasPermission('cuti.read_all'));

        // Pimpinan bukan approver stage 1 dan tidak mengonfigurasi approval chain (khusus super admin).
        $this->assertFalse($user->hasPermission('cuti.approve_stage1'));
        $this->assertFalse($user->hasPermission('cuti.configure'));
    }

    public function test_admin_kepegawaian_hanya_monitor_tidak_bisa_approve(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        // Admin kepegawaian memonitor seluruh pengajuan (read-only), bukan pelaku approval.
        $this->assertTrue($user->hasPermission('cuti.read_all'));

        $this->assertFalse($user->hasPermission('cuti.approve_stage1'));
        $this->assertFalse($user->hasPermission('cuti.approve_stage2'));
        $this->assertFalse($user->hasPermission('cuti.approve_stage3'));
    }

    public function test_konfigurasi_approval_hanya_untuk_super_admin(): void
    {
        // Konfigurasi approval chain dibatasi khusus super admin.
        $superAdmin = User::factory()->superAdmin()->create();
        $this->assertTrue($superAdmin->hasPermission('cuti.configure'));

        foreach (['admin_kepegawaian', 'pimpinan', 'kepala_bagian', 'pegawai'] as $role) {
            $user = User::factory()->state(['role' => $role])->create();
            $this->assertFalse(
                $user->hasPermission('cuti.configure'),
                "role {$role} tidak boleh memiliki cuti.configure",
            );
        }
    }
}
