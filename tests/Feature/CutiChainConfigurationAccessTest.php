<?php

namespace Tests\Feature;

use App\Actions\Cuti\ApplyGlobalPybmcAction;
use App\Actions\Cuti\SaveEmployeeApprovalChainAction;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\Permission;
use App\Models\RefStatusPegawai;
use App\Models\Role;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\Cuti\ApprovalChainConfigurationLockService;
use App\Services\Employees\EmployeeDashboardScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * Membuktikan capability konfigurasi cuti dapat didelegasikan tanpa memperluas
 * scope identitas asli atau membuka keberadaan pegawai di luar scope.
 */
class CutiChainConfigurationAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        $this->seedRbac();
    }

    public function test_semua_role_dapat_membuka_halaman_saat_cuti_configure_diberikan_dan_langsung_ditolak_setelah_dicabut(): void
    {
        foreach (['pegawai', 'kepala_bagian', 'pimpinan', 'admin_kepegawaian', 'super_admin'] as $roleName) {
            $identity = Employee::factory()->create();
            $actor = User::factory()->create([
                'role' => $roleName,
                'employee_id' => $identity->id,
            ]);

            $this->grant($roleName, 'cuti.configure');

            $response = $this->actingAs($actor)->get(route('cuti.config'));
            $response->assertOk();
            $matched = preg_match('/<nav id="sidebar-nav"[^>]*>(.*?)<\/nav>/s', $response->getContent(), $navigation);
            $this->assertSame(1, $matched);
            $this->assertStringContainsString('href="'.route('cuti.config').'"', $navigation[1]);

            $this->revoke($roleName, 'cuti.configure');
            $this->get(route('cuti.config'))->assertForbidden();
        }
    }

    public function test_scope_halaman_mengikuti_identitas_asli_saat_role_efektif_berubah(): void
    {
        $identity = Employee::factory()->create(['nama_lengkap' => 'Identitas Asli Super Admin']);
        $foreign = Employee::factory()->create(['nama_lengkap' => 'Pegawai Asing']);
        $actor = User::factory()->superAdmin()->create([
            'employee_id' => $identity->id,
            'temporary_role' => 'pegawai',
            'temporary_role_started_at' => now(),
        ]);
        $this->grant('pegawai', 'cuti.configure');

        $scopeIds = app(EmployeeDashboardScopeService::class)
            ->forIdentity($actor)
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing([$identity->id, $foreign->id], $scopeIds);
        $this->actingAs($actor)
            ->get(route('cuti.config', ['employee_id' => $foreign->id]))
            ->assertOk();
    }

    public function test_kepala_bagian_hanya_melihat_bawahan_dengan_penugasan_efektif(): void
    {
        $identity = Employee::factory()->create();
        $activeReport = Employee::factory()->create(['nama_lengkap' => 'Bawahan Efektif']);
        $futureReport = Employee::factory()->create(['nama_lengkap' => 'Bawahan Mendatang']);
        $actor = User::factory()->kepalaBagian()->create(['employee_id' => $identity->id]);
        $this->grant('kepala_bagian', 'cuti.configure');

        SupervisorAssignment::query()->create([
            'employee_id' => $activeReport->id,
            'kepala_bagian_id' => $identity->id,
            'tanggal_mulai' => today()->subDay(),
        ]);
        SupervisorAssignment::query()->create([
            'employee_id' => $futureReport->id,
            'kepala_bagian_id' => $identity->id,
            'tanggal_mulai' => today()->addDay(),
        ]);

        $response = $this->actingAs($actor)->get(route('cuti.config', ['search' => 'Bawahan']));

        $response->assertOk()
            ->assertSee('Bawahan Efektif')
            ->assertDontSee('Bawahan Mendatang');
    }

    public function test_halaman_menolak_target_asing_dan_membatasi_statistik_serta_audit_ke_scope(): void
    {
        $identity = Employee::factory()->create(['nama_lengkap' => 'Pegawai Dalam Scope']);
        $foreign = Employee::factory()->create(['nama_lengkap' => 'Pegawai Di Luar Scope']);
        $actor = User::factory()->pegawai()->create(['employee_id' => $identity->id]);
        $this->grant('pegawai', 'cuti.configure');
        $this->grant('pegawai', 'audit_logs.read');

        $ownChain = LeaveApprovalChain::query()->create([
            'employee_id' => $identity->id,
            'name' => 'Chain dalam scope',
            'is_active' => true,
            'effective_from' => today(),
            'created_by' => $actor->id,
        ]);
        $foreignChain = LeaveApprovalChain::query()->create([
            'employee_id' => $foreign->id,
            'name' => 'Chain luar scope',
            'is_active' => true,
            'effective_from' => today(),
            'created_by' => $actor->id,
        ]);
        foreach ([[$ownChain, 'Alasan chain sendiri'], [$foreignChain, 'Alasan rahasia asing']] as [$chain, $reason]) {
            AuditLog::query()->create([
                'user_id' => $actor->id,
                'user_name' => $actor->name,
                'event' => 'CREATE',
                'auditable_type' => 'LeaveApprovalChain',
                'auditable_id' => $chain->id,
                'old_values' => null,
                'new_values' => ['reason' => $reason],
            ]);
        }

        $response = $this->actingAs($actor)->get(route('cuti.config'));

        $response->assertOk()
            ->assertSee('Alasan chain sendiri')
            ->assertDontSee('Alasan rahasia asing');
        $this->assertSame(1, $response->viewData('chainStats')['active']);

        $this->get(route('cuti.config', ['employee_id' => $foreign->id]))->assertNotFound();
    }

    public function test_tanpa_izin_audit_halaman_tidak_memuat_payload_log(): void
    {
        $identity = Employee::factory()->create();
        $actor = User::factory()->pegawai()->create(['employee_id' => $identity->id]);
        $this->grant('pegawai', 'cuti.configure');
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'user_name' => $actor->name,
            'event' => 'CREATE',
            'auditable_type' => 'LeaveApprovalChain',
            'auditable_id' => (string) fake()->uuid(),
            'old_values' => null,
            'new_values' => ['reason' => 'Payload audit yang tidak boleh tampil'],
        ]);

        $this->actingAs($actor)
            ->get(route('cuti.config'))
            ->assertOk()
            ->assertDontSee('Payload audit yang tidak boleh tampil')
            ->assertDontSee('id="global-pybmc-heading"', false);
    }

    public function test_post_chain_target_asing_memberi_404_tanpa_memulai_validasi_payload(): void
    {
        $identity = Employee::factory()->create();
        $foreign = Employee::factory()->create();
        $actor = User::factory()->pegawai()->create(['employee_id' => $identity->id]);
        $this->grant('pegawai', 'cuti.configure');

        $this->actingAs($actor)
            ->post(route('cuti.config.employee-chain.store', $foreign), [])
            ->assertNotFound();

        $this->assertDatabaseCount('leave_approval_chains', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_post_global_ditolak_untuk_scope_terbatas_sebelum_validasi_payload(): void
    {
        $identity = Employee::factory()->create();
        $actor = User::factory()->pegawai()->create(['employee_id' => $identity->id]);
        $this->grant('pegawai', 'cuti.configure');

        $this->actingAs($actor)
            ->post(route('cuti.config.pybmc-global'), [])
            ->assertForbidden();

        $this->assertDatabaseCount('leave_pybmc_global_config', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('writerForms')]
    public function test_form_menjelaskan_penolakan_setelah_izin_dicabut_sebelum_writer_menyimpan(bool $global): void
    {
        $actor = User::factory()->superAdmin()->create();
        $target = Employee::factory()->create();
        $supervisor = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        SupervisorAssignment::query()->create([
            'employee_id' => $target->id,
            'kepala_bagian_id' => $supervisor->id,
            'tanggal_mulai' => today()->subDay(),
        ]);

        $lock = app(ApprovalChainConfigurationLockService::class);
        $expectation = $this->mock(ApprovalChainConfigurationLockService::class)->shouldReceive('acquire');
        if (! $expectation instanceof Expectation && ! $expectation instanceof CompositeExpectation) {
            $this->fail('Mockery tidak mengembalikan ekspektasi metode.');
        }
        $expectation->once()->andReturnUsing(function () use ($lock): void {
            $lock->acquire();
            // Sisipkan pencabutan di batas lock; otorisasi dan respons HTTP tetap dijalankan nyata.
            $this->revoke('super_admin', 'cuti.configure');
        });

        $url = $global ? route('cuti.config.pybmc-global') : route('cuti.config.employee-chain.store', $target);
        $payload = $global
            ? ['approver_employee_id' => $pybmc->id, 'pybmc_reason' => 'Penggantian PYBMC untuk pengujian.']
            : ['steps' => [
                ['step_type' => 'kepala_bagian', 'role_label' => 'Atasan Langsung', 'approver_employee_id' => $supervisor->id],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id],
            ]];

        $this->actingAs($actor)->post($url, $payload)
            ->assertForbidden()
            ->assertSee('Anda tidak lagi memiliki izin untuk tindakan ini. Perubahan tidak disimpan. Hubungi pengelola akses.');

        $this->assertDatabaseCount('leave_approval_chains', 0);
        $this->assertDatabaseCount('leave_pybmc_global_config', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function writerForms(): array
    {
        return ['individual' => [false], 'PYBMC global' => [true]];
    }

    public function test_uuid_approver_malformed_ditolak_422_tanpa_query_uuid_postgresql(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $target = Employee::factory()->create();

        $this->actingAs($actor)
            ->post(route('cuti.config.pybmc-global'), [
                'approver_employee_id' => 'bukan-uuid',
                'pybmc_reason' => 'Alasan konfigurasi global.',
            ])
            ->assertSessionHasErrors('approver_employee_id');

        $this->post(route('cuti.config.employee-chain.store', $target), [
            'steps' => [[
                'step_type' => 'verifier',
                'role_label' => 'Verifikator',
                'approver_employee_id' => 'bukan-uuid',
            ]],
        ])->assertSessionHasErrors('steps.0.approver_employee_id');
    }

    public function test_old_input_hanya_memulihkan_label_approver_aktif(): void
    {
        $identity = Employee::factory()->create();
        $activeApprover = Employee::factory()->create(['nama_lengkap' => 'Approver Lama Aktif']);
        $inactiveStatus = RefStatusPegawai::query()->create([
            'kode' => 'NONAKTIF-OLD-INPUT',
            'nama' => 'Nonaktif Old Input',
            'kelompok' => 'Nonaktif',
            'is_default' => false,
        ]);
        $inactiveApprover = Employee::factory()->create([
            'nama_lengkap' => 'Approver Lama Nonaktif Rahasia',
            'status_pegawai_id' => $inactiveStatus->id,
            'status_aktif' => 'Non-Aktif',
        ]);
        $actor = User::factory()->pegawai()->create(['employee_id' => $identity->id]);
        $this->grant('pegawai', 'cuti.configure');
        $this->assertTrue($activeApprover->refresh()->isActive());
        $supervisor = Employee::factory()->create();
        SupervisorAssignment::query()->create([
            'employee_id' => $identity->id,
            'kepala_bagian_id' => $supervisor->id,
            'tanggal_mulai' => today()->subDay(),
        ]);

        $returnUrl = route('cuti.config', ['tab' => 'pegawai', 'employee_id' => $identity->id]);
        $this->actingAs($actor)
            ->from($returnUrl)
            ->post(route('cuti.config.employee-chain.store', $identity), ['steps' => [
                ['step_type' => 'verifier', 'role_label' => 'Aktif', 'approver_employee_id' => $activeApprover->id],
                ['step_type' => 'verifier', 'role_label' => 'Nonaktif', 'approver_employee_id' => $inactiveApprover->id],
            ]])
            ->assertRedirect($returnUrl)
            ->assertSessionHasErrors('steps.1.approver_employee_id');

        $this->get($returnUrl)
            ->assertOk()
            ->assertSee('Approver Lama Aktif')
            ->assertDontSee('Approver Lama Nonaktif Rahasia');
    }

    public function test_label_chain_tepercaya_tetap_terbaca_tetapi_hanya_approver_aktif_yang_dapat_dipilih(): void
    {
        $identity = Employee::factory()->create();
        $supervisor = Employee::factory()->create();
        $activeApprover = Employee::factory()->create(['nama_lengkap' => 'Trusted Aktif']);
        $inactiveStatus = RefStatusPegawai::query()->create([
            'kode' => 'NONAKTIF-TRUSTED',
            'nama' => 'Nonaktif Trusted',
            'kelompok' => 'Nonaktif',
            'is_default' => false,
        ]);
        $inactiveApprover = Employee::factory()->create([
            'nama_lengkap' => 'Trusted Nonaktif',
            'status_pegawai_id' => $inactiveStatus->id,
            'status_aktif' => 'Non-Aktif',
        ]);
        $actor = User::factory()->pegawai()->create(['employee_id' => $identity->id]);
        $this->grant('pegawai', 'cuti.configure');
        SupervisorAssignment::query()->create([
            'employee_id' => $identity->id,
            'kepala_bagian_id' => $supervisor->id,
            'tanggal_mulai' => today()->subDay(),
        ]);
        $chain = LeaveApprovalChain::query()->create([
            'employee_id' => $identity->id,
            'name' => 'Chain trusted label',
            'is_active' => true,
            'effective_from' => today(),
            'created_by' => $actor->id,
        ]);
        foreach ([[$activeApprover, 1], [$inactiveApprover, 2]] as [$approver, $order]) {
            $chain->steps()->create([
                'step_order' => $order,
                'step_type' => 'verifier',
                'role_label' => 'Verifikator '.$order,
                'approver_employee_id' => $approver->id,
                'is_final' => false,
            ]);
        }

        $response = $this->actingAs($actor)->get(route('cuti.config', ['employee_id' => $identity->id]));

        $response->assertOk()
            ->assertSee('Trusted Aktif')
            ->assertSee('Trusted Nonaktif')
            ->assertDontSee('value="'.$activeApprover->id.'" disabled', false)
            ->assertSee('value="'.$inactiveApprover->id.'" disabled', false);
    }

    public function test_action_simpan_langsung_menolak_sebelum_validasi_domain_dan_tidak_menulis_state(): void
    {
        $identity = Employee::factory()->create();
        $target = Employee::factory()->create();
        $actor = User::factory()->pegawai()->create(['employee_id' => $identity->id]);

        try {
            app(SaveEmployeeApprovalChainAction::class)->execute($target, [], $actor, null);
            $this->fail('Action wajib menolak pemanggilan langsung tanpa permission.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('leave_approval_chains', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_action_simpan_langsung_dengan_permission_tetap_menyamarkan_target_asing(): void
    {
        $identity = Employee::factory()->create();
        $foreign = Employee::factory()->create();
        $actor = User::factory()->pegawai()->create(['employee_id' => $identity->id]);
        $this->grant('pegawai', 'cuti.configure');

        try {
            app(SaveEmployeeApprovalChainAction::class)->execute($foreign, [], $actor, null);
            $this->fail('Action wajib menolak target di luar scope sebelum validasi domain.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('leave_approval_chains', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_scope_identitas_fail_closed_untuk_null_role_invalid_dan_binding_kosong(): void
    {
        $scope = app(EmployeeDashboardScopeService::class);
        Employee::factory()->create();

        $this->assertFalse($scope->forIdentity(null)->exists());
        $this->assertFalse($scope->forIdentity(User::factory()->create(['role' => 'role_tidak_dikenal']))->exists());
        $this->assertFalse($scope->forIdentity(User::factory()->pegawai()->create(['employee_id' => null]))->exists());
        $this->assertFalse($scope->forIdentity(User::factory()->kepalaBagian()->create(['employee_id' => null]))->exists());

        $globalWithoutBinding = User::factory()->pimpinan()->create(['employee_id' => null]);
        $this->assertTrue($scope->hasGlobalIdentityScope($globalWithoutBinding));
        $this->assertTrue($scope->forIdentity($globalWithoutBinding)->exists());
    }

    public function test_action_global_ditolak_untuk_scope_terbatas_walaupun_permission_efektif(): void
    {
        $identity = Employee::factory()->create();
        $approver = Employee::factory()->create();
        $actor = User::factory()->pegawai()->create(['employee_id' => $identity->id]);
        $this->grant('pegawai', 'cuti.configure');

        try {
            app(ApplyGlobalPybmcAction::class)->execute($approver, $actor, 'Alasan konfigurasi global.');
            $this->fail('Action global wajib menolak scope identitas terbatas.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('leave_pybmc_global_config', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function grant(string $roleName, string $permissionName): void
    {
        $role = Role::query()->where('name', $roleName)->sole();
        $permission = Permission::query()->where('name', $permissionName)->sole();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }

    private function revoke(string $roleName, string $permissionName): void
    {
        $role = Role::query()->where('name', $roleName)->sole();
        $permission = Permission::query()->where('name', $permissionName)->sole();
        $role->permissions()->detach($permission->id);
    }
}
