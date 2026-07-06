<?php

namespace Tests\Feature;

use App\Actions\Cuti\ApplyGlobalPybmcAction;
use App\Actions\Cuti\BackfillEmployeeApprovalChainsAction;
use App\Actions\Cuti\SaveEmployeeApprovalChainAction;
use App\Models\ApprovalConfig;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeavePybmcGlobalConfig;
use App\Models\User;
use App\Services\Cuti\ApprovalChainResolver;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Menguji konfigurasi rantai approval cuti dinamis per pegawai.
 * Fase ini belum mengganti runtime approval; fokusnya memastikan chain baru siap dipakai snapshot.
 */
class EmployeeApprovalChainConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_menyimpan_chain_per_pegawai_dengan_audit(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $kepalaBagian = Employee::factory()->create();
        $pybmc = Employee::factory()->create();

        $chain = $this->actingAs($actor)->app->make(SaveEmployeeApprovalChainAction::class)->execute(
            $pegawai,
            [
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => false],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id, 'is_final' => true],
            ],
            $actor,
            'Penetapan chain awal pegawai.',
        );

        $this->assertDatabaseHas('leave_approval_chains', [
            'id' => $chain->id,
            'employee_id' => $pegawai->id,
            'is_active' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->assertDatabaseHas('leave_approval_chain_steps', [
            'leave_approval_chain_id' => $chain->id,
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'approver_employee_id' => $kepalaBagian->id,
            'is_final' => false,
        ]);
        $this->assertDatabaseHas('leave_approval_chain_steps', [
            'leave_approval_chain_id' => $chain->id,
            'step_order' => 2,
            'step_type' => 'pybmc',
            'approver_employee_id' => $pybmc->id,
            'is_final' => true,
        ]);

        $audit = AuditLog::where('auditable_type', 'LeaveApprovalChain')->first();
        $this->assertNotNull($audit);
        $this->assertSame('CREATE', $audit->event);
        $this->assertSame('Penetapan chain awal pegawai.', $audit->new_values['reason']);
    }

    public function test_update_chain_menonaktifkan_chain_lama(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $approverAwal = Employee::factory()->create();
        $approverBaru = Employee::factory()->create();

        $action = $this->actingAs($actor)->app->make(SaveEmployeeApprovalChainAction::class);
        $lama = $action->execute($pegawai, [
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $approverAwal->id, 'is_final' => true],
        ], $actor, 'Chain awal.');
        $baru = $action->execute($pegawai, [
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $approverBaru->id, 'is_final' => true],
        ], $actor, 'Ganti PYBMC.');

        $this->assertFalse($lama->fresh()->is_active);
        $this->assertTrue($baru->fresh()->is_active);
        $this->assertDatabaseCount('leave_approval_chains', 2);
    }

    public function test_update_chain_mencatat_old_values_sebelum_chain_lama_dinonaktifkan(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $approverAwal = Employee::factory()->create();
        $approverBaru = Employee::factory()->create();

        $action = $this->actingAs($actor)->app->make(SaveEmployeeApprovalChainAction::class);
        $action->execute($pegawai, [
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $approverAwal->id, 'is_final' => true],
        ], $actor, 'Chain awal.');
        $action->execute($pegawai, [
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $approverBaru->id, 'is_final' => true],
        ], $actor, 'Ganti PYBMC.');

        $auditUpdate = AuditLog::where('auditable_type', 'LeaveApprovalChain')
            ->whereJsonContains('new_values->reason', 'Ganti PYBMC.')
            ->firstOrFail();

        $this->assertTrue($auditUpdate->old_values['is_active']);
    }

    public function test_global_pybmc_bisa_disimpan_dengan_audit(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pybmc = Employee::factory()->create();

        $config = $this->actingAs($actor)->app->make(ApplyGlobalPybmcAction::class)->execute(
            $pybmc,
            $actor,
            'Penetapan PYBMC global awal.',
        );

        $this->assertDatabaseHas('leave_pybmc_global_config', [
            'id' => $config->id,
            'approver_employee_id' => $pybmc->id,
            'created_by' => $actor->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => 'LeavePybmcGlobalConfig',
            'auditable_id' => $config->id,
            'event' => 'CREATE',
        ]);
    }

    public function test_chain_tanpa_final_memakai_pybmc_global(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $kepalaBagian = Employee::factory()->create();
        $pybmc = Employee::factory()->create();

        $this->actingAs($actor)->app->make(ApplyGlobalPybmcAction::class)->execute($pybmc, $actor, 'PYBMC global.');

        $chain = $this->app->make(SaveEmployeeApprovalChainAction::class)->execute($pegawai, [
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => false],
        ], $actor, 'Chain memakai PYBMC global.');

        $this->assertSame([$kepalaBagian->id, $pybmc->id], $chain->steps()->orderBy('step_order')->pluck('approver_employee_id')->all());
        $this->assertTrue($chain->steps()->orderByDesc('step_order')->firstOrFail()->is_final);
    }

    public function test_super_admin_bisa_menyimpan_chain_pegawai_melalui_route(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $kepalaBagian = Employee::factory()->create();
        $pybmc = Employee::factory()->create();

        $this->actingAs($actor)->app->make(ApplyGlobalPybmcAction::class)->execute($pybmc, $actor, 'PYBMC global.');

        $response = $this->actingAs($actor)->post(route('cuti.config.employee-chain.store', $pegawai), [
            'steps' => [[
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $kepalaBagian->id,
            ]],
            'reason' => 'Set chain pegawai melalui route.',
        ]);

        $response->assertRedirect(route('cuti.config'));
        $this->assertDatabaseHas('leave_approval_chains', ['employee_id' => $pegawai->id, 'is_active' => true]);
    }

    public function test_resolver_skip_duplicate_dan_mempertahankan_final_pybmc(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $approverSama = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        $this->actingAs($actor)->app->make(SaveEmployeeApprovalChainAction::class)->execute($pegawai, [
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $approverSama->id, 'is_final' => false],
            ['step_type' => 'verifier', 'role_label' => 'Verifikator', 'approver_employee_id' => $verifikator->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $approverSama->id, 'is_final' => true],
        ], $actor, 'Chain dengan duplikasi approver.');

        $steps = $this->app->make(ApprovalChainResolver::class)->resolveEffectiveSteps($pegawai);

        $this->assertCount(2, $steps);
        $this->assertSame([$verifikator->id, $approverSama->id], $steps->pluck('approver_employee_id')->all());
        $this->assertTrue($steps->last()->is_final);
        $this->assertSame('pybmc', $steps->last()->step_type);
    }

    public function test_resolver_fail_closed_jika_chain_tidak_punya_final(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $kepalaBagian = Employee::factory()->create();

        LeaveApprovalChain::create([
            'employee_id' => $pegawai->id,
            'name' => 'Chain rusak',
            'effective_from' => today(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'change_reason' => 'Uji chain tanpa final.',
        ])->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $kepalaBagian->id,
            'is_final' => false,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rantai approval cuti wajib memiliki tepat satu approver final efektif.');

        $this->app->make(ApprovalChainResolver::class)->resolveEffectiveSteps($pegawai);
    }

    public function test_backfill_membuat_chain_dari_kepala_bagian_dan_approval_config_lama(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $verifikator = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $pegawai = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        $tanpaKepalaBagian = Employee::factory()->create(['kepala_bagian_id' => null]);
        $verifikatorUser = User::factory()->adminKepegawaian()->create(['employee_id' => $verifikator->id]);
        $pybmcUser = User::factory()->pimpinan()->create(['employee_id' => $pybmc->id]);

        ApprovalConfig::setVal('stage2_approver_id', $verifikatorUser->id);
        ApprovalConfig::setVal('stage3_approver_id', $pybmcUser->id);

        $result = $this->actingAs($actor)->app->make(BackfillEmployeeApprovalChainsAction::class)->execute($actor, 'Backfill awal Phase 3.');

        $this->assertContains($pegawai->id, $result['created_employee_ids']);
        $this->assertContains($tanpaKepalaBagian->id, $result['missing_kepala_bagian_employee_ids']);

        $chain = LeaveApprovalChain::where('employee_id', $pegawai->id)->firstOrFail();
        $this->assertSame([$kepalaBagian->id, $verifikator->id, $pybmc->id], $chain->steps()->orderBy('step_order')->pluck('approver_employee_id')->all());
    }

    public function test_backfill_memisahkan_skip_karena_final_approver_tidak_tersedia(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);

        $result = $this->actingAs($actor)->app->make(BackfillEmployeeApprovalChainsAction::class)->execute($actor, 'Backfill tanpa PYBMC.');

        $this->assertContains($pegawai->id, $result['missing_final_approver_employee_ids']);
        $this->assertNotContains($pegawai->id, $result['skipped_employee_ids']);
    }

    public function test_super_admin_bisa_menyimpan_pybmc_global_melalui_route(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pybmc = Employee::factory()->create();

        $response = $this->actingAs($actor)->post(route('cuti.config.pybmc-global'), [
            'approver_employee_id' => $pybmc->id,
            'pybmc_reason' => 'Penetapan PYBMC global melalui route.',
        ]);

        $response->assertRedirect(route('cuti.config'));
        $this->assertTrue(LeavePybmcGlobalConfig::where('approver_employee_id', $pybmc->id)->exists());
    }

    public function test_super_admin_bisa_memicu_backfill_chain_dari_halaman_konfigurasi(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $verifikator = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $pegawai = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        $verifikatorUser = User::factory()->adminKepegawaian()->create(['employee_id' => $verifikator->id]);
        $pybmcUser = User::factory()->pimpinan()->create(['employee_id' => $pybmc->id]);

        ApprovalConfig::setVal('stage2_approver_id', $verifikatorUser->id);
        ApprovalConfig::setVal('stage3_approver_id', $pybmcUser->id);

        $response = $this->actingAs($actor)->post(route('cuti.config.backfill'), [
            'backfill_reason' => 'Backfill awal dari konfigurasi lama.',
        ]);

        $response->assertRedirect(route('cuti.config'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('leave_approval_chains', ['employee_id' => $pegawai->id, 'is_active' => true]);
    }

    public function test_halaman_konfigurasi_menampilkan_panel_backfill_chain(): void
    {
        $actor = User::factory()->superAdmin()->create();

        $response = $this->actingAs($actor)->get(route('cuti.config'));

        $response->assertOk();
        $response->assertSee('Backfill Chain Dinamis');
        $response->assertSee('Jalankan Backfill Chain');
    }

    public function test_backfill_chain_wajib_permission_configure_chain(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($admin)->post(route('cuti.config.backfill'), [
            'backfill_reason' => 'Percobaan tanpa permission.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('leave_approval_chains', 0);
    }
}
