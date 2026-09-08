<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\Permission;
use App\Models\RefJenisCuti;
use App\Models\RefStatusPegawai;
use App\Models\Role;
use App\Models\User;
use App\Support\Rbac\PatenCapability;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
        'cuti.configure',
        'cuti.balance.read',
        'cuti.balance.reconcile',
        'cuti.manual.manage',
        'cuti.cancellation.manage',
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

    public function test_permission_stage_dan_configure_chain_tidak_terdaftar_di_rbac(): void
    {
        foreach (['cuti.approve_stage1', 'cuti.approve_stage2', 'cuti.approve_stage3', 'cuti.configure_chain'] as $permission) {
            $this->assertDatabaseMissing('permissions', ['name' => $permission]);
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

    public function test_reseed_tidak_menimpa_grant_dan_revoke_matrix_operator(): void
    {
        $permission = Permission::query()->where('name', 'employees.export')->firstOrFail();
        $superAdmin = Role::query()->where('name', 'super_admin')->firstOrFail();
        $kepalaBagian = Role::query()->where('name', 'kepala_bagian')->firstOrFail();

        // Operator mencabut default Super Admin lalu mendelegasikan capability ke Kabag.
        $superAdmin->permissions()->detach($permission->id);
        $kepalaBagian->permissions()->syncWithoutDetaching([$permission->id]);

        $this->seed(RbacSeeder::class);

        $this->assertDatabaseMissing('role_permissions', [
            'role_id' => $superAdmin->id,
            'permission_id' => $permission->id,
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $kepalaBagian->id,
            'permission_id' => $permission->id,
        ]);
    }

    public function test_super_admin_memiliki_semua_permission_cuti(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->superAdmin()->create(['employee_id' => $employee->id]);

        foreach (self::CUTI_PERMISSIONS as $permission) {
            if (PatenCapability::requiresRecordContext($permission)) {
                continue;
            }

            $this->assertTrue($user->hasPermission($permission), "Super Admin harus memiliki {$permission}");
        }
    }

    public function test_permission_manual_didefaultkan_ke_super_admin_dan_admin_kepegawaian(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $pimpinan = User::factory()->pimpinan()->create();
        $adminRole = Role::query()->where('name', 'admin_kepegawaian')->firstOrFail();
        $superAdminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $pimpinanRole = Role::query()->where('name', 'pimpinan')->firstOrFail();
        $permission = Permission::query()->where('name', 'cuti.manual.manage')->firstOrFail();

        $this->assertTrue($admin->hasPermission('cuti.manual.manage'));
        $this->assertTrue($superAdmin->hasPermission('cuti.manual.manage'));
        $this->assertFalse($pimpinan->hasPermission('cuti.manual.manage'));
        $this->assertDatabaseHas('role_permissions', ['role_id' => $adminRole->id, 'permission_id' => $permission->id]);
        $this->assertDatabaseHas('role_permissions', ['role_id' => $superAdminRole->id, 'permission_id' => $permission->id]);
        $this->assertDatabaseMissing('role_permissions', ['role_id' => $pimpinanRole->id, 'permission_id' => $permission->id]);
    }

    public function test_pimpinan_dengan_permission_reconcile_dapat_mencapai_boundary_rekonsiliasi(): void
    {
        $permission = Permission::query()->where('name', 'cuti.balance.reconcile')->firstOrFail();
        Role::query()->where('name', 'pimpinan')->firstOrFail()
            ->permissions()->syncWithoutDetaching([$permission->id]);
        $pimpinan = User::factory()->pimpinan()->create();

        // Payload sengaja tidak lengkap: bila gate role tidak lagi memblokir, FormRequest
        // mengembalikan validasi (redirect), bukan halaman 403 "Tidak Mendapatkan Akses".
        $this->actingAs($pimpinan)
            ->get(route('cuti.saldo.administrasi'))
            ->assertOk();

        $this->actingAs($pimpinan)
            ->post(route('cuti.reconciliation.store', (string) Str::uuid()))
            ->assertRedirect()
            ->assertSessionHasErrors(['balance_year']);
    }

    public function test_capability_paten_tidak_bergantung_pada_pivot_role_permissions(): void
    {
        $role = Role::query()->where('name', 'pimpinan')->firstOrFail();
        $patenPermissions = Permission::query()
            ->whereIn('name', PatenCapability::PERMISSION_NAMES)
            ->pluck('id');
        $role->permissions()->detach($patenPermissions);

        $employee = Employee::factory()->create();
        $user = User::factory()->pimpinan()->create(['employee_id' => $employee->id]);

        foreach ([
            ...PatenCapability::EMPLOYEE_SELF_PERMISSION_NAMES,
            ...PatenCapability::USER_CONTEXT_PERMISSION_NAMES,
        ] as $permission) {
            $this->assertTrue($user->hasPermission($permission), "PATEN {$permission} tidak boleh bergantung pivot legacy");
        }

        foreach (PatenCapability::RECORD_CONTEXT_PERMISSION_NAMES as $permission) {
            $this->assertFalse($user->hasPermission($permission), "PATEN {$permission} harus diputus pada boundary record, bukan User global");
        }
    }

    public function test_paten_memisahkan_lifecycle_employee_dari_capability_user_context(): void
    {
        $nonaktif = RefStatusPegawai::query()->firstOrCreate(
            ['kode' => 'NONAKTIF'],
            [
                'nama' => 'Nonaktif',
                'kelompok' => 'Nonaktif',
                'keterangan' => 'Pegawai tidak aktif.',
                'is_default' => false,
            ],
        );
        $employee = Employee::factory()->create([
            'status_pegawai_id' => $nonaktif->id,
            'status_aktif' => $nonaktif->nama,
        ]);
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        foreach (PatenCapability::EMPLOYEE_SELF_PERMISSION_NAMES as $permission) {
            $this->assertFalse($user->hasPermission($permission), "{$permission} harus fail-closed untuk Employee nonaktif.");
        }

        foreach (PatenCapability::USER_CONTEXT_PERMISSION_NAMES as $permission) {
            $this->assertTrue($user->hasPermission($permission), "{$permission} tetap capability konteks User.");
        }
    }

    public function test_semua_role_default_dapat_melihat_saldo_cuti_sendiri(): void
    {
        foreach (['super_admin', 'admin_kepegawaian', 'pimpinan', 'kepala_bagian', 'pegawai'] as $role) {
            $user = User::factory()->state(['role' => $role])->create();
            $this->assertTrue($user->hasPermission('cuti.balance.read'), "role {$role} harus memiliki cuti.balance.read");
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
        $employee = Employee::factory()->create();
        $user = User::factory()->state(['role' => $role])->create(['employee_id' => $employee->id]);

        $this->assertTrue($user->hasPermission('cuti.create'), "role {$role} harus memiliki cuti.create");
    }

    public function test_pegawai_mendapat_akses_pengajuan_pembacaan_sendiri_dan_saldo(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->assertTrue($user->hasPermission('cuti.create'));
        $this->assertTrue($user->hasPermission('cuti.read_own'));
        $this->assertTrue($user->hasPermission('cuti.balance.read'));
        $this->assertFalse($user->hasPermission('cuti.approve'));
        $this->assertFalse($user->hasPermission('cuti.read_all'));
        $this->assertFalse($user->hasPermission('cuti.configure'));
    }

    public function test_pegawai_yang_menjadi_approver_snapshot_bisa_mengambil_keputusan(): void
    {
        $approver = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $approver->id]);
        $approvalPermission = Permission::query()->where('name', 'cuti.approve')->firstOrFail();
        Role::query()->where('name', 'pegawai')->firstOrFail()->permissions()->detach($approvalPermission->id);
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
            'revision_version' => $cuti->fresh()->revision_version,
        ]);

        $response->assertRedirect(route('cuti.approval'));
        $this->assertSame('disetujui', $cuti->fresh()->status);
        $this->assertDatabaseHas('leave_proofs', ['leave_request_id' => $cuti->id]);

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

    public function test_pemilik_tetap_membaca_pengajuan_sendiri_tanpa_grant_read_own_legacy(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $permission = Permission::query()->where('name', 'cuti.read_own')->firstOrFail();
        Role::query()->where('name', 'pegawai')->firstOrFail()->permissions()->detach($permission->id);
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Milik Sendiri',
            'code' => 'cuti_milik_sendiri_rbac',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $cuti = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-06',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Pengajuan milik sendiri.',
            'status' => 'menunggu_approval',
        ]);

        $this->actingAs($user)->get(route('cuti.show', $cuti))
            ->assertOk()
            ->assertSee('Pengajuan milik sendiri.');
    }

    public function test_saldo_pribadi_tetap_terbuka_tanpa_permission_saldo_lintas_pegawai(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $permission = Permission::query()->where('name', 'cuti.balance.read')->firstOrFail();
        Role::query()->where('name', 'pegawai')->firstOrFail()->permissions()->detach($permission->id);

        $this->actingAs($user)->get(route('cuti.saldo'))->assertOk();
        $this->assertFalse($user->fresh()->hasPermission('cuti.balance.read'));
    }

    public function test_non_approver_tetap_ditolak_meski_mempunyai_grant_legacy(): void
    {
        $assignedApprover = Employee::factory()->create();
        $unassignedApprover = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $unassignedApprover->id]);
        $permission = Permission::query()->where('name', 'cuti.approve')->firstOrFail();
        Role::query()->where('name', 'pegawai')->firstOrFail()->permissions()->syncWithoutDetaching([$permission->id]);
        $pemohon = Employee::factory()->create();
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Approver Tidak Sesuai',
            'code' => 'cuti_non_approver_rbac',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $cuti = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-06',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Tidak boleh diputus approver lain.',
            'status' => 'menunggu_approval',
        ]);
        $step = LeaveRequestStep::create([
            'leave_request_id' => $cuti->id,
            'step_order' => 1,
            'step_type' => 'verifikator',
            'role_label' => 'Verifikator',
            'approver_employee_id' => $assignedApprover->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        $this->actingAs($user)->post(route('cuti.approve', $cuti), [
            'active_step_id' => $step->id,
            'revision_version' => $cuti->revision_version,
        ])->assertForbidden();
        $this->assertSame('menunggu_approval', $cuti->fresh()->status);
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

    public function test_kepala_bagian_mendapat_default_pengajuan_monitoring_konfigurasi_dan_saldo(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->kepalaBagian()->create(['employee_id' => $employee->id]);

        foreach (['cuti.create', 'cuti.read_own', 'cuti.read_all', 'cuti.configure', 'cuti.balance.read'] as $permission) {
            $this->assertTrue($user->hasPermission($permission), "Kepala Bagian harus memiliki {$permission}");
        }
    }

    public function test_pimpinan_aktif_mendapat_capability_self_service_dan_permission_administratif_default(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pimpinan()->create(['employee_id' => $employee->id]);

        $this->assertTrue($user->hasPermission('cuti.create'));
        foreach (['cuti.read_own', 'cuti.read_all', 'cuti.configure', 'cuti.balance.read'] as $permission) {
            $this->assertTrue($user->hasPermission($permission), "Pimpinan harus memiliki {$permission}");
        }
    }

    public function test_admin_kepegawaian_memiliki_default_bukti_dan_administrasi_cuti(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->adminKepegawaian()->create(['employee_id' => $employee->id]);

        foreach (['cuti.create', 'cuti.read_own', 'cuti.read_all', 'cuti.configure', 'cuti.balance.read', 'cuti.balance.reconcile', 'cuti.manual.manage'] as $permission) {
            $this->assertTrue($user->hasPermission($permission), "Admin Kepegawaian harus memiliki {$permission}");
        }
    }

    public function test_konfigurasi_approval_tersedia_untuk_semua_role_kecuali_pegawai(): void
    {
        foreach (['super_admin', 'admin_kepegawaian', 'pimpinan', 'kepala_bagian'] as $role) {
            $user = User::factory()->state(['role' => $role])->create();
            $this->assertTrue(
                $user->hasPermission('cuti.configure'),
                "role {$role} harus memiliki cuti.configure",
            );
        }

        $this->assertFalse(User::factory()->pegawai()->create()->hasPermission('cuti.configure'));
    }
}
