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
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefStatusPegawai;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\Cuti\ApprovalChainInvariantService;
use App\Services\Cuti\ApprovalChainResolver;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_super_admin_menyimpan_chain_per_pegawai_tanpa_alasan_dengan_label_bisnis_dan_audit_lengkap(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $pybmc = Employee::factory()->create();

        $chain = $this->actingAs($actor)->app->make(SaveEmployeeApprovalChainAction::class)->execute(
            $pegawai,
            [
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => false],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id, 'is_final' => true],
            ],
            $actor,
            null,
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
            'role_label' => 'Atasan Langsung',
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
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame($chain->id, $audit->auditable_id);
        $this->assertNull($audit->old_values);
        $this->assertSame($pegawai->id, $audit->new_values['employee_id']);
        $this->assertSame('Atasan Langsung', $audit->new_values['steps'][0]['role_label']);
        $this->assertArrayHasKey('reason', $audit->new_values);
        $this->assertNull($audit->new_values['reason']);
    }

    public function test_save_action_mencatat_aktor_eksplisit_tanpa_sesi_autentikasi(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $pybmc = Employee::factory()->create();

        $chain = $this->app->make(SaveEmployeeApprovalChainAction::class)->execute(
            $pegawai,
            [
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => false],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id, 'is_final' => true],
            ],
            $actor,
            'Menguji aktor eksplisit tanpa sesi autentikasi.',
        );

        $audit = AuditLog::query()
            ->where('auditable_type', 'LeaveApprovalChain')
            ->where('auditable_id', $chain->id)
            ->sole();

        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame($actor->name, $audit->user_name);
    }

    public function test_update_chain_menonaktifkan_chain_lama(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $approverAwal = Employee::factory()->create();
        $approverBaru = Employee::factory()->create();

        $action = $this->actingAs($actor)->app->make(SaveEmployeeApprovalChainAction::class);
        $lama = $action->execute($pegawai, [
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $approverAwal->id, 'is_final' => true],
        ], $actor, 'Chain awal.');
        $baru = $action->execute($pegawai, [
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $approverBaru->id, 'is_final' => true],
        ], $actor, 'Ganti PYBMC.');

        $this->assertFalse($lama->fresh()->is_active);
        $this->assertTrue($baru->fresh()->is_active);
        $this->assertDatabaseCount('leave_approval_chains', 2);
    }

    public function test_update_chain_mencatat_old_values_sebelum_chain_lama_dinonaktifkan(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $approverAwal = Employee::factory()->create();
        $approverBaru = Employee::factory()->create();

        $action = $this->actingAs($actor)->app->make(SaveEmployeeApprovalChainAction::class);
        $action->execute($pegawai, [
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $approverAwal->id, 'is_final' => true],
        ], $actor, 'Chain awal.');
        $action->execute($pegawai, [
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $approverBaru->id, 'is_final' => true],
        ], $actor, 'Ganti PYBMC.');

        $auditUpdate = AuditLog::where('auditable_type', 'LeaveApprovalChain')
            ->whereJsonContains('new_values->reason', 'Ganti PYBMC.')
            ->firstOrFail();

        $this->assertTrue($auditUpdate->old_values['is_active']);
    }

    public function test_save_action_menolak_chain_tanpa_step_kepala_bagian_sebelum_chain_lama_dimutasi(): void
    {
        $fixture = $this->buatFixtureChainAktifValid();
        $verifikator = Employee::factory()->create();

        $this->assertSaveChainInvalidTidakMengubahChainLama($fixture, [
            ['step_type' => 'verifier', 'role_label' => 'Verifikator', 'approver_employee_id' => $verifikator->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id, 'is_final' => true],
        ]);
    }

    public function test_save_action_menolak_step_final_yang_bukan_pybmc_sebelum_chain_lama_dimutasi(): void
    {
        $fixture = $this->buatFixtureChainAktifValid();
        $verifikator = Employee::factory()->create();

        $this->assertSaveChainInvalidTidakMengubahChainLama($fixture, [
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $fixture['kepala_bagian']->id, 'is_final' => false],
            ['step_type' => 'verifier', 'role_label' => 'Verifikator', 'approver_employee_id' => $verifikator->id, 'is_final' => true],
        ]);
    }

    public function test_save_action_menolak_verifikator_setelah_kepala_bagian_tanpa_mutasi_parsial(): void
    {
        $fixture = $this->buatFixtureChainAktifValid();
        $verifikator = Employee::factory()->create();

        $this->assertSaveChainInvalidTidakMengubahChainLama(
            $fixture,
            [
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $fixture['kepala_bagian']->id, 'is_final' => false],
                ['step_type' => 'verifier', 'role_label' => 'Verifikator', 'approver_employee_id' => $verifikator->id, 'is_final' => false],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id, 'is_final' => true],
            ],
            'Semua Verifikator harus ditempatkan sebelum Atasan Langsung.',
        );
    }

    public function test_save_action_menolak_pegawai_yang_diulang_pada_dua_tahap_verifikator_tanpa_mutasi_parsial(): void
    {
        $fixture = $this->buatFixtureChainAktifValid();
        $verifikator = Employee::factory()->create();

        $this->assertSaveChainInvalidTidakMengubahChainLama(
            $fixture,
            [
                ['step_type' => 'verifier', 'role_label' => 'Verifikator Pertama', 'approver_employee_id' => $verifikator->id, 'is_final' => false],
                ['step_type' => 'verifier', 'role_label' => 'Verifikator Kedua', 'approver_employee_id' => strtoupper($verifikator->id), 'is_final' => false],
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $fixture['kepala_bagian']->id, 'is_final' => false],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id, 'is_final' => true],
            ],
            'Pegawai yang sama tidak boleh mengisi lebih dari satu tahap dengan peran approval yang sama.',
        );
    }

    public function test_save_action_menolak_dua_step_kepala_bagian_tanpa_mutasi_parsial(): void
    {
        $fixture = $this->buatFixtureChainAktifValid();

        $this->assertSaveChainInvalidTidakMengubahChainLama(
            $fixture,
            [
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian Utama', 'approver_employee_id' => $fixture['kepala_bagian']->id, 'is_final' => false],
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian Duplikat', 'approver_employee_id' => $fixture['kepala_bagian']->id, 'is_final' => false],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id, 'is_final' => true],
            ],
            'Rantai approval cuti wajib memiliki tepat satu step Atasan Langsung.',
        );
    }

    public function test_save_action_menolak_pybmc_yang_tidak_final_tanpa_mutasi_parsial(): void
    {
        $fixture = $this->buatFixtureChainAktifValid();

        $this->assertSaveChainInvalidTidakMengubahChainLama(
            $fixture,
            [
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $fixture['kepala_bagian']->id, 'is_final' => false],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id, 'is_final' => false],
            ],
            'Rantai approval cuti wajib memiliki tepat satu approver final.',
        );
    }

    public function test_save_action_menolak_kepala_bagian_yang_bukan_penugasan_efektif_tanpa_mutasi_parsial(): void
    {
        $fixture = $this->buatFixtureChainAktifValid();
        $kepalaBagianLain = Employee::factory()->create();

        $this->assertSaveChainInvalidTidakMengubahChainLama(
            $fixture,
            [
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagianLain->id, 'is_final' => false],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id, 'is_final' => true],
            ],
            'Approver tahap Atasan Langsung harus sesuai penugasan Atasan Langsung efektif pegawai.',
        );
    }

    public function test_save_action_dengan_request_menerjemahkan_kepala_bagian_stale_menjadi_error_validasi(): void
    {
        $fixture = $this->buatFixtureChainAktifValid();
        $kepalaBagianLain = Employee::factory()->create();
        $exception = null;

        try {
            $fixture['action']->execute(
                $fixture['employee'],
                [
                    ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagianLain->id, 'is_final' => false],
                    ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id, 'is_final' => true],
                ],
                $fixture['actor'],
                'Kandidat stale dari boundary HTTP harus ditolak.',
                Request::create('/cuti/konfigurasi-approval', 'POST'),
            );
        } catch (\Throwable $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(ValidationException::class, $exception);
        $this->assertSame([
            'steps' => ['Approver tahap Atasan Langsung harus sesuai penugasan Atasan Langsung efektif pegawai.'],
        ], $exception->errors());
        $this->assertTrue($fixture['chain']->fresh()->is_active);
        $this->assertSame(
            $fixture['step_ids'],
            $fixture['chain']->steps()->orderBy('step_order')->pluck('id')->all(),
        );
        $this->assertSame(
            1,
            LeaveApprovalChain::query()->where('employee_id', $fixture['employee']->id)->count(),
        );
        $this->assertSame(
            $fixture['audit_count'],
            AuditLog::query()->where('auditable_type', 'LeaveApprovalChain')->count(),
        );
    }

    public function test_save_action_dengan_request_tetap_mempropagasikan_kegagalan_database(): void
    {
        $fixture = $this->buatFixtureChainAktifValid();
        $queryException = new QueryException(
            'pgsql',
            'select * from employees where id = ?',
            [$fixture['employee']->id],
            new RuntimeException('Koneksi database terputus.'),
        );
        $this->mock(ApprovalChainInvariantService::class, function (MockInterface $mock) use ($queryException): void {
            /** @var Expectation $expectation */
            $expectation = $mock->shouldReceive('validateForEmployee');
            $expectation->once()
                ->andThrow($queryException);
        });
        $exception = null;

        try {
            $this->app->make(SaveEmployeeApprovalChainAction::class)->execute(
                $fixture['employee'],
                [
                    ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $fixture['kepala_bagian']->id, 'is_final' => false],
                    ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id, 'is_final' => true],
                ],
                $fixture['actor'],
                'Kegagalan database tidak boleh menjadi pesan validasi.',
                Request::create('/cuti/konfigurasi-approval', 'POST'),
            );
        } catch (\Throwable $caught) {
            $exception = $caught;
        }

        $this->assertSame($queryException, $exception);
        $this->assertTrue($fixture['chain']->fresh()->is_active);
        $this->assertSame(
            $fixture['audit_count'],
            AuditLog::query()->where('auditable_type', 'LeaveApprovalChain')->count(),
        );
    }

    public function test_save_action_menolak_array_step_non_list_tanpa_mutasi_parsial(): void
    {
        $fixture = $this->buatFixtureChainAktifValid();
        $verifikator = Employee::factory()->create();

        $this->assertSaveChainInvalidTidakMengubahChainLama(
            $fixture,
            [
                2 => ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $fixture['kepala_bagian']->id, 'is_final' => false],
                0 => ['step_type' => 'verifier', 'role_label' => 'Verifikator', 'approver_employee_id' => $verifikator->id, 'is_final' => false],
                1 => ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id, 'is_final' => true],
            ],
            'Langkah rantai approval cuti wajib berupa daftar berurutan.',
        );
    }

    public function test_save_action_menolak_approver_null_sebelum_chain_lama_dimutasi(): void
    {
        $fixture = $this->buatFixtureChainAktifValid();

        $this->assertSaveChainInvalidTidakMengubahChainLama($fixture, [
            ['step_type' => 'verifier', 'role_label' => 'Verifikator', 'approver_employee_id' => null, 'is_final' => false],
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $fixture['kepala_bagian']->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id, 'is_final' => true],
        ]);
    }

    public function test_save_action_menolak_approver_string_kosong_sebelum_chain_lama_dimutasi(): void
    {
        $fixture = $this->buatFixtureChainAktifValid();

        $this->assertSaveChainInvalidTidakMengubahChainLama($fixture, [
            ['step_type' => 'verifier', 'role_label' => 'Verifikator', 'approver_employee_id' => '', 'is_final' => false],
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $fixture['kepala_bagian']->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id, 'is_final' => true],
        ]);
    }

    public function test_save_action_menolak_approver_uuid_malformed_sebelum_chain_lama_dimutasi(): void
    {
        $fixture = $this->buatFixtureChainAktifValid();

        $this->assertSaveChainInvalidTidakMengubahChainLama($fixture, [
            ['step_type' => 'verifier', 'role_label' => 'Verifikator', 'approver_employee_id' => 'bukan-uuid', 'is_final' => false],
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $fixture['kepala_bagian']->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id, 'is_final' => true],
        ]);
    }

    public function test_save_action_menolak_approver_uuid_valid_yang_tidak_ada_sebelum_chain_lama_dimutasi(): void
    {
        $fixture = $this->buatFixtureChainAktifValid();

        $this->assertSaveChainInvalidTidakMengubahChainLama($fixture, [
            ['step_type' => 'verifier', 'role_label' => 'Verifikator', 'approver_employee_id' => '00000000-0000-4000-8000-000000000001', 'is_final' => false],
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $fixture['kepala_bagian']->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id, 'is_final' => true],
        ]);
    }

    public function test_save_action_menolak_approver_nonaktif_sebelum_chain_lama_dimutasi(): void
    {
        $fixture = $this->buatFixtureChainAktifValid();
        $approverNonaktif = Employee::factory()->create(['status_aktif' => 'Non-Aktif']);

        $this->assertSaveChainInvalidTidakMengubahChainLama($fixture, [
            ['step_type' => 'verifier', 'role_label' => 'Verifikator', 'approver_employee_id' => $approverNonaktif->id, 'is_final' => false],
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $fixture['kepala_bagian']->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id, 'is_final' => true],
        ]);
    }

    public function test_save_action_menolak_id_pegawai_yang_tidak_lagi_tersedia_sebelum_chain_lama_dimutasi(): void
    {
        $fixture = $this->buatFixtureChainAktifValid();
        $approverDihapus = Employee::factory()->create();
        $approverDihapus->delete();

        $this->assertSaveChainInvalidTidakMengubahChainLama($fixture, [
            ['step_type' => 'verifier', 'role_label' => 'Verifikator', 'approver_employee_id' => $approverDihapus->id, 'is_final' => false],
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $fixture['kepala_bagian']->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id, 'is_final' => true],
        ]);
    }

    public function test_assignment_effective_today_synchronizes_only_first_kepala_bagian_step_on_active_chain(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagianLama = Employee::factory()->create();
        $kepalaBagianBaru = Employee::factory()->create();
        $verifikator = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $pegawai = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagianLama->id]);
        SupervisorAssignment::create([
            'employee_id' => $pegawai->id,
            'kepala_bagian_id' => $kepalaBagianLama->id,
            'tanggal_mulai' => today()->subMonth(),
            'tanggal_berakhir' => null,
        ]);
        $chain = $this->actingAs($actor)->app->make(SaveEmployeeApprovalChainAction::class)->execute($pegawai, [
            ['step_type' => 'verifier', 'role_label' => 'Verifikator Kepegawaian', 'approver_employee_id' => $verifikator->id, 'is_final' => false],
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagianLama->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id, 'is_final' => true],
        ], $actor, 'Chain sebelum pergantian Kepala Bagian.');

        $response = $this->actingAs($actor)->withSession(['_token' => 'test-token'])->postJson(
            "/api/v1/pegawai/{$pegawai->id}/assign-atasan",
            [
                'kepala_bagian_id' => $kepalaBagianBaru->id,
                'effective_date' => today()->toDateString(),
            ],
            ['X-CSRF-TOKEN' => 'test-token'],
        );

        $response->assertOk();
        $this->assertSame(
            [$verifikator->id, $kepalaBagianBaru->id, $pybmc->id],
            $chain->steps()->orderBy('step_order')->pluck('approver_employee_id')->all(),
        );
        $this->assertSame(
            ['verifier', 'kepala_bagian', 'pybmc'],
            $chain->steps()->orderBy('step_order')->pluck('step_type')->all(),
        );
        $this->assertSame(1, LeaveApprovalChain::where('employee_id', $pegawai->id)->where('is_active', true)->count());
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

    public function test_global_pybmc_mengganti_final_semua_chain_aktif_tanpa_mengubah_snapshot_pengajuan(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pybmcLama = Employee::factory()->create();
        $pybmcBaru = Employee::factory()->create();
        $kepalaBagianSatu = Employee::factory()->create();
        $kepalaBagianDua = Employee::factory()->create();
        $verifikator = Employee::factory()->create();
        $pegawaiSatu = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagianSatu);
        $pegawaiDua = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagianDua);
        $action = $this->actingAs($actor)->app->make(SaveEmployeeApprovalChainAction::class);

        $chainSatu = $action->execute($pegawaiSatu, [
            ['step_type' => 'verifier', 'role_label' => 'Verifikator Kepegawaian', 'approver_employee_id' => $verifikator->id, 'is_final' => false],
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagianSatu->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmcLama->id, 'is_final' => true],
        ], $actor, 'Chain pegawai satu.');
        $chainDua = $action->execute($pegawaiDua, [
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagianDua->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmcLama->id, 'is_final' => true],
        ], $actor, 'Chain pegawai dua.');

        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Sakit',
            'code' => 'cuti_sakit',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $pegawaiSatu->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-07-20',
            'tanggal_selesai' => '2026-07-21',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Pengujian snapshot.',
            'status' => 'menunggu_approval',
        ]);
        $leave->steps()->createMany($chainSatu->steps->map(fn ($step): array => [
            'step_order' => $step->step_order,
            'step_type' => $step->step_type,
            'role_label' => $step->role_label,
            'approver_employee_id' => $step->approver_employee_id,
            'status' => $step->step_order === 1 ? 'active' : 'pending',
            'is_final' => $step->is_final,
        ])->all());
        $snapshotSebelum = $leave->steps()->orderBy('step_order')->get(['step_order', 'step_type', 'role_label', 'approver_employee_id', 'is_final'])->toArray();

        $config = $this->app->make(ApplyGlobalPybmcAction::class)->execute(
            $pybmcBaru,
            $actor,
            'Override PYBMC global seluruh pegawai.',
        );

        $this->assertSame(
            [$verifikator->id, $kepalaBagianSatu->id, $pybmcBaru->id],
            $chainSatu->steps()->orderBy('step_order')->pluck('approver_employee_id')->all(),
        );
        $this->assertSame(
            [$kepalaBagianDua->id, $pybmcBaru->id],
            $chainDua->steps()->orderBy('step_order')->pluck('approver_employee_id')->all(),
        );
        $this->assertSame(1, $chainSatu->steps()->where('is_final', true)->where('step_type', 'pybmc')->count());
        $this->assertSame(1, $chainDua->steps()->where('is_final', true)->where('step_type', 'pybmc')->count());
        $this->assertSame($snapshotSebelum, $leave->steps()->orderBy('step_order')->get(['step_order', 'step_type', 'role_label', 'approver_employee_id', 'is_final'])->toArray());

        $audit = AuditLog::query()
            ->where('auditable_type', 'LeavePybmcGlobalConfig')
            ->where('auditable_id', $config->id)
            ->firstOrFail();
        $this->assertSame(2, $audit->new_values['affected_chain_count']);
    }

    public function test_global_pybmc_memproses_lebih_dari_satu_chunk_tanpa_menggandakan_step(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pybmcLama = Employee::factory()->create();
        $pybmcBaru = Employee::factory()->create();
        $chainIds = [];
        $finalStepIds = [];

        foreach (Employee::factory()->count(101)->create(['kepala_bagian_id' => $kepalaBagian->id]) as $pegawai) {
            $chain = LeaveApprovalChain::create([
                'employee_id' => $pegawai->id,
                'name' => 'Chain coverage chunk global',
                'effective_from' => today(),
            ]);
            $chain->steps()->create([
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $kepalaBagian->id,
                'is_final' => false,
            ]);
            $final = $chain->steps()->create([
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $pybmcLama->id,
                'is_final' => true,
            ]);
            $chainIds[] = $chain->id;
            $finalStepIds[] = $final->id;
        }

        $config = $this->app->make(ApplyGlobalPybmcAction::class)->execute(
            $pybmcBaru,
            $actor,
            'Override melintasi batas chunk.',
        );

        $finalSesudah = DB::table('leave_approval_chain_steps')
            ->whereIn('leave_approval_chain_id', $chainIds)
            ->where('is_final', true)
            ->orderBy('id')
            ->get(['id', 'approver_employee_id']);
        $audit = AuditLog::query()
            ->where('auditable_type', 'LeavePybmcGlobalConfig')
            ->where('auditable_id', $config->id)
            ->sole();

        $this->assertCount(101, $finalSesudah);
        $this->assertSame(
            collect($finalStepIds)->sort()->values()->all(),
            $finalSesudah->pluck('id')->all(),
        );
        $this->assertSame([$pybmcBaru->id], $finalSesudah->pluck('approver_employee_id')->unique()->values()->all());
        $this->assertSame(202, DB::table('leave_approval_chain_steps')->whereIn('leave_approval_chain_id', $chainIds)->count());
        $this->assertSame(101, $audit->new_values['affected_chain_count']);
    }

    public function test_global_pybmc_menolak_chain_legacy_tanpa_final_dan_rollback_seluruh_perubahan(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $verifikator = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $chain = LeaveApprovalChain::create([
            'employee_id' => $pegawai->id,
            'name' => 'Chain legacy tanpa final',
            'effective_from' => today(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'change_reason' => 'Fixture chain legacy.',
        ]);
        $chain->steps()->createMany([
            [
                'step_order' => 2,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $kepalaBagian->id,
                'is_final' => false,
            ],
            [
                'step_order' => 5,
                'step_type' => 'verifier',
                'role_label' => 'Verifikator Kepegawaian',
                'approver_employee_id' => $verifikator->id,
                'is_final' => false,
            ],
        ]);
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Sakit Legacy',
            'code' => 'cuti_sakit_legacy',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $pegawai->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-07-20',
            'tanggal_selesai' => '2026-07-21',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Snapshot chain legacy.',
            'status' => 'menunggu_approval',
        ]);
        $leave->steps()->createMany([
            [
                'step_order' => 2,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $kepalaBagian->id,
                'status' => 'active',
                'is_final' => false,
            ],
            [
                'step_order' => 5,
                'step_type' => 'verifier',
                'role_label' => 'Verifikator Kepegawaian',
                'approver_employee_id' => $verifikator->id,
                'status' => 'pending',
                'is_final' => false,
            ],
        ]);
        $chainSebelum = $chain->fresh()->toArray();
        $stepSebelum = $chain->steps()->orderBy('step_order')->get()->toArray();
        $snapshotSebelum = $leave->steps()->orderBy('step_order')->get()->toArray();
        $jumlahAuditSebelum = AuditLog::count();
        $exception = null;

        try {
            $this->app->make(ApplyGlobalPybmcAction::class)->execute(
                $pybmc,
                $actor,
                'Chain legacy tidak boleh diperbaiki diam-diam.',
            );
        } catch (\Throwable $caught) {
            $exception = $caught;
        }

        $this->assertSame($chainSebelum, $chain->fresh()->toArray());
        $this->assertSame($stepSebelum, $chain->steps()->orderBy('step_order')->get()->toArray());
        $this->assertSame($snapshotSebelum, $leave->steps()->orderBy('step_order')->get()->toArray());
        $this->assertDatabaseCount('leave_pybmc_global_config', 0);
        $this->assertSame($jumlahAuditSebelum, AuditLog::count());
        $this->assertNotNull($exception);
        $this->assertSame(RuntimeException::class, $exception::class);
    }

    public function test_global_pybmc_direct_menolak_approver_nonaktif_tanpa_chain_aktif(): void
    {
        $this->assertApplyGlobalMenolakApprover(
            Employee::factory()->create(['status_aktif' => 'Non-Aktif']),
        );
    }

    public function test_global_pybmc_direct_menolak_id_pegawai_yang_tidak_lagi_tersedia_tanpa_chain_aktif(): void
    {
        $approver = Employee::factory()->create();
        $approver->delete();

        $this->assertApplyGlobalMenolakApprover($approver);
    }

    public function test_global_pybmc_direct_menolak_approver_uuid_valid_yang_tidak_tersimpan(): void
    {
        $approver = new Employee;
        $approver->forceFill([
            'id' => '00000000-0000-4000-8000-000000000701',
            'nama_lengkap' => 'PYBMC tidak tersimpan',
            'status_aktif' => 'Aktif',
        ]);

        $this->assertApplyGlobalMenolakApprover($approver);
    }

    public function test_global_pybmc_direct_menolak_approver_uuid_malformed_sebagai_error_domain(): void
    {
        $approver = new Employee;
        $approver->forceFill([
            'id' => 'bukan-uuid',
            'nama_lengkap' => 'PYBMC malformed',
            'status_aktif' => 'Aktif',
        ]);

        $this->assertApplyGlobalMenolakApprover($approver);
    }

    public function test_global_pybmc_direct_menolak_approver_id_kosong_sebagai_error_domain(): void
    {
        $approver = new Employee;
        $approver->forceFill([
            'id' => '',
            'nama_lengkap' => 'PYBMC tanpa ID',
            'status_aktif' => 'Aktif',
        ]);

        $this->assertApplyGlobalMenolakApprover($approver);
    }

    public function test_global_pybmc_menolak_chain_aktif_tanpa_kepala_bagian_dan_rollback_chain_valid(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pybmcLama = Employee::factory()->create();
        $pybmcBaru = Employee::factory()->create();
        $pegawaiValid = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        $pegawaiRusak = Employee::factory()->create();

        $chainValid = LeaveApprovalChain::create([
            'employee_id' => $pegawaiValid->id,
            'name' => 'Chain valid sebelum override',
            'effective_from' => today(),
        ]);
        $chainValid->steps()->createMany([
            ['step_order' => 1, 'step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => false],
            ['step_order' => 2, 'step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmcLama->id, 'is_final' => true],
        ]);
        $chainRusak = LeaveApprovalChain::create([
            'employee_id' => $pegawaiRusak->id,
            'name' => 'Chain tanpa Kepala Bagian',
            'effective_from' => today(),
        ]);
        $chainRusak->steps()->create([
            'step_order' => 1,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $pybmcLama->id,
            'is_final' => true,
        ]);
        $stepValidSebelum = $chainValid->steps()->orderBy('step_order')->get()->toArray();
        $stepRusakSebelum = $chainRusak->steps()->orderBy('step_order')->get()->toArray();
        $jumlahAuditSebelum = AuditLog::count();
        $exception = null;

        try {
            $this->app->make(ApplyGlobalPybmcAction::class)->execute(
                $pybmcBaru,
                $actor,
                'Override harus gagal karena ada chain tanpa Kepala Bagian.',
            );
        } catch (\Throwable $caught) {
            $exception = $caught;
        }

        $this->assertSame($stepValidSebelum, $chainValid->steps()->orderBy('step_order')->get()->toArray());
        $this->assertSame($stepRusakSebelum, $chainRusak->steps()->orderBy('step_order')->get()->toArray());
        $this->assertDatabaseCount('leave_pybmc_global_config', 0);
        $this->assertSame($jumlahAuditSebelum, AuditLog::count());
        $this->assertNotNull($exception);
        $this->assertSame(RuntimeException::class, $exception::class);
    }

    public function test_global_pybmc_memakai_actor_eksplisit_saat_tidak_ada_session_auth(): void
    {
        $actor = User::factory()->superAdmin()->create(['name' => 'Aktor Global Eksplisit']);
        $pybmc = Employee::factory()->create();

        $this->assertGuest();

        $config = $this->app->make(ApplyGlobalPybmcAction::class)->execute(
            $pybmc,
            $actor,
            'Audit harus memakai actor eksplisit.',
        );

        $audit = AuditLog::query()
            ->where('auditable_type', 'LeavePybmcGlobalConfig')
            ->where('auditable_id', $config->id)
            ->sole();

        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame($actor->name, $audit->user_name);
        $this->assertSame(0, $audit->new_values['affected_chain_count']);
    }

    public function test_kegagalan_audit_global_pybmc_dipropagasikan_dan_rollback_seluruh_mutasi(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Rollback trigger audit PYBMC global diverifikasi khusus pada PostgreSQL.');
        }

        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pybmcLama = Employee::factory()->create();
        $pybmcBaru = Employee::factory()->create();
        $pegawaiSatu = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        $pegawaiDua = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        $chains = collect([$pegawaiSatu, $pegawaiDua])->map(function (Employee $pegawai) use ($kepalaBagian, $pybmcLama): LeaveApprovalChain {
            $chain = LeaveApprovalChain::create([
                'employee_id' => $pegawai->id,
                'name' => 'Chain valid untuk kegagalan audit',
                'effective_from' => today(),
            ]);
            $chain->steps()->createMany([
                ['step_order' => 1, 'step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => false],
                ['step_order' => 2, 'step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmcLama->id, 'is_final' => true],
            ]);

            return $chain;
        });
        $stepSebelum = $chains->mapWithKeys(fn (LeaveApprovalChain $chain): array => [
            $chain->id => $chain->steps()->orderBy('step_order')->get()->toArray(),
        ])->all();

        $this->tolakPenulisanAuditPybmcGlobal();
        $exception = null;
        DB::beginTransaction();

        try {
            $this->app->make(ApplyGlobalPybmcAction::class)->execute(
                $pybmcBaru,
                $actor,
                'Mutasi wajib rollback jika audit global gagal.',
            );
        } catch (\Throwable $caught) {
            $exception = $caught;
        }

        if ($exception === null) {
            // Implementasi lama menelan error PostgreSQL dan meninggalkan transaksi pada status abort.
            // Savepoint test perlu di-rollback agar keadaan database masih dapat diverifikasi sebagai RED.
            DB::rollBack();
        } else {
            DB::commit();
        }

        foreach ($chains as $chain) {
            $this->assertSame($stepSebelum[$chain->id], $chain->steps()->orderBy('step_order')->get()->toArray());
        }
        $this->assertDatabaseCount('leave_pybmc_global_config', 0);
        $this->assertDatabaseMissing('audit_logs', ['auditable_type' => 'LeavePybmcGlobalConfig']);
        $this->assertNotNull($exception);
        $this->assertInstanceOf(QueryException::class, $exception);
    }

    public function test_global_pybmc_gagal_transaksional_jika_chain_aktif_memiliki_banyak_final(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pybmcLama = Employee::factory()->create();
        $pybmcBaru = Employee::factory()->create();
        $kepalaBagianValid = Employee::factory()->create();
        $pegawaiValid = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagianValid->id]);
        $pegawaiRusak = Employee::factory()->create();
        $chainValid = LeaveApprovalChain::create([
            'employee_id' => $pegawaiValid->id,
            'name' => 'Chain valid',
            'effective_from' => today(),
        ]);
        $chainValid->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $kepalaBagianValid->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $pybmcLama->id,
                'is_final' => true,
            ],
        ]);
        $chainRusak = LeaveApprovalChain::create([
            'employee_id' => $pegawaiRusak->id,
            'name' => 'Chain rusak banyak final',
            'effective_from' => today(),
        ]);
        $chainRusak->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC Lama 1',
                'approver_employee_id' => $pybmcLama->id,
                'is_final' => true,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC Lama 2',
                'approver_employee_id' => $pybmcLama->id,
                'is_final' => true,
            ],
        ]);

        try {
            $this->actingAs($actor)->app->make(ApplyGlobalPybmcAction::class)->execute(
                $pybmcBaru,
                $actor,
                'Override harus rollback karena chain rusak.',
            );
            $this->fail('Global override wajib gagal saat chain aktif memiliki lebih dari satu final.');
        } catch (RuntimeException) {
            $this->assertSame(
                [$kepalaBagianValid->id, $pybmcLama->id],
                $chainValid->steps()->orderBy('step_order')->pluck('approver_employee_id')->all(),
            );
            $this->assertSame(2, $chainRusak->steps()->where('is_final', true)->count());
            $this->assertDatabaseCount('leave_pybmc_global_config', 0);
            $this->assertDatabaseMissing('audit_logs', [
                'auditable_type' => 'LeavePybmcGlobalConfig',
            ]);
        }
    }

    public function test_chain_tanpa_final_memakai_pybmc_global(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $pybmc = Employee::factory()->create();

        $this->actingAs($actor)->app->make(ApplyGlobalPybmcAction::class)->execute($pybmc, $actor, 'PYBMC global.');

        $chain = $this->app->make(SaveEmployeeApprovalChainAction::class)->execute($pegawai, [
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => false],
        ], $actor, 'Chain memakai PYBMC global.');

        $this->assertSame([$kepalaBagian->id, $pybmc->id], $chain->steps()->orderBy('step_order')->pluck('approver_employee_id')->all());
        $this->assertTrue($chain->steps()->orderByDesc('step_order')->firstOrFail()->is_final);
    }

    public function test_audit_chain_pegawai_menyimpan_jejak_forensik_permintaan(): void
    {
        // Audit konfigurasi persetujuan wajib menyimpan alamat dan perangkat pemohon supaya perubahan
        // kewenangan dapat ditelusuri, bukan hanya diketahui siapa aktornya.
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $pybmc = Employee::factory()->create();

        $this->actingAs($actor)->app->make(ApplyGlobalPybmcAction::class)->execute($pybmc, $actor, 'PYBMC global.');

        $this->actingAs($actor)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'PengujiJejak/1.0'])
            ->post(route('cuti.config.employee-chain.store', $pegawai), [
                'steps' => [[
                    'step_type' => 'kepala_bagian',
                    'role_label' => 'Kepala Bagian',
                    'approver_employee_id' => $kepalaBagian->id,
                ]],
                'reason' => 'Set chain pegawai untuk uji jejak forensik.',
            ])
            ->assertRedirect(route('cuti.config'));

        $audit = AuditLog::query()
            ->where('auditable_type', 'LeaveApprovalChain')
            ->where('event', 'CREATE')
            ->sole();

        $this->assertSame('203.0.113.7', $audit->ip_address);
        $this->assertSame('PengujiJejak/1.0', $audit->user_agent);
    }

    public function test_kegagalan_audit_membatalkan_penyimpanan_chain_pegawai(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Rollback trigger audit rantai pegawai diverifikasi khusus pada PostgreSQL.');
        }

        // Konfigurasi persetujuan tidak boleh berpindah tanpa jejak. Penulisan audit berada di dalam
        // transaksi penyimpanan, sehingga kegagalannya wajib menggagalkan penyimpanan rantai juga.
        // Test ini menjaga sifat itu agar penulisan audit tidak dipindahkan ke luar transaksi.
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);

        $this->tolakPenulisanAuditRantai();

        try {
            $this->actingAs($actor)->app->make(SaveEmployeeApprovalChainAction::class)->execute($pegawai, [
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => false],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => true],
            ], $actor, 'Chain yang auditnya gagal ditulis.');

            $this->fail('Penyimpanan chain seharusnya dibatalkan ketika audit gagal ditulis.');
        } catch (QueryException) {
            // Kegagalan memang diharapkan; yang diuji adalah keadaan basis data sesudahnya.
        }

        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $pegawai->id]);
    }

    /**
     * Memasang penjaga sementara yang menolak penulisan audit rantai approval.
     * Dipakai untuk membuktikan sifat fail-closed tanpa menyentuh kode produksi.
     */
    private function tolakPenulisanAuditRantai(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION uji_tolak_audit_rantai() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Penulisan audit rantai ditolak untuk pengujian.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER uji_tolak_audit_rantai
            BEFORE INSERT ON audit_logs
            FOR EACH ROW
            WHEN (NEW.auditable_type = 'LeaveApprovalChain')
            EXECUTE FUNCTION uji_tolak_audit_rantai();
        SQL);
    }

    /**
     * Menolak audit konfigurasi PYBMC global untuk membuktikan mutasi kewenangan bersifat fail-closed.
     */
    private function tolakPenulisanAuditPybmcGlobal(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION uji_tolak_audit_pybmc_global() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Penulisan audit PYBMC global ditolak untuk pengujian.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER uji_tolak_audit_pybmc_global
            BEFORE INSERT ON audit_logs
            FOR EACH ROW
            WHEN (NEW.auditable_type = 'LeavePybmcGlobalConfig')
            EXECUTE FUNCTION uji_tolak_audit_pybmc_global();
        SQL);
    }

    public function test_route_resave_chain_legacy_membuat_successor_auditabel_tanpa_mengubah_snapshot(): void
    {
        $fixture = $this->buatFixtureRantaiLegacyDenganSnapshot();

        $this->actingAs($fixture['actor'])
            ->post(route('cuti.config.employee-chain.store', $fixture['employee']), [
                'steps' => [
                    ['step_type' => 'verifier', 'role_label' => 'Verifikator Kepegawaian', 'approver_employee_id' => $fixture['verifier']->id],
                    ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $fixture['kepala_bagian']->id],
                    ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id],
                ],
                'reason' => 'Menyusun ulang chain legacy secara auditabel.',
            ])
            ->assertRedirect(route('cuti.config'));

        $predecessor = $fixture['predecessor']->fresh();
        $this->assertNotNull($predecessor);
        $this->assertFalse($predecessor->is_active);
        $this->assertNotNull($predecessor->effective_until);

        $successor = LeaveApprovalChain::query()
            ->where('employee_id', $fixture['employee']->id)
            ->where('is_active', true)
            ->sole();
        $this->assertSame(
            ['verifier', 'kepala_bagian', 'pybmc'],
            $successor->steps()->orderBy('step_order')->pluck('step_type')->all(),
        );
        $this->assertSame(
            [$fixture['verifier']->id, $fixture['kepala_bagian']->id, $fixture['pybmc']->id],
            $successor->steps()->orderBy('step_order')->pluck('approver_employee_id')->all(),
        );

        $audit = AuditLog::query()
            ->where('auditable_type', 'LeaveApprovalChain')
            ->where('auditable_id', $successor->id)
            ->where('event', 'CREATE')
            ->sole();
        $this->assertSame(
            1,
            AuditLog::query()->where('auditable_type', 'LeaveApprovalChain')->count(),
        );
        $this->assertSame($fixture['actor']->id, $audit->user_id);
        $this->assertSame($fixture['actor']->name, $audit->user_name);
        $this->assertSame($fixture['predecessor']->id, $audit->old_values['id'] ?? null);
        $this->assertTrue($audit->old_values['is_active'] ?? false);
        $oldAuditSteps = collect($audit->old_values['steps'] ?? []);
        $this->assertSame([1, 2, 3], $oldAuditSteps->pluck('step_order')->all());
        $this->assertSame(
            ['kepala_bagian', 'verifier', 'pybmc'],
            $oldAuditSteps->pluck('step_type')->all(),
        );
        $this->assertSame(
            [$fixture['kepala_bagian']->id, $fixture['verifier']->id, $fixture['pybmc']->id],
            $oldAuditSteps->pluck('approver_employee_id')->all(),
        );
        $this->assertSame(
            ['Kepala Bagian', 'Verifikator Kepegawaian', 'PYBMC'],
            $oldAuditSteps->pluck('role_label')->all(),
        );
        $this->assertSame([false, false, true], $oldAuditSteps->pluck('is_final')->all());
        $this->assertSame('Menyusun ulang chain legacy secara auditabel.', $audit->new_values['reason'] ?? null);
        $this->assertSame(
            ['verifier', 'kepala_bagian', 'pybmc'],
            collect($audit->new_values['steps'] ?? [])->pluck('step_type')->all(),
        );
        $this->assertSame(
            [$fixture['verifier']->id, $fixture['kepala_bagian']->id, $fixture['pybmc']->id],
            collect($audit->new_values['steps'] ?? [])->pluck('approver_employee_id')->all(),
        );
        $this->assertSame(
            $fixture['snapshot'],
            $this->snapshotLangkahPengajuan($fixture['leave_request']->id),
        );
    }

    public function test_route_resave_chain_legacy_rollback_saat_audit_gagal_dan_snapshot_tetap_identik(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Rollback re-save chain legacy diverifikasi khusus pada PostgreSQL.');
        }

        $fixture = $this->buatFixtureRantaiLegacyDenganSnapshot();
        $this->tolakPenulisanAuditRantai();
        $this->withoutExceptionHandling();
        $exception = null;

        try {
            $this->actingAs($fixture['actor'])
                ->post(route('cuti.config.employee-chain.store', $fixture['employee']), [
                    'steps' => [
                        ['step_type' => 'verifier', 'role_label' => 'Verifikator Kepegawaian', 'approver_employee_id' => $fixture['verifier']->id],
                        ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $fixture['kepala_bagian']->id],
                        ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id],
                    ],
                    'reason' => 'Re-save chain legacy yang auditnya gagal.',
                ]);
        } catch (QueryException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(QueryException::class, $exception);
        $predecessor = $fixture['predecessor']->fresh();
        $this->assertNotNull($predecessor);
        $this->assertTrue($predecessor->is_active);
        $this->assertNull($predecessor->effective_until);
        $this->assertSame(
            1,
            LeaveApprovalChain::query()->where('employee_id', $fixture['employee']->id)->count(),
        );
        $this->assertSame(0, AuditLog::query()->where('auditable_type', 'LeaveApprovalChain')->count());
        $this->assertSame(
            $fixture['snapshot'],
            $this->snapshotLangkahPengajuan($fixture['leave_request']->id),
        );
    }

    public function test_super_admin_bisa_menyimpan_chain_pegawai_melalui_route(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
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

        $chain = LeaveApprovalChain::query()
            ->where('employee_id', $pegawai->id)
            ->where('is_active', true)
            ->sole();
        $this->assertSame(
            ['kepala_bagian', 'pybmc'],
            $chain->steps()->orderBy('step_order')->pluck('step_type')->all(),
        );
        $this->assertSame(
            [$kepalaBagian->id, $pybmc->id],
            $chain->steps()->orderBy('step_order')->pluck('approver_employee_id')->all(),
        );
    }

    public function test_route_menyimpan_satu_verifikator_sebelum_kepala_bagian(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $verifikator = Employee::factory()->create();
        $pybmc = Employee::factory()->create();

        $response = $this->actingAs($actor)->post(route('cuti.config.employee-chain.store', $pegawai), [
            'steps' => [
                ['step_type' => 'verifier', 'role_label' => 'Ketua Tim Kerja', 'approver_employee_id' => $verifikator->id],
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id],
            ],
            'reason' => 'Menyimpan Ketua Tim sebagai verifikator.',
        ]);

        $response->assertRedirect(route('cuti.config'));
        $chain = LeaveApprovalChain::query()
            ->where('employee_id', $pegawai->id)
            ->where('is_active', true)
            ->sole();

        $this->assertSame(
            ['verifier', 'kepala_bagian', 'pybmc'],
            $chain->steps()->orderBy('step_order')->pluck('step_type')->all(),
        );
        $this->assertSame(
            [$verifikator->id, $kepalaBagian->id, $pybmc->id],
            $chain->steps()->orderBy('step_order')->pluck('approver_employee_id')->all(),
        );
    }

    public function test_route_menolak_verifikator_setelah_kepala_bagian_secara_actionable(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $verifikator = Employee::factory()->create();
        $pybmc = Employee::factory()->create();

        $response = $this->actingAs($actor)->post(route('cuti.config.employee-chain.store', $pegawai), [
            'steps' => [
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id],
                ['step_type' => 'verifier', 'role_label' => 'Verifikator', 'approver_employee_id' => $verifikator->id],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id],
            ],
            'reason' => 'Menguji urutan legacy yang tidak sah.',
        ]);

        $response->assertSessionHasErrors([
            'steps' => 'Semua Verifikator harus ditempatkan sebelum Atasan Langsung. Pindahkan Verifikator yang berada setelah Atasan Langsung.',
        ]);
        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $pegawai->id]);
        $this->assertSame(0, AuditLog::query()->where('auditable_type', 'LeaveApprovalChain')->count());
    }

    public function test_route_menyimpan_payload_browser_dengan_pybmc_kosong_menggunakan_pybmc_global(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $pybmcGlobal = Employee::factory()->create();
        $this->actingAs($actor)->app->make(ApplyGlobalPybmcAction::class)->execute(
            $pybmcGlobal,
            $actor,
            'PYBMC global untuk payload browser.',
        );

        $response = $this->actingAs($actor)->post(route('cuti.config.employee-chain.store', $pegawai), [
            'steps' => [
                0 => [
                    'step_type' => 'kepala_bagian',
                    'role_label' => 'Kepala Bagian',
                    'approver_employee_id' => $kepalaBagian->id,
                ],
                '_pybmc' => ['approver_employee_id' => ''],
            ],
            'reason' => 'Simpan payload browser memakai global.',
        ]);

        $response->assertRedirect(route('cuti.config'));
        $chain = LeaveApprovalChain::query()->where('employee_id', $pegawai->id)->where('is_active', true)->firstOrFail();
        $this->assertSame(
            [$kepalaBagian->id, $pybmcGlobal->id],
            $chain->steps()->orderBy('step_order')->pluck('approver_employee_id')->all(),
        );
        $this->assertTrue($chain->steps()->orderByDesc('step_order')->firstOrFail()->is_final);
    }

    public function test_route_menormalisasi_key_numeric_tidak_berurutan_sebelum_menyimpan_chain(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $verifikatorSatu = Employee::factory()->create();
        $verifikatorDua = Employee::factory()->create();
        $pybmc = Employee::factory()->create();

        $response = $this->actingAs($actor)->post(route('cuti.config.employee-chain.store', $pegawai), [
            'steps' => [
                2 => ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id],
                0 => ['step_type' => 'verifier', 'role_label' => 'Verifikator 1', 'approver_employee_id' => $verifikatorSatu->id],
                1 => ['step_type' => 'verifier', 'role_label' => 'Ketua Tim Kerja', 'approver_employee_id' => $verifikatorDua->id],
                '_pybmc' => ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id],
            ],
            'reason' => 'Menguji normalisasi key numeric pada boundary HTTP.',
        ]);

        $response->assertRedirect(route('cuti.config'));
        $chain = LeaveApprovalChain::query()
            ->where('employee_id', $pegawai->id)
            ->where('is_active', true)
            ->sole();

        $this->assertSame(
            ['verifier', 'verifier', 'kepala_bagian', 'pybmc'],
            $chain->steps()->orderBy('step_order')->pluck('step_type')->all(),
        );
        $this->assertSame(
            [$verifikatorSatu->id, $verifikatorDua->id, $kepalaBagian->id, $pybmc->id],
            $chain->steps()->orderBy('step_order')->pluck('approver_employee_id')->all(),
        );
    }

    public function test_route_menolak_payload_transport_dengan_sebelas_step_tanpa_mutasi(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $verifikators = Employee::factory()->count(9)->create();
        $pybmc = Employee::factory()->create();
        $steps = $verifikators->map(fn (Employee $verifikator): array => [
            'step_type' => 'verifier',
            'role_label' => 'Verifikator',
            'approver_employee_id' => $verifikator->id,
        ])->all();
        $steps[] = [
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $kepalaBagian->id,
        ];
        $steps[] = [
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $pybmc->id,
        ];

        $response = $this->actingAs($actor)->post(route('cuti.config.employee-chain.store', $pegawai), [
            'steps' => $steps,
            'reason' => 'Menguji guard ukuran payload transport existing.',
        ]);

        $response->assertSessionHasErrors(['steps']);
        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $pegawai->id]);
        $this->assertSame(0, AuditLog::query()->where('auditable_type', 'LeaveApprovalChain')->count());
    }

    #[DataProvider('keyStepHttpTidakValid')]
    public function test_route_menolak_key_step_http_tidak_valid_tanpa_mutasi(int|string $invalidKey): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $verifikator = Employee::factory()->create();
        $pybmc = Employee::factory()->create();

        $response = $this->actingAs($actor)->post(route('cuti.config.employee-chain.store', $pegawai), [
            'steps' => [
                0 => ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id],
                $invalidKey => ['step_type' => 'verifier', 'role_label' => 'Verifikator', 'approver_employee_id' => $verifikator->id],
                '_pybmc' => ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id],
            ],
            'reason' => 'Menguji key payload yang tidak sah.',
        ]);

        $response->assertSessionHasErrors([
            'steps' => 'Struktur langkah approval tidak valid. Muat ulang halaman dan susun kembali chain.',
        ]);
        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $pegawai->id]);
        $this->assertSame(0, AuditLog::query()->where('auditable_type', 'LeaveApprovalChain')->count());
    }

    /** @return iterable<string, array{int|string}> */
    public static function keyStepHttpTidakValid(): iterable
    {
        yield 'nama key arbitrer' => ['legacy'];
        yield 'key numeric negatif' => [-1];
        yield 'numeric string tidak kanonis' => ['01'];
    }

    #[DataProvider('nilaiPybmcMalformed')]
    public function test_route_menolak_pybmc_malformed_tanpa_fallback_global(mixed $malformedPybmc): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $pybmcGlobal = Employee::factory()->create();
        $this->actingAs($actor)->app->make(ApplyGlobalPybmcAction::class)->execute(
            $pybmcGlobal,
            $actor,
            'PYBMC global tidak boleh menjadi fallback payload malformed.',
        );

        $response = $this->actingAs($actor)->post(route('cuti.config.employee-chain.store', $pegawai), [
            'steps' => [
                0 => ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id],
                '_pybmc' => $malformedPybmc,
            ],
            'reason' => 'Menguji nilai PYBMC malformed.',
        ]);

        $response->assertSessionHasErrors([
            'steps' => 'Struktur langkah approval tidak valid. Muat ulang halaman dan susun kembali chain.',
        ]);
        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $pegawai->id]);
        $this->assertSame(0, AuditLog::query()->where('auditable_type', 'LeaveApprovalChain')->count());
    }

    /** @return iterable<string, array{mixed}> */
    public static function nilaiPybmcMalformed(): iterable
    {
        yield 'null eksplisit' => [null];
        yield 'scalar string' => ['teks'];
        yield 'boolean' => [false];
    }

    public function test_route_tetap_menolak_entry_pybmc_parsial(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $pybmcGlobal = Employee::factory()->create();
        $this->actingAs($actor)->app->make(ApplyGlobalPybmcAction::class)->execute($pybmcGlobal, $actor, 'PYBMC global.');

        $response = $this->actingAs($actor)->post(route('cuti.config.employee-chain.store', $pegawai), [
            'steps' => [
                0 => [
                    'step_type' => 'kepala_bagian',
                    'role_label' => 'Kepala Bagian',
                    'approver_employee_id' => $kepalaBagian->id,
                ],
                '_pybmc' => [
                    'step_type' => 'pybmc',
                    'role_label' => '',
                    'approver_employee_id' => '',
                ],
            ],
            'reason' => 'Uji payload PYBMC parsial.',
        ]);

        $response->assertSessionHasErrors([
            'steps.1.role_label',
            'steps.1.approver_employee_id',
        ]);
        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $pegawai->id]);
    }

    public function test_route_menerima_pegawai_tugas_belajar_sebagai_approver(): void
    {
        // Klasifikasi aktif memakai kelompok referensi: Tugas Belajar (Aktif/khusus)
        // tetap sah sebagai approver — konsisten dengan isActive()/whereActiveStatus().
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        // Referensi Tugas Belajar (kelompok Aktif/khusus) — dibuat inline karena file
        // test ini tidak meload ReferenceSeeder; isi sama dengan seeder produksi.
        $tugasBelajarStatus = RefStatusPegawai::updateOrCreate(
            ['kode' => 'TUGAS_BELAJAR'],
            ['nama' => 'Tugas Belajar', 'kelompok' => 'Aktif/khusus', 'is_active' => true, 'is_default' => false, 'keterangan' => 'Pegawai menjalani tugas belajar.'],
        );
        $tugasBelajar = Employee::factory()->create([
            'status_aktif' => 'Tugas Belajar',
            'status_pegawai_id' => $tugasBelajarStatus->id,
        ]);
        $pybmc = Employee::factory()->create();

        $response = $this->actingAs($actor)->post(route('cuti.config.employee-chain.store', $pegawai), [
            'steps' => [
                ['step_type' => 'verifier', 'role_label' => 'Verifikator', 'approver_employee_id' => $tugasBelajar->id],
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id],
            ],
            'reason' => 'Uji verifikator tugas belajar.',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('leave_approval_chains', ['employee_id' => $pegawai->id]);
        $this->assertTrue($tugasBelajar->refresh()->isActive());
    }

    public function test_route_menolak_verifikator_nonaktif(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $verifikatorNonaktif = Employee::factory()->create(['status_aktif' => 'Non-Aktif']);
        $pybmc = Employee::factory()->create();

        $response = $this->actingAs($actor)->post(route('cuti.config.employee-chain.store', $pegawai), [
            'steps' => [
                ['step_type' => 'verifier', 'role_label' => 'Verifikator', 'approver_employee_id' => $verifikatorNonaktif->id],
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id],
            ],
            'reason' => 'Uji verifikator nonaktif.',
        ]);

        $response->assertSessionHasErrors([
            'steps.0.approver_employee_id' => 'Approver chain harus merupakan pegawai aktif.',
        ]);
        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $pegawai->id]);
    }

    public function test_route_menolak_verifikator_yang_sudah_dihapus(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $verifikatorDihapus = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $verifikatorDihapus->delete();

        $response = $this->actingAs($actor)->post(route('cuti.config.employee-chain.store', $pegawai), [
            'steps' => [
                ['step_type' => 'verifier', 'role_label' => 'Verifikator', 'approver_employee_id' => $verifikatorDihapus->id],
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id],
            ],
            'reason' => 'Uji verifikator yang sudah dihapus.',
        ]);

        $response->assertSessionHasErrors([
            'steps.0.approver_employee_id' => 'Approver chain harus merupakan pegawai aktif.',
        ]);
        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $pegawai->id]);
    }

    public function test_route_menolak_pybmc_khusus_nonaktif(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $pybmcNonaktif = Employee::factory()->create(['status_aktif' => 'Non-Aktif']);

        $response = $this->actingAs($actor)->post(route('cuti.config.employee-chain.store', $pegawai), [
            'steps' => [
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmcNonaktif->id],
            ],
            'reason' => 'Uji PYBMC khusus nonaktif.',
        ]);

        $response->assertSessionHasErrors([
            'steps.1.approver_employee_id' => 'Approver chain harus merupakan pegawai aktif.',
        ]);
        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $pegawai->id]);
    }

    public function test_route_menolak_chain_tanpa_pybmc_khusus_saat_pybmc_global_belum_ada(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);

        $response = $this->actingAs($actor)->post(route('cuti.config.employee-chain.store', $pegawai), [
            'steps' => [[
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $kepalaBagian->id,
            ]],
            'reason' => 'Menguji chain tanpa sumber PYBMC.',
        ]);

        $response->assertSessionHasErrors([
            'steps' => 'PYBMC khusus wajib dipilih karena PYBMC global belum dikonfigurasi.',
        ]);
        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $pegawai->id]);
    }

    public function test_route_menyimpan_verifikator_dinamis_dan_pybmc_khusus_pegawai(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $verifikatorSatu = Employee::factory()->create();
        $verifikatorDua = Employee::factory()->create();
        $pybmc = Employee::factory()->create();

        $response = $this->actingAs($actor)->post(route('cuti.config.employee-chain.store', $pegawai), [
            'steps' => [
                [
                    'step_type' => 'verifier',
                    'role_label' => 'Verifikator',
                    'approver_employee_id' => $verifikatorSatu->id,
                ],
                [
                    'step_type' => 'verifier',
                    'role_label' => 'Ketua Tim Kerja',
                    'approver_employee_id' => $verifikatorDua->id,
                ],
                [
                    'step_type' => 'kepala_bagian',
                    'role_label' => 'Kepala Bagian',
                    'approver_employee_id' => $kepalaBagian->id,
                ],
                [
                    'step_type' => 'pybmc',
                    'role_label' => 'PYBMC',
                    'approver_employee_id' => $pybmc->id,
                ],
            ],
            'reason' => 'Menyimpan dua verifikator dan PYBMC khusus pegawai.',
        ]);

        $response->assertRedirect(route('cuti.config'));

        $chain = LeaveApprovalChain::query()
            ->where('employee_id', $pegawai->id)
            ->where('is_active', true)
            ->firstOrFail();

        $this->assertSame(
            [$verifikatorSatu->id, $verifikatorDua->id, $kepalaBagian->id, $pybmc->id],
            $chain->steps()->orderBy('step_order')->pluck('approver_employee_id')->all(),
        );
        $this->assertSame(
            ['verifier', 'verifier', 'kepala_bagian', 'pybmc'],
            $chain->steps()->orderBy('step_order')->pluck('step_type')->all(),
        );
        $this->assertTrue($chain->steps()->orderByDesc('step_order')->firstOrFail()->is_final);
    }

    public function test_route_menolak_kepala_bagian_snapshot_yang_bukan_penugasan_efektif_pegawai(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagianSnapshot = Employee::factory()->create();
        $kepalaBagianEfektif = Employee::factory()->create();
        $pegawai = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagianSnapshot->id]);
        SupervisorAssignment::create([
            'employee_id' => $pegawai->id,
            'kepala_bagian_id' => $kepalaBagianEfektif->id,
            'tanggal_mulai' => today()->subDay(),
            'tanggal_berakhir' => null,
        ]);
        $pybmc = Employee::factory()->create();
        $this->actingAs($actor)->app->make(ApplyGlobalPybmcAction::class)->execute($pybmc, $actor, 'PYBMC global.');

        $response = $this->actingAs($actor)->post(route('cuti.config.employee-chain.store', $pegawai), [
            'steps' => [[
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $kepalaBagianSnapshot->id,
            ]],
            'reason' => 'Menguji relasi Kepala Bagian.',
        ]);

        $response->assertSessionHasErrors([
            'steps.0.approver_employee_id' => 'Approver tahap Atasan Langsung harus sesuai penugasan Atasan Langsung efektif pegawai.',
        ]);
        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $pegawai->id]);
    }

    public function test_route_menolak_chain_saat_pegawai_tidak_memiliki_kepala_bagian_efektif(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $approverSnapshot = Employee::factory()->create();
        $pegawai = Employee::factory()->create(['kepala_bagian_id' => $approverSnapshot->id]);
        $pybmc = Employee::factory()->create();
        $this->actingAs($actor)->app->make(ApplyGlobalPybmcAction::class)->execute($pybmc, $actor, 'PYBMC global.');

        $response = $this->actingAs($actor)->post(route('cuti.config.employee-chain.store', $pegawai), [
            'steps' => [[
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $approverSnapshot->id,
            ]],
            'reason' => 'Menguji pegawai tanpa Kepala Bagian.',
        ]);

        $response->assertSessionHasErrors([
            'steps.0.approver_employee_id' => 'Atasan Langsung belum ditetapkan untuk pegawai. Tetapkan penugasan Atasan Langsung terlebih dahulu.',
        ]);
        $this->assertDatabaseMissing('leave_approval_chains', ['employee_id' => $pegawai->id]);
    }

    public function test_resolver_mempertahankan_approver_sama_pada_peran_berbeda_dan_final_pybmc(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $approverSama = Employee::factory()->create();
        $verifikator = Employee::factory()->create();

        // Atasan efektif wajib ada agar resolver melewati gerbang fail-closed dan membuktikan
        // satu pegawai pada peran berbeda tetap menghasilkan tahap yang terpisah.
        SupervisorAssignment::create([
            'employee_id' => $pegawai->id,
            'kepala_bagian_id' => $approverSama->id,
            'tanggal_mulai' => today()->subDay(),
            'tanggal_berakhir' => null,
        ]);

        $this->actingAs($actor)->app->make(SaveEmployeeApprovalChainAction::class)->execute($pegawai, [
            ['step_type' => 'verifier', 'role_label' => 'Verifikator', 'approver_employee_id' => $verifikator->id, 'is_final' => false],
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $approverSama->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $approverSama->id, 'is_final' => true],
        ], $actor, 'Chain dengan duplikasi approver.');

        $steps = $this->app->make(ApprovalChainResolver::class)->resolveEffectiveSteps($pegawai);

        $this->assertCount(3, $steps);
        $this->assertSame([$verifikator->id, $approverSama->id, $approverSama->id], $steps->pluck('approver_employee_id')->all());
        $this->assertSame(['Verifikator', 'Atasan Langsung', 'PYBMC'], $steps->pluck('role_label')->all());
        $this->assertTrue($steps->last()->is_final);
        $this->assertSame('pybmc', $steps->last()->step_type);
    }

    public function test_resolver_menolak_chain_legacy_dengan_verifikator_setelah_kepala_bagian_tanpa_efek_samping(): void
    {
        $pegawai = Employee::factory()->create();
        $kepalaBagian = Employee::factory()->create();
        $verifikator = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        SupervisorAssignment::create([
            'employee_id' => $pegawai->id,
            'kepala_bagian_id' => $kepalaBagian->id,
            'tanggal_mulai' => today()->subDay(),
            'tanggal_berakhir' => null,
        ]);
        $chain = LeaveApprovalChain::create([
            'employee_id' => $pegawai->id,
            'name' => 'Chain legacy invalid untuk resolver',
            'effective_from' => today(),
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
                'step_type' => 'verifier',
                'role_label' => 'Verifikator Kepegawaian',
                'approver_employee_id' => $verifikator->id,
                'is_final' => false,
            ],
            [
                'step_order' => 3,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $pybmc->id,
                'is_final' => true,
            ],
        ]);
        $jumlahAuditSebelum = AuditLog::query()->count();

        try {
            $this->app->make(ApprovalChainResolver::class)->resolveEffectiveSteps($pegawai);
            $this->fail('Resolver harus menolak chain aktif legacy yang tidak mengikuti urutan kanonis.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Semua Verifikator harus ditempatkan sebelum Atasan Langsung.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('leave_requests', 0);
        $this->assertDatabaseCount('leave_request_steps', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame($jumlahAuditSebelum, AuditLog::query()->count());
    }

    public function test_resolver_fail_closed_jika_chain_tidak_punya_pybmc(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $kepalaBagian = Employee::factory()->create();

        // Kepala Bagian efektif wajib ada agar resolver mencapai guard bentuk dan menolak chain tanpa PYBMC.
        SupervisorAssignment::create([
            'employee_id' => $pegawai->id,
            'kepala_bagian_id' => $kepalaBagian->id,
            'tanggal_mulai' => today()->subDay(),
            'tanggal_berakhir' => null,
        ]);

        LeaveApprovalChain::create([
            'employee_id' => $pegawai->id,
            'name' => 'Chain rusak',
            'effective_from' => today(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'change_reason' => 'Uji chain tanpa PYBMC.',
        ])->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $kepalaBagian->id,
            'is_final' => false,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rantai approval cuti wajib memiliki tepat satu step PYBMC.');

        $this->app->make(ApprovalChainResolver::class)->resolveEffectiveSteps($pegawai);
    }

    public function test_resolver_fail_closed_jika_supervisor_aktif_ada_tetapi_step_kepala_bagian_hilang(): void
    {
        $pegawai = Employee::factory()->create();
        $kepalaBagian = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        SupervisorAssignment::create([
            'employee_id' => $pegawai->id,
            'kepala_bagian_id' => $kepalaBagian->id,
            'tanggal_mulai' => today()->subDay(),
            'tanggal_berakhir' => null,
        ]);
        LeaveApprovalChain::create([
            'employee_id' => $pegawai->id,
            'name' => 'Chain tanpa Kepala Bagian',
            'effective_from' => today(),
        ])->steps()->create([
            'step_order' => 1,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $pybmc->id,
            'is_final' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rantai approval cuti wajib memiliki step Atasan Langsung.');

        $this->app->make(ApprovalChainResolver::class)->resolveEffectiveSteps($pegawai);
    }

    public function test_backfill_membuat_chain_verifikator_sebelum_kepala_bagian_dan_pybmc(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $verifikator = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $kepalaBagianSnapshotLama = Employee::factory()->create();
        $tanpaPenugasanEfektif = Employee::factory()->create([
            'kepala_bagian_id' => $kepalaBagianSnapshotLama->id,
        ]);
        $verifikatorUser = User::factory()->adminKepegawaian()->create(['employee_id' => $verifikator->id]);
        $pybmcUser = User::factory()->pimpinan()->create(['employee_id' => $pybmc->id]);

        ApprovalConfig::setVal('stage2_approver_id', $verifikatorUser->id);
        ApprovalConfig::setVal('stage3_approver_id', $pybmcUser->id);

        $result = $this->actingAs($actor)->app->make(BackfillEmployeeApprovalChainsAction::class)->execute($actor, 'Backfill awal Phase 3.');

        $this->assertContains($pegawai->id, $result['created_employee_ids']);
        $this->assertContains($tanpaPenugasanEfektif->id, $result['missing_kepala_bagian_employee_ids']);
        $this->assertDatabaseMissing('leave_approval_chains', [
            'employee_id' => $tanpaPenugasanEfektif->id,
        ]);

        $chain = LeaveApprovalChain::where('employee_id', $pegawai->id)->firstOrFail();
        $this->assertSame([$verifikator->id, $kepalaBagian->id, $pybmc->id], $chain->steps()->orderBy('step_order')->pluck('approver_employee_id')->all());
    }

    public function test_backfill_tidak_mengubah_snapshot_pengajuan_existing(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $verifikator = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $verifikatorUser = User::factory()->adminKepegawaian()->create(['employee_id' => $verifikator->id]);
        $pybmcUser = User::factory()->pimpinan()->create(['employee_id' => $pybmc->id]);
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Sakit Backfill',
            'code' => 'sakit_backfill',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $pengajuan = LeaveRequest::create([
            'employee_id' => $pegawai->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-11',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Menjaga snapshot pengajuan lama saat backfill.',
            'status' => 'menunggu_approval',
        ]);
        $pengajuan->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian Lama',
                'approver_employee_id' => $kepalaBagian->id,
                'status' => 'disetujui',
                'is_final' => false,
                'acted_at' => '2026-08-12 08:00:00',
                'decision_note' => 'Snapshot lama yang sudah diputuskan.',
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC Lama',
                'approver_employee_id' => $pybmc->id,
                'status' => 'menunggu',
                'is_final' => true,
                'skipped_reason' => null,
            ],
        ]);

        ApprovalConfig::setVal('stage2_approver_id', $verifikatorUser->id);
        ApprovalConfig::setVal('stage3_approver_id', $pybmcUser->id);

        $snapshotSebelum = DB::table('leave_request_steps')
            ->where('leave_request_id', $pengajuan->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $result = $this->app->make(BackfillEmployeeApprovalChainsAction::class)->execute(
            $actor,
            'Backfill tanpa mengubah snapshot pengajuan existing.',
        );

        $snapshotSesudah = DB::table('leave_request_steps')
            ->where('leave_request_id', $pengajuan->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $this->assertContains($pegawai->id, $result['created_employee_ids']);
        $this->assertSame($snapshotSebelum, $snapshotSesudah);
    }

    public function test_backfill_melanjutkan_target_lain_saat_kepala_bagian_efektif_nonaktif(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagianAktif = Employee::factory()->create();
        $statusNonaktif = RefStatusPegawai::firstOrCreate(
            ['kode' => 'PENSIUN_BACKFILL'],
            [
                'nama' => 'Pensiun Backfill',
                'kelompok' => 'Nonaktif',
                'keterangan' => 'Fixture Kepala Bagian nonaktif untuk backfill.',
                'is_default' => false,
                'is_active' => true,
            ],
        );
        $kepalaBagianNonaktif = Employee::factory()->create();
        $kepalaBagianNonaktif->update(['status_pegawai_id' => $statusNonaktif->id]);
        $verifikator = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $verifikatorUser = User::factory()->adminKepegawaian()->create(['employee_id' => $verifikator->id]);
        $pybmcUser = User::factory()->pimpinan()->create(['employee_id' => $pybmc->id]);

        $targetSebelum = Employee::factory()->make(['nama_lengkap' => 'Target Sebelum KB Nonaktif']);
        $targetSebelum->id = '10000000-0000-4000-8000-000000000001';
        $targetSebelum->save();
        $targetKepalaBagianNonaktif = Employee::factory()->make(['nama_lengkap' => 'Target KB Nonaktif']);
        $targetKepalaBagianNonaktif->id = '20000000-0000-4000-8000-000000000002';
        $targetKepalaBagianNonaktif->save();
        $targetSesudah = Employee::factory()->make(['nama_lengkap' => 'Target Sesudah KB Nonaktif']);
        $targetSesudah->id = '30000000-0000-4000-8000-000000000003';
        $targetSesudah->save();

        SupervisorAssignment::insert([
            [
                'id' => (string) str()->uuid(),
                'employee_id' => $targetSebelum->id,
                'supervisor_id' => $kepalaBagianAktif->id,
                'kepala_bagian_id' => $kepalaBagianAktif->id,
                'tanggal_mulai' => today()->subDay(),
                'tanggal_berakhir' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) str()->uuid(),
                'employee_id' => $targetKepalaBagianNonaktif->id,
                'supervisor_id' => $kepalaBagianNonaktif->id,
                'kepala_bagian_id' => $kepalaBagianNonaktif->id,
                'tanggal_mulai' => today()->subDay(),
                'tanggal_berakhir' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) str()->uuid(),
                'employee_id' => $targetSesudah->id,
                'supervisor_id' => $kepalaBagianAktif->id,
                'kepala_bagian_id' => $kepalaBagianAktif->id,
                'tanggal_mulai' => today()->subDay(),
                'tanggal_berakhir' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertFalse($kepalaBagianNonaktif->fresh()->isActive());
        ApprovalConfig::setVal('stage2_approver_id', $verifikatorUser->id);
        ApprovalConfig::setVal('stage3_approver_id', $pybmcUser->id);

        $result = $this->app->make(BackfillEmployeeApprovalChainsAction::class)->execute(
            $actor,
            'Backfill tetap melanjutkan setelah Kepala Bagian nonaktif.',
        );

        $this->assertContains($targetSebelum->id, $result['created_employee_ids']);
        $this->assertContains($targetSesudah->id, $result['created_employee_ids']);
        $this->assertContains(
            $targetKepalaBagianNonaktif->id,
            $result['missing_kepala_bagian_employee_ids'],
        );
        $this->assertDatabaseHas('leave_approval_chains', [
            'employee_id' => $targetSebelum->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('leave_approval_chains', [
            'employee_id' => $targetSesudah->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('leave_approval_chains', [
            'employee_id' => $targetKepalaBagianNonaktif->id,
        ]);
    }

    public function test_backfill_memisahkan_skip_karena_final_approver_tidak_tersedia(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);

        $result = $this->actingAs($actor)->app->make(BackfillEmployeeApprovalChainsAction::class)->execute($actor, 'Backfill tanpa PYBMC.');

        $this->assertContains($pegawai->id, $result['missing_final_approver_employee_ids']);
        $this->assertNotContains($pegawai->id, $result['skipped_employee_ids']);
    }

    public function test_backfill_memproses_101_target_dengan_urutan_nama_dan_uuid_berlawanan(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create(['nama_lengkap' => 'ZZZ Kepala Bagian Backfill']);
        $verifikator = Employee::factory()->create(['nama_lengkap' => 'ZZZ Verifikator Backfill']);
        $pybmc = Employee::factory()->create(['nama_lengkap' => 'ZZZ PYBMC Backfill']);
        $verifikatorUser = User::factory()->adminKepegawaian()->create(['employee_id' => $verifikator->id]);
        $pybmcUser = User::factory()->pimpinan()->create(['employee_id' => $pybmc->id]);
        $targetIds = [];

        foreach (range(1, 101) as $number) {
            $id = match ($number) {
                100 => 'ffffffff-ffff-4fff-bfff-ffffffffffff',
                101 => '00000000-0000-4000-8000-000000000101',
                default => sprintf('10000000-0000-4000-8000-%012d', $number),
            };
            $pegawai = Employee::factory()->make([
                'nama_lengkap' => sprintf('Target Backfill %03d', $number),
                'kepala_bagian_id' => $kepalaBagian->id,
            ]);
            $pegawai->id = $id;
            $pegawai->save();
            SupervisorAssignment::create([
                'employee_id' => $pegawai->id,
                'kepala_bagian_id' => $kepalaBagian->id,
                'tanggal_mulai' => today()->subDay(),
                'tanggal_berakhir' => null,
            ]);
            $targetIds[] = $id;
        }

        ApprovalConfig::setVal('stage2_approver_id', $verifikatorUser->id);
        ApprovalConfig::setVal('stage3_approver_id', $pybmcUser->id);

        $result = $this->app->make(BackfillEmployeeApprovalChainsAction::class)->execute(
            $actor,
            'Backfill deterministik melintasi batas chunk.',
        );

        $createdIds = $result['created_employee_ids'];
        sort($createdIds);
        sort($targetIds);

        $this->assertSame($targetIds, $createdIds);
        $this->assertSame(101, count(array_unique($result['created_employee_ids'])));
        $this->assertSame(
            101,
            LeaveApprovalChain::query()->whereIn('employee_id', $targetIds)->where('is_active', true)->count(),
        );
        $this->assertSame(
            303,
            DB::table('leave_approval_chain_steps')
                ->whereIn('leave_approval_chain_id', LeaveApprovalChain::query()->whereIn('employee_id', $targetIds)->pluck('id'))
                ->count(),
        );
    }

    public function test_super_admin_bisa_menyimpan_pybmc_global_melalui_route(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pybmc = Employee::factory()->create();

        $this->actingAs($actor)->post(route('cuti.config.pybmc-global'), [
            'approver_employee_id' => $pybmc->id,
        ])->assertSessionHasErrors('pybmc_reason');

        $this->assertDatabaseCount('leave_pybmc_global_config', 0);

        $response = $this->actingAs($actor)->post(route('cuti.config.pybmc-global'), [
            'approver_employee_id' => $pybmc->id,
            'pybmc_reason' => 'Penetapan PYBMC global melalui route.',
        ]);

        $response->assertRedirect(route('cuti.config'));
        $this->assertTrue(LeavePybmcGlobalConfig::where('approver_employee_id', $pybmc->id)->exists());
    }

    public function test_route_menolak_pybmc_global_nonaktif(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pybmcNonaktif = Employee::factory()->create(['status_aktif' => 'Non-Aktif']);

        $response = $this->actingAs($actor)->post(route('cuti.config.pybmc-global'), [
            'approver_employee_id' => $pybmcNonaktif->id,
            'pybmc_reason' => 'Uji PYBMC global nonaktif.',
        ]);

        $response->assertSessionHasErrors([
            'approver_employee_id' => 'PYBMC global harus merupakan pegawai aktif.',
        ]);
        $this->assertDatabaseCount('leave_pybmc_global_config', 0);
    }

    public function test_route_menolak_pybmc_global_yang_sudah_dihapus(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pybmcDihapus = Employee::factory()->create();
        $pybmcDihapus->delete();

        $response = $this->actingAs($actor)->post(route('cuti.config.pybmc-global'), [
            'approver_employee_id' => $pybmcDihapus->id,
            'pybmc_reason' => 'Uji PYBMC global yang sudah dihapus.',
        ]);

        $response->assertSessionHasErrors([
            'approver_employee_id' => 'PYBMC global harus merupakan pegawai aktif.',
        ]);
        $this->assertDatabaseCount('leave_pybmc_global_config', 0);
    }

    public function test_super_admin_bisa_memicu_backfill_chain_dari_halaman_konfigurasi(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $verifikator = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $verifikatorUser = User::factory()->adminKepegawaian()->create(['employee_id' => $verifikator->id]);
        $pybmcUser = User::factory()->pimpinan()->create(['employee_id' => $pybmc->id]);

        ApprovalConfig::setVal('stage2_approver_id', $verifikatorUser->id);
        ApprovalConfig::setVal('stage3_approver_id', $pybmcUser->id);

        $response = $this->actingAs($actor)->post(route('cuti.config.backfill'));

        $response->assertRedirect(route('cuti.config'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('leave_approval_chains', ['employee_id' => $pegawai->id, 'is_active' => true]);

        $chain = LeaveApprovalChain::query()
            ->where('employee_id', $pegawai->id)
            ->where('is_active', true)
            ->sole();
        $audit = AuditLog::query()
            ->where('auditable_type', 'LeaveApprovalChain')
            ->where('auditable_id', $chain->id)
            ->sole();

        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame($pegawai->id, $audit->new_values['employee_id']);
        $this->assertSame('Atasan Langsung', $audit->new_values['steps'][1]['role_label']);
        $this->assertArrayHasKey('reason', $audit->new_values);
        $this->assertNull($audit->new_values['reason']);
    }

    public function test_halaman_konfigurasi_menampilkan_panel_backfill_chain(): void
    {
        $actor = User::factory()->superAdmin()->create();

        $response = $this->actingAs($actor)->get(route('cuti.config'));

        $response->assertOk();
        $response->assertSee('Backfill Chain Dinamis');
        $response->assertSee('Jalankan Backfill Chain');
    }

    public function test_halaman_konfigurasi_menampilkan_kontrol_chain_pegawai_dinamis_tanpa_stage_tetap(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create(['nama_lengkap' => 'Kepala Bagian Uji']);
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian, [
            'nama_lengkap' => 'Pegawai Konfigurasi',
        ]);

        $response = $this->actingAs($actor)->get(route('cuti.config', [
            'search' => 'Pegawai Konfigurasi',
            'employee_id' => $pegawai->id,
        ]));

        $response->assertOk();
        $response->assertSee('Pilih Pegawai');
        $response->assertSee('Atasan Langsung');
        $response->assertSee('Tambah Verifikator');
        $response->assertSee('for="employee-pybmc"', false);
        $response->assertSee('aria-label="Naikkan urutan verifikator"', false);
        $response->assertSee('aria-label="Turunkan urutan verifikator"', false);
        $response->assertDontSee('Approver Stage 2');
        $response->assertDontSee('Approver Stage 3');
    }

    public function test_editor_task_2_merender_index_payload_dan_error_sesuai_urutan_kanonis(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $verifikator = Employee::factory()->create();
        $pybmc = Employee::factory()->create();

        $this->actingAs($actor)->app->make(SaveEmployeeApprovalChainAction::class)->execute(
            $pegawai,
            [
                ['step_type' => 'verifier', 'role_label' => 'Ketua Tim Kerja', 'approver_employee_id' => $verifikator->id, 'is_final' => false],
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => false],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id, 'is_final' => true],
            ],
            $actor,
            'Fixture editor Task 2.',
        );

        $response = $this->actingAs($actor)->get(route('cuti.config', [
            'employee_id' => $pegawai->id,
        ]));

        $response->assertOk()
            ->assertSee(':name="`steps[${index}][step_type]`"', false)
            ->assertSee(':name="`steps[${verifiers.length}][step_type]`"', false)
            ->assertSee('verifier.validation_errors.role_label', false)
            ->assertSee('verifier.validation_errors.approver_employee_id', false)
            ->assertSee('kepalaBagianError', false)
            ->assertSee('pybmcError', false)
            ->assertSee(':aria-describedby="verifier.validation_errors.role_label ? `verifier-label-error-${verifier.client_key}` : null"', false)
            ->assertSee(':id="`verifier-label-error-${verifier.client_key}`"', false)
            ->assertDontSee('errorFor(`steps.${index}', false)
            ->assertDontSee('errorFor(`steps.${verifiers.length}', false)
            ->assertSee(':key="verifier.client_key"', false)
            ->assertSee('aria-live="polite" aria-atomic="true" x-text="announcement"', false);
    }

    public function test_halaman_konfigurasi_membatasi_hasil_pegawai_dan_menampilkan_audit_chain_dinamis(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Batas 00',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        SupervisorAssignment::create([
            'employee_id' => $pegawai->id,
            'kepala_bagian_id' => $kepalaBagian->id,
            'tanggal_mulai' => today()->subDay(),
            'tanggal_berakhir' => null,
        ]);

        foreach (range(1, 50) as $number) {
            Employee::factory()->create([
                'nama_lengkap' => sprintf('Pegawai Batas %02d', $number),
            ]);
        }

        $this->actingAs($actor)->app->make(SaveEmployeeApprovalChainAction::class)->execute(
            $pegawai,
            [[
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $kepalaBagian->id,
                'is_final' => false,
            ], [
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $kepalaBagian->id,
                'is_final' => true,
            ]],
            $actor,
            'Menguji audit chain dan batas hasil pencarian.',
        );

        $response = $this->actingAs($actor)->get(route('cuti.config', ['search' => 'Pegawai Batas']));

        $response->assertOk();
        $response->assertSee('Pegawai Batas 49');
        $response->assertDontSee('Pegawai Batas 50');
        $response->assertSee('Chain pegawai');
        $response->assertSee('Menguji audit chain dan batas hasil pencarian.');
    }

    public function test_halaman_konfigurasi_menampilkan_pegawai_aktif_sebagai_kandidat_approver_dengan_pencarian_terbatas(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $verifikatorPegawai = Employee::factory()->create(['nama_lengkap' => 'Approver Pegawai 00']);
        User::factory()->pegawai()->create(['employee_id' => $verifikatorPegawai->id]);

        foreach (range(1, 50) as $number) {
            Employee::factory()->create([
                'nama_lengkap' => sprintf('Approver Pegawai %02d', $number),
            ]);
        }

        $response = $this->actingAs($actor)->get(route('cuti.config', [
            'search' => $pegawai->nip,
            'employee_id' => $pegawai->id,
            'approver_search' => 'Approver Pegawai',
        ]));

        $response->assertOk();
        $response->assertSee('Approver Pegawai 00');
        $response->assertSee('Approver Pegawai 49');
        $response->assertDontSee('Approver Pegawai 50');
    }

    public function test_halaman_konfigurasi_mempertahankan_kandidat_chain_saat_pencarian_approver_berbeda(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $verifikatorTersimpan = Employee::factory()->create(['nama_lengkap' => 'Verifikator Tersimpan']);
        $hasilPencarianLain = Employee::factory()->create(['nama_lengkap' => 'Kandidat Hasil Lain']);

        $this->actingAs($actor)->app->make(SaveEmployeeApprovalChainAction::class)->execute(
            $pegawai,
            [[
                'step_type' => 'verifier',
                'role_label' => 'Verifikator',
                'approver_employee_id' => $verifikatorTersimpan->id,
                'is_final' => false,
            ], [
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $kepalaBagian->id,
                'is_final' => false,
            ], [
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $kepalaBagian->id,
                'is_final' => true,
            ]],
            $actor,
            'Menyimpan kandidat yang harus tetap tersedia.',
        );

        $response = $this->actingAs($actor)->get(route('cuti.config', [
            'search' => $pegawai->nip,
            'employee_id' => $pegawai->id,
            'approver_search' => 'Kandidat Hasil Lain',
        ]));

        $response->assertOk();
        $response->assertSee($verifikatorTersimpan->nama_lengkap);
        $response->assertSee($hasilPencarianLain->nama_lengkap);
    }

    public function test_backfill_chain_wajib_permission_configure(): void
    {
        $pegawai = User::factory()->pegawai()->create();

        $response = $this->actingAs($pegawai)->post(route('cuti.config.backfill'), [
            'backfill_reason' => 'Percobaan tanpa permission.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('leave_approval_chains', 0);
    }

    /**
     * Menyiapkan satu-satunya rantai aktif yang sah agar tes dapat membuktikan kegagalan kandidat
     * tidak menonaktifkan atau mengganti rantai yang sedang dipakai pengajuan cuti baru.
     *
     * @return array{
     *     action: SaveEmployeeApprovalChainAction,
     *     actor: User,
     *     employee: Employee,
     *     kepala_bagian: Employee,
     *     pybmc: Employee,
     *     chain: LeaveApprovalChain,
     *     step_ids: list<string>,
     *     audit_count: int
     * }
     */
    private function buatFixtureChainAktifValid(): array
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $employee = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $action = $this->actingAs($actor)->app->make(SaveEmployeeApprovalChainAction::class);
        $chain = $action->execute($employee, [
            ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => false],
            ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id, 'is_final' => true],
        ], $actor, 'Rantai aktif awal sebelum kandidat tidak sah diuji.');

        return [
            'action' => $action,
            'actor' => $actor,
            'employee' => $employee,
            'kepala_bagian' => $kepalaBagian,
            'pybmc' => $pybmc,
            'chain' => $chain,
            'step_ids' => $chain->steps()->orderBy('step_order')->pluck('id')->all(),
            'audit_count' => AuditLog::query()->where('auditable_type', 'LeaveApprovalChain')->count(),
        ];
    }

    /**
     * Kandidat rantai yang tidak memenuhi invariant wajib gagal sebagai kesalahan domain sebelum
     * rantai lama, langkahnya, atau jejak audit mengalami perubahan parsial.
     *
     * @param  array{
     *     action: SaveEmployeeApprovalChainAction,
     *     actor: User,
     *     employee: Employee,
     *     kepala_bagian: Employee,
     *     pybmc: Employee,
     *     chain: LeaveApprovalChain,
     *     step_ids: list<string>,
     *     audit_count: int
     * }  $fixture
     * @param  array<int, array{step_type:string, role_label:string, approver_employee_id:string|null, is_final:bool}>  $steps
     */
    private function assertSaveChainInvalidTidakMengubahChainLama(
        array $fixture,
        array $steps,
        ?string $expectedMessage = null,
    ): void {
        $exception = null;

        try {
            $fixture['action']->execute(
                $fixture['employee'],
                $steps,
                $fixture['actor'],
                'Kandidat rantai tidak sah harus ditolak.',
            );
        } catch (\Throwable $caught) {
            $exception = $caught;
        }

        $chainLama = $fixture['chain']->fresh();

        $this->assertNotNull($chainLama);
        $this->assertTrue($chainLama->is_active);
        $this->assertNull($chainLama->effective_until);
        $this->assertSame(
            1,
            LeaveApprovalChain::query()->where('employee_id', $fixture['employee']->id)->count(),
        );
        $this->assertSame(
            [$fixture['kepala_bagian']->id, $fixture['pybmc']->id],
            $chainLama->steps()->orderBy('step_order')->pluck('approver_employee_id')->all(),
        );
        $this->assertSame(
            $fixture['step_ids'],
            $chainLama->steps()->orderBy('step_order')->pluck('id')->all(),
        );
        $this->assertSame(
            ['kepala_bagian', 'pybmc'],
            $chainLama->steps()->orderBy('step_order')->pluck('step_type')->all(),
        );
        $this->assertSame(
            [false, true],
            $chainLama->steps()->orderBy('step_order')->pluck('is_final')->all(),
        );
        $this->assertSame(
            $fixture['audit_count'],
            AuditLog::query()->where('auditable_type', 'LeaveApprovalChain')->count(),
        );
        $this->assertNotNull($exception);
        $this->assertSame(RuntimeException::class, $exception::class);

        if ($expectedMessage !== null) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }
    }

    /**
     * Approver global yang bukan pegawai aktif tersimpan wajib ditolak sebagai kesalahan domain,
     * termasuk saat belum ada rantai aktif yang akan diperbarui.
     */
    private function assertApplyGlobalMenolakApprover(Employee $approver): void
    {
        $actor = User::factory()->superAdmin()->create();
        $jumlahAuditSebelum = AuditLog::count();
        $exception = null;

        try {
            $this->app->make(ApplyGlobalPybmcAction::class)->execute(
                $approver,
                $actor,
                'Approver global tidak sah wajib ditolak.',
            );
        } catch (\Throwable $caught) {
            $exception = $caught;
        }

        $this->assertDatabaseCount('leave_pybmc_global_config', 0);
        $this->assertSame($jumlahAuditSebelum, AuditLog::count());
        $this->assertNotNull($exception);
        $this->assertSame(RuntimeException::class, $exception::class);
    }

    /**
     * Membentuk predecessor legacy dan snapshot pengajuan yang sengaja tidak melewati writer kanonis.
     *
     * @return array{
     *     actor: User,
     *     employee: Employee,
     *     kepala_bagian: Employee,
     *     verifier: Employee,
     *     pybmc: Employee,
     *     predecessor: LeaveApprovalChain,
     *     leave_request: LeaveRequest,
     *     snapshot: list<array<string, mixed>>
     * }
     */
    private function buatFixtureRantaiLegacyDenganSnapshot(): array
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $verifier = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $employee = $this->buatPegawaiDenganKepalaBagianEfektif($kepalaBagian);
        $predecessor = LeaveApprovalChain::create([
            'employee_id' => $employee->id,
            'name' => 'Chain legacy sebelum re-save',
            'is_active' => true,
            'effective_from' => today()->subDay(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'change_reason' => 'Fixture predecessor legacy.',
        ]);
        $predecessor->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $kepalaBagian->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'verifier',
                'role_label' => 'Verifikator Kepegawaian',
                'approver_employee_id' => $verifier->id,
                'is_final' => false,
            ],
            [
                'step_order' => 3,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $pybmc->id,
                'is_final' => true,
            ],
        ]);
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Sakit Snapshot Re-save Legacy',
            'code' => 'cuti_sakit_snapshot_resave_legacy',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-03',
            'tanggal_selesai' => '2026-08-04',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Snapshot historis sebelum re-save.',
            'status' => 'menunggu_approval',
        ]);

        foreach ($predecessor->steps()->orderBy('step_order')->get() as $index => $step) {
            $leaveRequest->steps()->create([
                'step_order' => $step->step_order,
                'step_type' => $step->step_type,
                'role_label' => $step->role_label,
                'approver_employee_id' => $step->approver_employee_id,
                'status' => $index === 0 ? 'active' : 'pending',
                'is_final' => $step->is_final,
                'skipped_reason' => null,
                'decision_note' => $index === 0 ? 'Snapshot aktif sebelum re-save.' : null,
            ]);
        }

        return [
            'actor' => $actor,
            'employee' => $employee,
            'kepala_bagian' => $kepalaBagian,
            'verifier' => $verifier,
            'pybmc' => $pybmc,
            'predecessor' => $predecessor,
            'leave_request' => $leaveRequest,
            'snapshot' => $this->snapshotLangkahPengajuan($leaveRequest->id),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function snapshotLangkahPengajuan(string $leaveRequestId): array
    {
        return DB::table('leave_request_steps')
            ->where('leave_request_id', $leaveRequestId)
            ->orderBy('step_order')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * Membuat relasi Kepala Bagian bertanggal efektif agar fixture memakai sumber lifecycle kanonis.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function buatPegawaiDenganKepalaBagianEfektif(Employee $kepalaBagian, array $attributes = []): Employee
    {
        $pegawai = Employee::factory()->create([
            'kepala_bagian_id' => $kepalaBagian->id,
            ...$attributes,
        ]);

        SupervisorAssignment::create([
            'employee_id' => $pegawai->id,
            'kepala_bagian_id' => $kepalaBagian->id,
            'tanggal_mulai' => today()->subDay(),
            'tanggal_berakhir' => null,
        ]);

        return $pegawai;
    }
}
