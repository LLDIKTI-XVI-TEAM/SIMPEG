<?php

namespace Tests\Feature;

use App\Actions\Cuti\ApplyEmployeeApprovalChainsAction;
use App\Actions\Cuti\ApplyGlobalPybmcAction;
use App\Actions\Cuti\PreviewEmployeeApprovalChainsAction;
use App\Actions\Cuti\SaveEmployeeApprovalChainAction;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeavePybmcGlobalConfig;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\RefJenisCuti;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use App\Models\Role;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\Cuti\ApprovalChainConfigurationLockService;
use App\Services\Cuti\EmployeeApprovalChainBatchService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Membuktikan pratinjau menyusun kewenangan tiap target tanpa menulis konfigurasi. */
class EmployeeApprovalChainBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->seedRbac();
    }

    public function test_preview_memakai_atasan_masing_masing_target_tanpa_mutasi(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $supervisorA = Employee::factory()->create();
        $supervisorB = Employee::factory()->create();
        $a = $this->assignedEmployee($supervisorA);
        $b = $this->assignedEmployee($supervisorB);
        $pybmc = Employee::factory()->create();
        $auditBefore = AuditLog::query()->count();

        $response = $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/pratinjau', [
            'employee_ids' => [$a->id, $b->id], 'mode' => 'missing_only',
            'verifiers' => [], 'pybmc_mode' => 'custom',
            'pybmc_employee_id' => $pybmc->id, 'reason' => null,
        ])->assertOk()->assertJsonPath('data.counts.create', 2)
            ->assertJsonPath('data.can_apply', true);

        $rows = collect($response->json('data.rows'))->keyBy('employee_id');
        $this->assertSame($supervisorA->id, $rows[$a->id]['after_steps'][0]['approver_employee_id']);
        $this->assertSame($supervisorB->id, $rows[$b->id]['after_steps'][0]['approver_employee_id']);
        $this->assertNotEmpty($response->json('data.preview_token'));
        $this->assertDatabaseCount('leave_approval_chains', 0);
        $this->assertDatabaseCount('leave_approval_chain_steps', 0);
        $this->assertDatabaseCount('supervisor_assignments', 2);
        $this->assertDatabaseCount('leave_pybmc_global_config', 0);
        $this->assertSame($auditBefore, AuditLog::query()->count());
    }

    #[DataProvider('invalidDrafts')]
    public function test_draft_malformed_ditolak_sebelum_query_uuid(array $changes, string $field): void
    {
        [$actor, $employee, $pybmc] = $this->fixture();
        $response = $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/pratinjau',
            array_replace($this->draft([$employee->id], $pybmc->id), $changes),
        )->assertUnprocessable()->assertJsonValidationErrors($field);
        if (($changes['reason'] ?? null) === 'abcd') {
            $this->assertStringContainsString('alasan penerapan', $response->json('errors.reason.0'));
            $this->assertStringNotContainsString('reason', $response->json('errors.reason.0'));
        }
        $this->assertDatabaseCount('leave_approval_chains', 0);
    }

    public static function invalidDrafts(): array
    {
        return [
            'target kosong' => [['employee_ids' => []], 'employee_ids'],
            'target bukan list' => [['employee_ids' => ['x' => 'invalid']], 'employee_ids'],
            'uuid malformed' => [['employee_ids' => ['invalid']], 'employee_ids.0'],
            'uuid array' => [['employee_ids' => [['id' => 'invalid']]], 'employee_ids.0'],
            'duplicate case' => [['employee_ids' => ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA']], 'employee_ids.1'],
            'terlalu banyak target' => [['employee_ids' => array_map(fn ($n) => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $n), range(1, 101))], 'employee_ids'],
            'mode asing' => [['mode' => 'all'], 'mode'],
            'verifier bukan list' => [['verifiers' => ['foo' => []]], 'verifiers'],
            'verifier injection' => [['verifiers' => [['approver_employee_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'role_label' => 'Uji', 'is_final' => true]]], 'verifiers.0'],
            'pybmc array' => [['pybmc_employee_id' => ['x']], 'pybmc_employee_id'],
            'global custom campur' => [['pybmc_mode' => 'global'], 'pybmc_employee_id'],
            'pybmc custom kosong' => [['pybmc_employee_id' => null], 'pybmc_employee_id'],
            'label unicode kosong' => [['verifiers' => [['approver_employee_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'role_label' => "\u{00a0}\u{2003}"]]], 'verifiers.0.role_label'],
            'reason array' => [['reason' => ['x']], 'reason'],
            'reason pendek' => [['reason' => 'abcd'], 'reason'],
            'reason panjang' => [['reason' => str_repeat('a', 501)], 'reason'],
            'root injection' => [['atasan_employee_id' => 'invalid'], 'draft'],
        ];
    }

    public function test_verifier_satu_delapan_dan_aktor_lintas_peran_tetap_berurutan(): void
    {
        [$actor, $employee, $pybmc] = $this->fixture();
        $supervisor = $employee->currentSupervisor()->kepalaBagian;
        $verifiers = [$supervisor, $pybmc, $employee, ...Employee::factory()->count(6)->create()->all()];
        foreach ([1, 8, 9] as $count) {
            $draft = $this->draft([$employee->id], $pybmc->id);
            $draft['verifiers'] = array_map(fn ($e) => ['approver_employee_id' => strtoupper($e->id), 'role_label' => "\u{2003}Verifikator\u{00a0}"], array_slice($verifiers, 0, $count));
            if ($count === 9) {
                $this->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)->assertUnprocessable()->assertJsonValidationErrors('verifiers');

                continue;
            }
            $response = $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)
                ->assertOk()->assertJsonCount($count + 2, 'data.rows.0.after_steps')
                ->assertJsonPath('data.rows.0.after_steps.0.role_label', 'Verifikator')
                ->assertJsonPath('data.rows.0.after_steps.0.approver_employee_id', $supervisor->id)
                ->assertJsonPath('data.rows.0.after_steps.'.$count.'.approver_employee_id', $supervisor->id);
            if ($count === 8) {
                $this->assertStringContainsString('diri sendiri', $response->json('data.rows.0.message'));
            }
        }
        $draft['verifiers'] = array_slice($draft['verifiers'], 0, 8);
        $draft['verifiers'][1]['approver_employee_id'] = strtoupper($supervisor->id);
        $this->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)->assertUnprocessable()
            ->assertJsonValidationErrors('verifiers.1.approver_employee_id');
    }

    public function test_seratus_target_diterima_tanpa_memotong_pilihan(): void
    {
        [$actor, $first, $pybmc] = $this->fixture();
        $supervisor = $first->currentSupervisor()->kepalaBagian;
        $ids = [$first->id];
        foreach (range(2, 100) as $ignored) {
            $ids[] = $this->assignedEmployee($supervisor)->id;
        }
        $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/pratinjau', $this->draft($ids, $pybmc->id))
            ->assertOk()->assertJsonCount(100, 'data.rows')->assertJsonPath('data.counts.create', 100);
        $this->applyDraft($actor, $this->draft($ids, $pybmc->id))->assertOk()
            ->assertJsonCount(100, 'data.rows')->assertJsonPath('data.counts.create', 100);
        $this->assertSame(100, LeaveApprovalChain::query()->whereIn('employee_id', $ids)->where('is_active', true)->count());
        $this->assertSame(100, AuditLog::query()->where('auditable_type', 'LeaveApprovalChain')->count());
    }

    public function test_outcome_missing_replace_unchanged_dan_alasan_berdasarkan_penggantian_nyata(): void
    {
        [$actor, $employee, $pybmc] = $this->fixture();
        $chain = $this->chain($employee, $pybmc);
        $draft = $this->draft([$employee->id], $pybmc->id);
        $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)
            ->assertOk()->assertJsonPath('data.counts.skip_existing', 1)->assertJsonPath('data.can_apply', false);
        $draft['mode'] = 'replace';
        $draft['reason'] = 'Alasan baru tidak mengganti rantai yang sama.';
        $this->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)
            ->assertOk()->assertJsonPath('data.counts.unchanged', 1)->assertJsonPath('data.can_apply', false);
        $chain->steps()->where('step_type', 'pybmc')->update(['approver_role_key' => 'metadata_lama']);
        $draft['reason'] = null;
        $this->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)
            ->assertOk()->assertJsonPath('data.counts.replace', 1);
        $second = $this->assignedEmployee($employee->currentSupervisor()->kepalaBagian);
        $draft['employee_ids'][] = $second->id;
        $draft['reason'] = "\u{2003}\u{00a0}";
        $this->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)->assertUnprocessable()->assertJsonValidationErrors('reason');
        foreach ([5, 500] as $length) {
            $draft['reason'] = "\u{2003}".str_repeat('a', $length)."\u{00a0}";
            $this->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)->assertOk()
                ->assertJsonPath('data.counts.create', 1)->assertJsonPath('data.counts.replace', 1);
        }
        $this->assertDatabaseCount('leave_approval_chains', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_target_tanpa_atasan_future_nonaktif_dan_self_required_dilewati(): void
    {
        [$actor, $valid, $pybmc] = $this->fixture();
        $missing = Employee::factory()->create();
        $future = $this->assignedEmployee($pybmc);
        $future->supervisorAssignments()->update(['tanggal_mulai' => today()->addDay()]);
        $inactive = Employee::factory()->create(['status_aktif' => 'Non-Aktif']);
        $inactiveSupervisor = $this->assignedEmployee($inactive);
        $selfSupervisor = Employee::factory()->create();
        $selfSupervisor->supervisorAssignments()->create(['supervisor_id' => $selfSupervisor->id, 'tanggal_mulai' => today()]);
        $pybmc->supervisorAssignments()->create(['supervisor_id' => $valid->currentSupervisor()->kepala_bagian_id, 'tanggal_mulai' => today()]);
        $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/pratinjau', $this->draft(
            [$missing->id, $future->id, $inactive->id, $inactiveSupervisor->id, $selfSupervisor->id, $pybmc->id], $pybmc->id,
        ))->assertOk()->assertJsonPath('data.counts.skip_supervisor', 3)
            ->assertJsonPath('data.counts.skip_inactive', 1)->assertJsonPath('data.counts.skip_self_required', 2)
            ->assertJsonPath('data.can_apply', false);
    }

    public function test_common_candidate_invalid_menggagalkan_semua_draft_termasuk_target_skip(): void
    {
        [$actor, $employee, $pybmc] = $this->fixture();
        $this->chain($employee, $pybmc);
        $this->actingAs($actor);
        foreach ([Str::uuid()->toString(), Employee::factory()->create(['status_aktif' => 'Non-Aktif'])->id] as $badId) {
            $draft = $this->draft([$employee->id], $badId);
            $this->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)->assertUnprocessable()->assertJsonValidationErrors('pybmc_employee_id');
            $draft = $this->draft([$employee->id], $pybmc->id);
            $draft['verifiers'] = [['approver_employee_id' => $badId, 'role_label' => 'Verifikator']];
            $this->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)->assertUnprocessable()->assertJsonValidationErrors('verifiers');
        }
    }

    public function test_global_resolve_konkret_dan_token_memuat_hash_state_tanpa_identitas_privat(): void
    {
        [$actor, $employee, $pybmc] = $this->fixture();
        $draft = $this->draft([$employee->id], null);
        $draft['pybmc_mode'] = 'global';
        $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)->assertUnprocessable()->assertJsonValidationErrors('pybmc_employee_id');
        $global = LeavePybmcGlobalConfig::query()->create(['approver_employee_id' => $pybmc->id, 'effective_from' => today(), 'created_by' => $actor->id]);
        $response = $this->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)->assertOk()
            ->assertJsonPath('data.rows.0.after_steps.1.approver_employee_id', $pybmc->id);
        $token = json_decode(Crypt::decryptString($response->json('data.preview_token')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $token['version']);
        $this->assertSame($actor->id, $token['actor_id']);
        $this->assertEqualsWithDelta(now()->timestamp + 600, $token['expires_at'], 2);
        $this->assertStringNotContainsString($employee->nip, json_encode($token));
        $service = app(EmployeeApprovalChainBatchService::class);
        $prepared = $service->prepare($actor, $draft);
        $this->assertSame($token['state_hash'], $service->fingerprint($prepared));
        $global->update(['approver_employee_id' => $employee->currentSupervisor()->kepala_bagian_id]);
        $this->assertNotSame($token['state_hash'], $service->fingerprint($service->prepare($actor, $draft)));
    }

    #[DataProvider('globalReaders')]
    public function test_pembaca_global_memakai_perubahan_terakhir_pada_detik_yang_sama(string $reader): void
    {
        $this->freezeTime();
        $actor = User::factory()->superAdmin()->create();
        $employee = $this->assignedEmployee(Employee::factory()->create());
        $oldPybmc = Employee::factory()->create(['id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff']);
        $newPybmc = Employee::factory()->create(['id' => '00000000-0000-4000-8000-000000000001']);
        // Kedua konfigurasi sengaja memiliki urutan UUID yang berkebalikan dengan waktu tulis.
        $this->app['events']->listen('eloquent.creating: '.LeavePybmcGlobalConfig::class, function (LeavePybmcGlobalConfig $config) use ($oldPybmc): void {
            $config->id = $config->approver_employee_id === $oldPybmc->id
                ? 'ffffffff-ffff-4fff-8fff-ffffffffffff'
                : '00000000-0000-4000-8000-000000000001';
        });
        $existing = $this->chain($this->assignedEmployee($employee->currentSupervisor()->kepalaBagian), $oldPybmc);
        $writer = app(ApplyGlobalPybmcAction::class);
        $old = $writer->execute($oldPybmc, $actor, 'Konfigurasi global awal.');
        $draft = [...$this->draft([$employee->id], null), 'pybmc_mode' => 'global'];
        $oldToken = $reader === 'token lama' ? $this->previewToken($actor, $draft) : null;
        $new = $writer->execute($newPybmc, $actor, 'Konfigurasi global pengganti.');
        $this->assertSame($old->fresh()->getRawOriginal('created_at'), $new->fresh()->getRawOriginal('created_at'));
        $this->assertSame($newPybmc->id, $existing->steps()->where('is_final', true)->sole()->approver_employee_id);

        if ($reader === 'halaman') {
            $response = $this->actingAs($actor)->get(route('cuti.config', ['tab' => 'pybmc']))->assertOk();
            $this->assertSame($new->id, $response->viewData('globalPybmc')->id);
        } elseif ($reader === 'individual') {
            $chain = app(SaveEmployeeApprovalChainAction::class)->execute($employee, [[
                'step_type' => 'kepala_bagian', 'role_label' => 'Atasan Langsung',
                'approver_employee_id' => $employee->currentSupervisor()->kepala_bagian_id, 'is_final' => false,
            ]], $actor, null);
            $this->assertSame($newPybmc->id, $chain->steps()->where('is_final', true)->sole()->approver_employee_id);
        } elseif ($reader === 'token lama') {
            $before = $this->rawTables(['leave_approval_chains', 'leave_approval_chain_steps', 'audit_logs']);
            $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/terapkan', [...$draft, 'preview_token' => $oldToken])
                ->assertUnprocessable()->assertJsonValidationErrors('preview_token');
            $this->assertSame($before, $this->rawTables(['leave_approval_chains', 'leave_approval_chain_steps', 'audit_logs']));
        } else {
            $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)
                ->assertOk()->assertJsonPath('data.rows.0.after_steps.1.approver_employee_id', $newPybmc->id);
            $this->applyDraft($actor, $draft)->assertOk();
            $this->assertSame($newPybmc->id, LeaveApprovalChain::query()->where('employee_id', $employee->id)->sole()
                ->steps()->where('is_final', true)->sole()->approver_employee_id);
        }

        $this->assertDatabaseCount('leave_pybmc_global_config', 2);
        $this->assertSame($oldPybmc->id, $old->fresh()->approver_employee_id);
    }

    public static function globalReaders(): array
    {
        return [['halaman'], ['individual'], ['batch'], ['token lama']];
    }

    public function test_fingerprint_mendeteksi_perubahan_langkah_pada_id_chain_yang_sama_dan_assignment(): void
    {
        [$actor, $employee, $pybmc] = $this->fixture();
        $chain = $this->chain($employee, $pybmc);
        $service = app(EmployeeApprovalChainBatchService::class);
        $draft = $this->draft([$employee->id], $pybmc->id);
        $first = $service->fingerprint($service->prepare($actor, $draft));
        $chain->steps()->where('step_type', 'pybmc')->update(['role_label' => 'Label berubah']);
        $second = $service->fingerprint($service->prepare($actor, $draft));
        $this->assertNotSame($first, $second);
        $employee->supervisorAssignments()->update(['tanggal_mulai' => today()->subDays(2)]);
        $this->assertNotSame($second, $service->fingerprint($service->prepare($actor, $draft)));
    }

    public function test_permission_scope_dan_lookup_approver_luar_scope_minimum(): void
    {
        [$ignored, $employee, $pybmc] = $this->fixture();
        $actor = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $draft = $this->draft([$employee->id], $pybmc->id);
        $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)->assertForbidden();
        $this->grant('pegawai');
        $this->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)->assertOk();
        foreach ([$pybmc->id, Str::uuid()->toString()] as $foreign) {
            $draft['employee_ids'] = [$employee->id, $foreign];
            $this->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)->assertNotFound()->assertDontSee($pybmc->nip);
        }
        $this->getJson('/cuti/konfigurasi-approval/target')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $employee->id);
        $this->getJson('/cuti/konfigurasi-approval/approver?q='.urlencode($pybmc->nip))->assertOk()->assertExactJson(['data' => [[
            'id' => $pybmc->id, 'nama_lengkap' => $pybmc->nama_lengkap, 'nip' => $pybmc->nip,
        ]]]);
        $this->getJson(route('cuti.employee-lookup', ['q' => $pybmc->nip]))->assertForbidden();
        $this->get(route('cuti.config'))->assertOk()
            ->assertSee(Js::from(url('/cuti/konfigurasi-approval/target'))->toHtml(), false)
            ->assertSee(Js::from(url('/cuti/konfigurasi-approval/approver'))->toHtml(), false);
    }

    public function test_target_lookup_unit_exact_paginasi_literal_dan_pegawai_tanpa_histori(): void
    {
        [$actor, $employee, $pybmc] = $this->fixture();
        $unit = RefUnitKerja::query()->create(['nama' => 'Unit Target', 'jenis_unit' => 'bagian']);
        $child = RefUnitKerja::query()->create(['nama' => 'Subunit Target', 'jenis_unit' => 'sub_bagian', 'parent_id' => $unit->id]);
        $employee->positionHistories()->create(['nama_jabatan' => 'Analis', 'unit_kerja_id' => $unit->id, 'is_latest' => true, 'tmt_jabatan' => today()]);
        $pybmc->positionHistories()->create(['nama_jabatan' => 'Analis', 'unit_kerja_id' => $child->id, 'is_latest' => true, 'tmt_jabatan' => today()]);
        $this->chain($employee, $pybmc);
        $this->actingAs($actor)->getJson('/cuti/konfigurasi-approval/target?unit_kerja_id='.$unit->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $employee->id)->assertJsonPath('data.0.has_active_chain', true);
        $literal = Employee::factory()->create(['nama_lengkap' => 'Literal %_\\ contoh']);
        Employee::factory()->count(26)->create(['nama_lengkap' => 'Urutan Sama']);
        Employee::factory()->create(['nama_lengkap' => 'Urutan Sama', 'status_aktif' => 'Non-Aktif']);
        $this->getJson('/cuti/konfigurasi-approval/target?q='.urlencode('%_\\'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $literal->id);
        $this->getJson('/cuti/konfigurasi-approval/target?q=Urutan')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('total', 26)->assertJsonPath('last_page', 2);
        $this->getJson('/cuti/konfigurasi-approval/target?q=Urutan&page=2')->assertOk()->assertJsonCount(1, 'data');
        foreach (['unit_kerja_id=invalid', 'q[]=x', 'page=0', 'per_page=999'] as $query) {
            $this->getJson('/cuti/konfigurasi-approval/target?'.$query)->assertUnprocessable();
        }
    }

    public function test_lookup_approver_literal_bounded_no_store_dan_url_global_switch_role(): void
    {
        [$actor, $employee, $pybmc] = $this->fixture();
        $this->actingAs($actor);
        $literal = Employee::factory()->create(['nama_lengkap' => 'Cari %_\\ Nama']);
        Employee::factory()->count(16)->create(['nama_lengkap' => 'Cari Sama']);
        foreach (['/cuti/konfigurasi-approval/approver', '/cuti/pegawai/cari'] as $endpoint) {
            $this->getJson($endpoint.'?q='.urlencode('Cari %_\\'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $literal->id);
        }
        $response = $this->getJson('/cuti/konfigurasi-approval/approver?q=Cari')->assertOk()->assertJsonCount(15, 'data');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->getJson('/cuti/konfigurasi-approval/approver?q=%%')->assertOk()->assertJsonCount(0, 'data');
        foreach (['', 'a', str_repeat('a', 101), "\u{2003}\u{00a0}"] as $query) {
            $this->getJson('/cuti/konfigurasi-approval/approver?q='.urlencode($query))->assertUnprocessable()->assertJsonValidationErrors('q');
        }
        $this->grant('pegawai');
        $actor->update(['temporary_role' => 'pegawai', 'temporary_role_started_at' => now()]);
        $this->get(route('cuti.config'))->assertOk()->assertSee('pybmc-global-lookup', false)
            ->assertSee(Js::from(url('/cuti/konfigurasi-approval/approver'))->toHtml(), false);
    }

    public function test_public_result_membatasi_field_internal_dan_preview_tidak_mengunci(): void
    {
        [$actor, $employee, $pybmc] = $this->fixture();
        $service = app(EmployeeApprovalChainBatchService::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $prepared = $service->prepare($actor, $this->draft([$employee->id], $pybmc->id));
            $queries = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
        }
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|for update|pg_advisory)\b/i', $query);
        }
        $prepared['rows'][0]['private_reason'] = 'Tidak boleh dikirim';
        $prepared['rows'][0]['after_steps'][0]['private_note'] = 'Metadata internal';
        $row = $service->publicResult($prepared)['data']['rows'][0];
        $this->assertEqualsCanonicalizing(['employee_id', 'nama_lengkap', 'nip', 'supervisor', 'before_steps', 'after_steps', 'outcome', 'message'], array_keys($row));
        $this->assertArrayNotHasKey('private_note', $row['after_steps'][0]);
    }

    public function test_draft_hash_canonical_tidak_bergantung_urutan_target_key_dan_transport_token(): void
    {
        [$actor, $employee, $pybmc] = $this->fixture();
        $second = $this->assignedEmployee($pybmc);
        $draft = $this->draft([$employee->id, $second->id], $pybmc->id);
        $draft['verifiers'] = [['role_label' => 'Verifikator', 'approver_employee_id' => $employee->id]];
        $action = app(PreviewEmployeeApprovalChainsAction::class);
        $first = $action->execute($actor, $draft);
        $draft = array_reverse($draft, true);
        $draft['employee_ids'] = array_map('strtoupper', array_reverse($draft['employee_ids']));
        $draft['verifiers'][0] = ['approver_employee_id' => strtoupper($employee->id), 'role_label' => "\u{2003}Verifikator\u{00a0}"];
        $draft['_token'] = 'CSRF';
        $draft['preview_token'] = 'Transport';
        $secondResult = $action->execute($actor, $draft);
        $firstToken = json_decode(Crypt::decryptString($first['data']['preview_token']), true);
        $secondToken = json_decode(Crypt::decryptString($secondResult['data']['preview_token']), true);
        $this->assertSame($firstToken['draft_hash'], $secondToken['draft_hash']);
        $this->assertSame($firstToken['state_hash'], $secondToken['state_hash']);
        $draft['reason'] = 'Alasan yang berbeda';
        $changed = json_decode(Crypt::decryptString($action->execute($actor, $draft)['data']['preview_token']), true);
        $this->assertNotSame($firstToken['draft_hash'], $changed['draft_hash']);
        $this->assertSame($firstToken['state_hash'], $changed['state_hash']);
    }

    public function test_kabag_nonaktif_keluar_scope_dan_revoke_menutup_seluruh_boundary(): void
    {
        [$ignored, $employee, $pybmc] = $this->fixture();
        $supervisor = $employee->currentSupervisor()->kepalaBagian;
        $actor = User::factory()->kepalaBagian()->create(['employee_id' => $supervisor->id]);
        $this->grant('kepala_bagian');
        $draft = $this->draft([$employee->id], $pybmc->id);
        $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)->assertOk();
        $employee->update(['status_aktif' => 'Non-Aktif']);
        $this->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)->assertNotFound();
        $this->getJson('/cuti/konfigurasi-approval/target')->assertOk()->assertJsonCount(0, 'data');
        Role::query()->where('name', 'kepala_bagian')->sole()->permissions()->detach(Permission::query()->where('name', 'cuti.configure')->sole()->id);
        $this->postJson('/cuti/konfigurasi-approval/pratinjau', $draft)->assertForbidden();
        $this->getJson('/cuti/konfigurasi-approval/target')->assertForbidden();
        $this->getJson('/cuti/konfigurasi-approval/approver?q=ab')->assertForbidden();
        try {
            app(PreviewEmployeeApprovalChainsAction::class)->execute($actor, ['employee_ids' => ['invalid']]);
            $this->fail('Permission harus diperiksa sebelum input domain pada pemanggilan langsung.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('leave_approval_chains', 0);
    }

    public function test_action_langsung_menolak_uuid_malformed_dengan_validasi_dan_combobox_default_tetap(): void
    {
        [$actor, $employee, $pybmc] = $this->fixture();
        try {
            app(PreviewEmployeeApprovalChainsAction::class)->execute($actor, $this->draft(['invalid'], $pybmc->id));
            $this->fail('UUID malformed harus ditolak sebelum query PostgreSQL.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('employee_ids.0', $exception->errors());
        }
        $this->blade('<x-cuti.employee-combobox action="/cuti/laporan" id="lookup-default" name="employee_id" />')
            ->assertSee(Js::from(route('cuti.employee-lookup'))->toHtml(), false);
    }

    public function test_apply_membuat_dua_chain_dan_audit_tanpa_mengubah_assignment_global(): void
    {
        [$actor, $first, $pybmc] = $this->fixture();
        $second = $this->assignedEmployee(Employee::factory()->create());
        $draft = $this->draft([$second->id, $first->id], $pybmc->id);
        $beforeAssignments = DB::table('supervisor_assignments')->orderBy('id')->get()->toJson();
        $beforeGlobal = DB::table('leave_pybmc_global_config')->orderBy('id')->get()->toJson();
        $token = app(PreviewEmployeeApprovalChainsAction::class)->execute($actor, $draft)['data']['preview_token'];

        $response = $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/terapkan', [...$draft, 'preview_token' => $token])
            ->assertOk()->assertJsonPath('data.counts.create', 2);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('leave_approval_chains', 2);
        $this->assertSame(2, LeaveApprovalChain::query()->where('is_active', true)->count());
        $this->assertDatabaseCount('leave_approval_chain_steps', 4);
        $this->assertSame(2, AuditLog::query()->where('auditable_type', 'LeaveApprovalChain')->where('user_id', $actor->id)->count());
        $this->assertSame($beforeAssignments, DB::table('supervisor_assignments')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeGlobal, DB::table('leave_pybmc_global_config')->orderBy('id')->get()->toJson());
        foreach ([$first, $second] as $target) {
            $chain = LeaveApprovalChain::query()->where('employee_id', $target->id)->sole();
            $this->assertSame([$target->currentSupervisor()->kepala_bagian_id, $pybmc->id], $chain->steps()->orderBy('step_order')->pluck('approver_employee_id')->all());
        }
    }

    public function test_apply_missing_only_melewati_existing_dan_replace_identik_tidak_membuat_audit(): void
    {
        [$actor, $existing, $pybmc] = $this->fixture();
        $chain = $this->chain($existing, $pybmc);
        $new = $this->assignedEmployee($pybmc);
        $draft = $this->draft([$new->id, $existing->id], $pybmc->id);
        $this->applyDraft($actor, $draft)->assertOk()->assertJsonPath('data.counts.create', 1)->assertJsonPath('data.counts.skip_existing', 1);
        $this->assertTrue($chain->refresh()->is_active);
        $this->assertDatabaseCount('leave_approval_chains', 2);
        $this->assertDatabaseCount('audit_logs', 1);

        $third = $this->assignedEmployee($pybmc);
        $draft['employee_ids'] = [$new->id, $existing->id, $third->id];
        $draft['mode'] = 'replace';
        $draft['reason'] = 'Alasan baru tidak membuat versi fiktif.';
        $this->applyDraft($actor, $draft)->assertOk()->assertJsonPath('data.counts.create', 1)->assertJsonPath('data.counts.unchanged', 2);
        $this->assertDatabaseCount('leave_approval_chains', 3);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_apply_success_flash_hanya_memuat_counts_final_dan_bertahan_sampai_halaman_tujuan(): void
    {
        [$actor, $created, $pybmc] = $this->fixture();
        $replaced = $this->assignedEmployee($pybmc);
        $unchanged = $this->assignedEmployee($pybmc);
        $inactive = Employee::factory()->create(['status_aktif' => 'Non-Aktif']);
        $missingSupervisor = Employee::factory()->create();
        $this->chain($replaced, Employee::factory()->create());
        $this->chain($unchanged, $pybmc);
        $draft = [...$this->draft([$created->id, $replaced->id, $unchanged->id, $inactive->id, $missingSupervisor->id], $pybmc->id),
            'mode' => 'replace', 'reason' => 'Penyesuaian rangkaian terpilih.'];
        $counts = ['create' => 1, 'replace' => 1, 'unchanged' => 1, 'skip_existing' => 0,
            'skip_inactive' => 1, 'skip_supervisor' => 1, 'skip_self_required' => 0];

        $preview = $this->actingAs($actor)->postJson(route('cuti.config.batch.preview'), $draft)
            ->assertOk()->assertSessionMissing('cuti_batch_success_counts');
        $this->postJson(route('cuti.config.batch.apply'), [...$draft, 'preview_token' => $preview->json('data.preview_token')])
            ->assertOk()->assertJsonPath('data.counts', $counts)->assertSessionHas('cuti_batch_success_counts', $counts);

        // Polling bukan navigasi, sehingga pesan sukses menunggu GET halaman konfigurasi.
        $this->getJson('/api/v1/notifikasi/jumlah-belum-dibaca')->assertOk()->assertSessionHas('cuti_batch_success_counts', $counts);
        $this->get(route('cuti.config'))->assertOk()->assertViewHas('initialTab', 'pegawai')
            ->assertSee(Js::from($counts)->toHtml(), false);
        $this->get(route('cuti.config'))->assertOk()->assertDontSee(Js::from($counts)->toHtml(), false);
    }

    #[DataProvider('unsuccessfulFlashCases')]
    public function test_apply_gagal_atau_tanpa_perubahan_tidak_menyediakan_toast_sukses(string $case): void
    {
        [$actor, $target, $pybmc] = $this->fixture();
        if ($case === 'no-op') {
            $this->chain($target, $pybmc);
        }
        $draft = $this->draft([$target->id], $pybmc->id);
        $token = $this->previewToken($actor, $draft);
        if ($case === 'denied') {
            Role::query()->where('name', 'super_admin')->sole()->permissions()->detach(Permission::query()->where('name', 'cuti.configure')->sole()->id);
        } elseif ($case === 'stale') {
            $draft['reason'] = 'Alasan berubah setelah pratinjau.';
        }

        $this->actingAs($actor)->postJson(route('cuti.config.batch.apply'), [...$draft, 'preview_token' => $token])
            ->assertStatus($case === 'denied' ? 403 : 422)->assertSessionMissing('cuti_batch_success_counts');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function unsuccessfulFlashCases(): array
    {
        return [['no-op'], ['stale'], ['denied']];
    }

    public function test_apply_replace_menjaga_snapshot_pending_approved_dan_audit_per_chain(): void
    {
        [$actor, $first, $oldPybmc] = $this->fixture();
        $second = $this->assignedEmployee($oldPybmc);
        $newPybmc = Employee::factory()->create();
        $chains = [$this->chain($first, $oldPybmc), $this->chain($second, $oldPybmc)];
        foreach ([$first, $second] as $index => $target) {
            $leave = LeaveRequest::query()->create([
                'employee_id' => $target->id, 'jenis_cuti_id' => RefJenisCuti::query()->firstOrFail()->id,
                'tanggal_mulai' => today()->addWeek(), 'tanggal_selesai' => today()->addWeek(),
                'jumlah_hari_kerja' => 1, 'alasan' => 'Histori yang wajib utuh.',
                'status' => $index === 0 ? 'menunggu_approval' : 'disetujui',
            ]);
            $leave->steps()->createMany($chains[$index]->steps()->orderBy('step_order')->get()->map(fn ($s) => [
                'step_order' => $s->step_order, 'step_type' => $s->step_type, 'role_label' => $s->role_label,
                'approver_employee_id' => $s->approver_employee_id, 'is_final' => $s->is_final,
                'status' => $index === 0 ? ($s->step_order === 1 ? 'active' : 'pending') : 'approved',
                'acted_at' => $index === 0 ? null : now()->subDay(),
                'decision_note' => $index === 0 ? null : 'Keputusan resmi yang tidak boleh berubah.',
            ])->all());
        }
        $unchangedTables = ['leave_requests', 'leave_request_steps', 'supervisor_assignments', 'leave_pybmc_global_config'];
        $before = $this->rawTables($unchangedTables);
        $draft = [...$this->draft([$first->id, $second->id], $newPybmc->id), 'mode' => 'replace', 'reason' => '  Penyesuaian rangkaian pegawai.  '];
        $token = $this->previewToken($actor, $draft);
        $request = Request::create('/cuti/konfigurasi-approval/terapkan', 'POST', server: ['REMOTE_ADDR' => '203.0.113.24', 'HTTP_USER_AGENT' => 'UjiBatch/1.0']);
        $result = app(ApplyEmployeeApprovalChainsAction::class)->execute($actor, $draft, $token, $request);
        $this->assertSame(2, $result['data']['counts']['replace']);
        $this->assertSame($before, $this->rawTables($unchangedTables));
        $this->assertDatabaseCount('leave_approval_chains', 4);
        $this->assertSame(2, LeaveApprovalChain::query()->where('is_active', true)->count());
        foreach ($chains as $chain) {
            $this->assertFalse($chain->refresh()->is_active);
            $this->assertSame(2, $chain->steps()->count());
        }
        foreach (AuditLog::query()->where('auditable_type', 'LeaveApprovalChain')->get() as $audit) {
            $this->assertSame($actor->id, $audit->user_id);
            $this->assertSame('Penyesuaian rangkaian pegawai.', $audit->new_values['reason']);
            $this->assertNotEmpty($audit->old_values['steps']);
            $this->assertSame('203.0.113.24', $audit->ip_address);
            $this->assertSame('UjiBatch/1.0', $audit->user_agent);
        }
        $this->assertDatabaseCount('audit_logs', 2);
    }

    #[DataProvider('applyReasons')]
    public function test_apply_reason_matrix(int $targetCount, ?string $reason, bool $valid): void
    {
        [$actor, $first, $pybmc] = $this->fixture();
        $this->chain($first, Employee::factory()->create());
        $ids = [$first->id];
        if ($targetCount === 2) {
            $ids[] = $this->assignedEmployee($pybmc)->id;
        }
        $draft = [...$this->draft($ids, $pybmc->id), 'mode' => 'replace', 'reason' => $reason];
        if ($valid) {
            $this->applyDraft($actor, $draft)->assertOk()->assertJsonPath('data.counts.replace', 1);
            $this->assertDatabaseCount('audit_logs', $targetCount);
        } else {
            // Token sah dari draft valid tidak mengizinkan penggantian alasan yang melanggar kontrak.
            $token = $this->previewToken($actor, [...$draft, 'reason' => 'Alasan sah sebelumnya.']);
            $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/terapkan', [...$draft, 'preview_token' => $token])->assertUnprocessable();
            $this->assertDatabaseCount('leave_approval_chains', 1);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public static function applyReasons(): array
    {
        return [
            'single blank' => [1, null, true], 'multi blank' => [2, null, false],
            'unicode whitespace' => [2, "\u{2003}\u{00a0}", false],
            'four' => [2, 'abcd', false], 'five trimmed' => [2, '  abcde  ', true],
            'five hundred' => [2, str_repeat('a', 500), true], 'five hundred one' => [2, str_repeat('a', 501), false],
        ];
    }

    #[DataProvider('invalidTokens')]
    public function test_apply_menolak_token_tidak_sah_tanpa_mutasi(string $case): void
    {
        [$actor, $target, $pybmc] = $this->fixture();
        $draft = $this->draft([$target->id], $pybmc->id);
        $token = $this->previewToken($actor, $draft);
        $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        switch ($case) {
            case 'absent': $token = null;
                break;
            case 'ciphertext': $token = 'invalid-ciphertext';
                break;
            case 'json': $token = Crypt::encryptString('{');
                break;
            case 'shape': $token = Crypt::encryptString('[]');
                break;
            case 'version': $payload['version'] = 2;
                break;
            case 'actor': $payload['actor_id'] = User::factory()->superAdmin()->create()->id;
                break;
            case 'expiry': $payload['expires_at'] = now()->timestamp;
                break;
            case 'expiry type': $payload['expires_at'] = (string) (now()->timestamp + 600);
                break;
            case 'draft': $draft['reason'] = 'Alasan berubah setelah pratinjau.';
                break;
            case 'state type': $payload['state_hash'] = [];
                break;
        }
        if (in_array($case, ['version', 'actor', 'expiry', 'expiry type', 'state type'], true)) {
            $token = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        }
        $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/terapkan', [...$draft, 'preview_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->assertDatabaseCount('leave_approval_chains', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidTokens(): array
    {
        return array_map(fn ($case) => [$case], ['absent', 'ciphertext', 'json', 'shape', 'version', 'actor', 'expiry', 'expiry type', 'draft', 'state type']);
    }

    #[DataProvider('staleStates')]
    public function test_apply_menolak_state_yang_berubah(string $case): void
    {
        $this->travelTo(now()->setTime(23, 55));
        [$actor, $target, $pybmc] = $this->fixture();
        $other = Employee::factory()->create();
        $chain = $this->chain($target, $pybmc);
        $draft = [...$this->draft([$target->id], $other->id), 'mode' => 'replace'];
        $global = null;
        if ($case === 'global') {
            $global = LeavePybmcGlobalConfig::query()->create(['approver_employee_id' => $other->id, 'effective_from' => today(), 'created_by' => $actor->id]);
            $draft['pybmc_mode'] = 'global';
            $draft['pybmc_employee_id'] = null;
        }
        if ($case === 'future effective') {
            $target->supervisorAssignments()->update(['tanggal_berakhir' => today()]);
            SupervisorAssignment::query()->create(['employee_id' => $target->id, 'kepala_bagian_id' => $other->id, 'tanggal_mulai' => today()->addDay()]);
        }
        $token = $this->previewToken($actor, $draft);
        match ($case) {
            'same chain steps' => $chain->steps()->where('is_final', true)->update(['approver_role_key' => 'berubah']),
            'global writer in place' => app(ApplyGlobalPybmcAction::class)->execute($other, $actor, 'Perubahan global setelah pratinjau.'),
            'chain identity' => $chain->update(['is_active' => false]),
            'assignment' => $target->supervisorAssignments()->update(['kepala_bagian_id' => $other->id]),
            'future effective' => $this->travel(6)->minutes(),
            'lifecycle' => $target->update(['status_aktif' => 'Non-Aktif']),
            'global' => $global->update(['approver_employee_id' => $pybmc->id]),
        };
        $before = $this->rawTables(['leave_approval_chains', 'leave_approval_chain_steps', 'audit_logs']);
        $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/terapkan', [...$draft, 'preview_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->assertSame($before, $this->rawTables(['leave_approval_chains', 'leave_approval_chain_steps', 'audit_logs']));
    }

    public static function staleStates(): array
    {
        return array_map(fn ($case) => [$case], ['same chain steps', 'global writer in place', 'chain identity', 'assignment', 'future effective', 'lifecycle', 'global']);
    }

    public function test_apply_recheck_expiry_setelah_lock(): void
    {
        [$actor, $target, $pybmc] = $this->fixture();
        $draft = $this->draft([$target->id], $pybmc->id);
        $token = $this->previewToken($actor, $draft);
        // Boundary lock menggeser clock secara deterministik; blocking PostgreSQL diuji harness serial.
        $expectation = $this->mock(ApprovalChainConfigurationLockService::class)->shouldReceive('acquire');
        if (! $expectation instanceof Expectation && ! $expectation instanceof CompositeExpectation) {
            $this->fail('Mockery tidak mengembalikan ekspektasi metode.');
        }
        $expectation->once()->andReturnUsing(fn () => $this->travel(11)->minutes());
        $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/terapkan', [...$draft, 'preview_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->assertDatabaseCount('leave_approval_chains', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_apply_skip_target_invalid_dan_mempertahankan_aktor_lintas_peran(): void
    {
        [$actor, $target, $pybmc] = $this->fixture();
        $supervisor = $target->currentSupervisor()->kepalaBagian;
        $inactive = Employee::factory()->create(['status_aktif' => 'Non-Aktif']);
        $missing = Employee::factory()->create();
        SupervisorAssignment::query()->create(['employee_id' => $pybmc->id, 'kepala_bagian_id' => $supervisor->id, 'tanggal_mulai' => today()->subDay()]);
        $draft = $this->draft([$target->id, $inactive->id, $missing->id, $pybmc->id], $pybmc->id);
        $draft['verifiers'] = array_map(fn ($employee) => ['approver_employee_id' => $employee->id, 'role_label' => 'Verifikator'], [$target, $supervisor, $pybmc]);
        $this->applyDraft($actor, $draft)->assertOk()->assertJsonPath('data.counts.create', 1)
            ->assertJsonPath('data.counts.skip_inactive', 1)->assertJsonPath('data.counts.skip_supervisor', 1)->assertJsonPath('data.counts.skip_self_required', 1);
        $this->assertDatabaseCount('leave_approval_chains', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $steps = LeaveApprovalChain::query()->sole()->steps()->orderBy('step_order')->get();
        $this->assertSame(['verifier', 'verifier', 'verifier', 'kepala_bagian', 'pybmc'], $steps->pluck('step_type')->all());
        $this->assertSame([$target->id, $supervisor->id, $pybmc->id, $supervisor->id, $pybmc->id], $steps->pluck('approver_employee_id')->all());
        $this->assertSame([false, false, false, false, true], $steps->pluck('is_final')->all());
    }

    public function test_apply_common_approver_nonaktif_sejak_preview_tidak_menulis_target_lain(): void
    {
        [$actor, $target, $pybmc] = $this->fixture();
        $draft = $this->draft([$target->id], $pybmc->id);
        $token = $this->previewToken($actor, $draft);
        $pybmc->update(['status_aktif' => 'Non-Aktif']);
        $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/terapkan', [...$draft, 'preview_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('pybmc_employee_id');
        $this->assertDatabaseCount('leave_approval_chains', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_apply_revoke_scope_dan_direct_action_tetap_fail_closed(): void
    {
        [$ignored, $target, $pybmc] = $this->fixture();
        $supervisor = $target->currentSupervisor()->kepalaBagian;
        $actor = User::factory()->kepalaBagian()->create(['employee_id' => $supervisor->id]);
        $this->grant('kepala_bagian');
        $draft = $this->draft([$target->id], $pybmc->id);
        $token = $this->previewToken($actor, $draft);
        $target->supervisorAssignments()->update(['kepala_bagian_id' => $pybmc->id, 'supervisor_id' => $pybmc->id]);
        $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/terapkan', [...$draft, 'preview_token' => $token])->assertNotFound()->assertDontSee($target->nip);
        Role::query()->where('name', 'kepala_bagian')->sole()->permissions()->detach(Permission::query()->where('name', 'cuti.configure')->sole()->id);
        $this->postJson('/cuti/konfigurasi-approval/terapkan', [...$draft, 'preview_token' => $token])->assertForbidden();
        try {
            app(ApplyEmployeeApprovalChainsAction::class)->execute($actor, ['employee_ids' => ['invalid']], 'invalid');
            $this->fail('Authority harus ditolak sebelum validasi token atau UUID.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('leave_approval_chains', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_apply_replay_dua_aktor_dan_nol_target_eligible_tidak_menggandakan_mutasi(): void
    {
        [$actor, $target, $pybmc] = $this->fixture();
        $otherActor = User::factory()->superAdmin()->create();
        $draft = $this->draft([$target->id], $pybmc->id);
        $token = $this->previewToken($actor, $draft);
        $otherToken = $this->previewToken($otherActor, $draft);
        $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/terapkan', [...$draft, 'preview_token' => $token])->assertOk();
        $this->postJson('/cuti/konfigurasi-approval/terapkan', [...$draft, 'preview_token' => $token])->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->actingAs($otherActor)->postJson('/cuti/konfigurasi-approval/terapkan', [...$draft, 'preview_token' => $otherToken])->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->applyDraft($actor, $draft)->assertUnprocessable()->assertJsonValidationErrors('employee_ids');
        $this->assertDatabaseCount('leave_approval_chains', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_apply_kegagalan_audit_target_kedua_rollback_seluruh_batch(): void
    {
        [$actor, $first, $pybmc] = $this->fixture();
        $second = $this->assignedEmployee($pybmc);
        $this->chain($first, Employee::factory()->create());
        $this->chain($second, Employee::factory()->create());
        $draft = [...$this->draft([$first->id, $second->id], $pybmc->id), 'mode' => 'replace', 'reason' => 'Penggantian harus seluruhnya atomik.'];
        $token = $this->previewToken($actor, $draft);
        $ids = [$first->id, $second->id];
        sort($ids, SORT_STRING);
        $before = $this->rawTables(['leave_approval_chains', 'leave_approval_chain_steps', 'audit_logs']);
        // Trigger menolak audit UUID target terakhir, sesudah writer pertama benar-benar bekerja.
        DB::unprepared(<<<SQL
            CREATE FUNCTION uji_tolak_audit_batch() RETURNS trigger AS \$\$
            BEGIN
                IF NEW.auditable_type = 'LeaveApprovalChain'
                    AND NEW.new_values->>'employee_id' = '{$ids[1]}' THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM audit_logs
                        WHERE auditable_type = 'LeaveApprovalChain'
                            AND new_values->>'employee_id' = '{$ids[0]}'
                    ) THEN
                        RAISE EXCEPTION 'Urutan write belum membuktikan audit target pertama';
                    END IF;
                    RAISE EXCEPTION 'Audit target kedua ditolak';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
            CREATE TRIGGER uji_tolak_audit_batch BEFORE INSERT ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION uji_tolak_audit_batch();
        SQL);
        try {
            try {
                app(ApplyEmployeeApprovalChainsAction::class)->execute($actor, $draft, $token);
                $this->fail('Kegagalan audit harus menggagalkan seluruh transaksi.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Audit target kedua ditolak', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS uji_tolak_audit_batch ON audit_logs; DROP FUNCTION IF EXISTS uji_tolak_audit_batch();');
        }
        $this->assertSame($before, $this->rawTables(['leave_approval_chains', 'leave_approval_chain_steps', 'audit_logs']));
    }

    public function test_apply_menerima_target_dan_approver_aktif_khusus_dengan_atasan_efektif(): void
    {
        [$actor, $target, $pybmc] = $this->fixture();
        $supervisor = $target->currentSupervisor()->kepalaBagian;
        $staleSupervisor = Employee::factory()->create();
        $target->update(['kepala_bagian_id' => $staleSupervisor->id]);
        $verifier = Employee::factory()->create();
        $status = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->sole();
        DB::table('ref_status_pegawai')->where('id', $status->id)->update(['kelompok' => ' aktif/KHUSUS ']);
        DB::table('employees')->whereIn('id', [$target->id, $supervisor->id, $verifier->id, $pybmc->id])
            ->update(['status_pegawai_id' => $status->id, 'status_aktif' => 'Tugas Belajar']);
        $draft = [...$this->draft([$target->id], $pybmc->id), 'verifiers' => [
            ['approver_employee_id' => $verifier->id, 'role_label' => 'Verifikator'],
        ]];

        $this->applyDraft($actor, $draft)->assertOk()->assertJsonPath('data.counts.create', 1);
        $chain = LeaveApprovalChain::query()->where('employee_id', $target->id)->sole();
        $this->assertSame([$verifier->id, $supervisor->id, $pybmc->id], $chain->steps()->orderBy('step_order')->pluck('approver_employee_id')->all());
        $this->assertDatabaseMissing('leave_approval_chain_steps', ['leave_approval_chain_id' => $chain->id, 'approver_employee_id' => $staleSupervisor->id]);
    }

    public function test_apply_mengganti_metadata_lama_dengan_metadata_kanonis_composer(): void
    {
        [$actor, $target, $pybmc] = $this->fixture();
        $old = $this->chain($target, $pybmc);
        $old->steps()->where('step_type', 'pybmc')->update(['approver_role_key' => 'metadata_lama']);
        $draft = [...$this->draft([$target->id], $pybmc->id), 'mode' => 'replace'];

        $this->applyDraft($actor, $draft)->assertOk()->assertJsonPath('data.counts.replace', 1);
        $this->assertFalse($old->refresh()->is_active);
        $this->assertSame('metadata_lama', $old->steps()->where('step_type', 'pybmc')->sole()->approver_role_key);
        $active = LeaveApprovalChain::query()->where('employee_id', $target->id)->where('is_active', true)->sole();
        $this->assertSame([null, null], $active->steps()->orderBy('step_order')->pluck('approver_role_key')->all());
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_endpoint_massal_lama_tidak_lagi_memutasi_data(): void
    {
        [$actor, $target, $pybmc] = $this->fixture();
        $this->chain($target, $pybmc);
        $tables = ['leave_approval_chains', 'leave_approval_chain_steps', 'audit_logs', 'approval_configs', 'supervisor_assignments', 'leave_pybmc_global_config'];
        $before = $this->rawTables($tables);

        foreach (['/cuti/konfigurasi-approval/backfill', '/cuti/konfigurasi-approval/unit'] as $url) {
            $response = $this->actingAs($actor)->postJson($url, [
                'source_employee_id' => $target->id, 'unit_kerja_id' => (string) Str::uuid(),
                'backfill_reason' => 'Permintaan jalur lama.', 'template_reason' => 'Permintaan jalur lama.',
            ]);
            $this->assertContains($response->status(), [404, 405]);
            $this->assertSame($before, $this->rawTables($tables));
        }
    }

    private function previewToken(User $actor, array $draft): string
    {
        return app(PreviewEmployeeApprovalChainsAction::class)->execute($actor, $draft)['data']['preview_token'];
    }

    private function applyDraft(User $actor, array $draft): TestResponse
    {
        return $this->actingAs($actor)->postJson('/cuti/konfigurasi-approval/terapkan', [...$draft, 'preview_token' => $this->previewToken($actor, $draft)]);
    }

    /** Snapshot mentah berurutan mempertahankan tipe/metadata, bukan hanya jumlah record. */
    private function rawTables(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $query = DB::table($table);
            if ($table === 'leave_request_steps') {
                $query->orderBy('step_order');
            }
            $result[$table] = $query->orderBy('id')->get()->toJson();
        }

        return $result;
    }

    private function fixture(): array
    {
        return [User::factory()->superAdmin()->create(), $this->assignedEmployee(Employee::factory()->create()), Employee::factory()->create()];
    }

    private function draft(array $ids, ?string $pybmc): array
    {
        return ['employee_ids' => $ids, 'mode' => 'missing_only', 'verifiers' => [], 'pybmc_mode' => 'custom', 'pybmc_employee_id' => $pybmc, 'reason' => null];
    }

    private function chain(Employee $employee, Employee $pybmc): LeaveApprovalChain
    {
        $chain = LeaveApprovalChain::query()->create(['employee_id' => $employee->id, 'name' => 'Konfigurasi uji', 'is_active' => true, 'effective_from' => today()]);
        foreach ([['kepala_bagian', 'Kepala Bagian', $employee->currentSupervisor()->kepala_bagian_id, false], ['pybmc', 'PYBMC', $pybmc->id, true]] as $index => $step) {
            $chain->steps()->create(['step_order' => $index + 1, 'step_type' => $step[0], 'role_label' => $step[1], 'approver_employee_id' => $step[2], 'approver_role_key' => null, 'is_final' => $step[3]]);
        }

        return $chain;
    }

    private function grant(string $role): void
    {
        Role::query()->where('name', $role)->sole()->permissions()->syncWithoutDetaching([Permission::query()->where('name', 'cuti.configure')->sole()->id]);
    }

    private function assignedEmployee(Employee $supervisor): Employee
    {
        $employee = Employee::factory()->create();
        SupervisorAssignment::query()->create([
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisor->id,
            'supervisor_id' => $supervisor->id,
            'tanggal_mulai' => today()->subDay()->toDateString(),
        ]);

        return $employee;
    }
}
