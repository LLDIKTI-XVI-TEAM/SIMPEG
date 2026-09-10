<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Membuktikan state navigasi dan tujuan redirect konfigurasi cuti tetap internal
 * tanpa memperluas permission, scope pegawai, atau kontrak error non-validasi.
 */
class CutiConfigNavigationHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_get_menormalisasi_tab_dan_tahap_presentasi_tanpa_error(): void
    {
        $actor = User::factory()->superAdmin()->create();

        $valid = $this->actingAs($actor)->get(route('cuti.config', [
            'tab' => 'rangkaian',
            'step' => 'tinjau',
        ]));

        $valid->assertOk();
        $this->assertSame('rangkaian', $valid->viewData('initialTab'));
        $this->assertSame('tinjau', $valid->viewData('initialStep'));

        foreach ([
            ['tab' => 'asing', 'step' => 'asing'],
            ['tab' => ['riwayat'], 'step' => ['pilih']],
        ] as $query) {
            $fallback = $this->get(route('cuti.config', $query));
            $fallback->assertOk();
            $this->assertSame('pegawai', $fallback->viewData('initialTab'));
            $this->assertSame('susun', $fallback->viewData('initialStep'));
        }
    }

    public function test_tab_riwayat_ditentukan_permission_walaupun_audit_kosong(): void
    {
        $actor = User::factory()->superAdmin()->create();

        $response = $this->actingAs($actor)->get(route('cuti.config', ['tab' => 'riwayat']));

        $response->assertOk();
        $this->assertSame([], $response->viewData('auditRows'));
        $this->assertTrue($response->viewData('canViewAudit'));
        $this->assertSame('riwayat', $response->viewData('initialTab'));
    }

    public function test_tab_tidak_terjangkau_jatuh_ke_pegawai(): void
    {
        $identity = Employee::factory()->create();
        $actor = User::factory()->pegawai()->create(['employee_id' => $identity->id]);
        $this->grant('pegawai', 'cuti.configure');

        foreach (['pybmc', 'riwayat'] as $tab) {
            $response = $this->actingAs($actor)->get(route('cuti.config', ['tab' => $tab]));
            $response->assertOk()
                ->assertSee('PYBMC global saat ini')
                ->assertDontSee('Perubahan tercatat terakhir');
            $this->assertSame('pegawai', $response->viewData('initialTab'));
            $this->assertFalse($response->viewData('canViewAudit'));
        }
    }

    public function test_old_input_global_dan_atasan_memulihkan_label_kandidat_aktif(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();
        $candidate = Employee::factory()->create([
            'nama_lengkap' => 'Kandidat Lama Aktif',
            'nip' => '198001012026090901',
        ]);

        $response = $this->actingAs($actor)
            ->withSession(['_old_input' => [
                'approver_employee_id' => $candidate->id,
                'kepala_bagian_id' => $candidate->id,
            ]])
            ->get(route('cuti.config', ['employee_id' => $employee->id]));

        $response->assertOk();
        $expected = 'Kandidat Lama Aktif (198001012026090901)';
        $this->assertSame($expected, $response->viewData('oldGlobalPybmcLabel'));
        $this->assertSame($expected, $response->viewData('oldKepalaBagianLabel'));
    }

    public function test_old_input_tidak_sah_atau_tidak_berizin_tidak_membocorkan_label(): void
    {
        $identity = Employee::factory()->create();
        $candidate = Employee::factory()->create(['nama_lengkap' => 'Label Privat Tidak Boleh Bocor']);
        $actor = User::factory()->pegawai()->create(['employee_id' => $identity->id]);
        $this->grant('pegawai', 'cuti.configure');

        $response = $this->actingAs($actor)
            ->withSession(['_old_input' => [
                'approver_employee_id' => $candidate->id,
                'kepala_bagian_id' => ['bukan-string'],
            ]])
            ->get(route('cuti.config', ['employee_id' => $identity->id]));

        $response->assertOk();
        $this->assertSame('', $response->viewData('oldGlobalPybmcLabel'));
        $this->assertSame('', $response->viewData('oldKepalaBagianLabel'));
        $response->assertDontSee('Label Privat Tidak Boleh Bocor');
    }

    public function test_old_input_array_admin_dinormalisasi_tanpa_error_render_atau_query_uuid(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();

        $response = $this->actingAs($actor)
            ->withSession(['_old_input' => [
                'approver_employee_id' => ['bukan-scalar'],
                'kepala_bagian_id' => ['bukan-scalar'],
            ]])
            ->get(route('cuti.config', ['employee_id' => $employee->id]));

        $response->assertOk();
        $this->assertSame('', $response->viewData('oldGlobalPybmcLabel'));
        $this->assertSame('', $response->viewData('oldKepalaBagianLabel'));
        $response->assertDontSee('bukan-scalar');
    }

    public function test_validasi_chain_individual_kembali_ke_panel_dan_pegawai_dari_route(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();
        $supervisor = Employee::factory()->create();
        SupervisorAssignment::query()->create([
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisor->id,
            'tanggal_mulai' => today()->subDay(),
        ]);

        $response = $this->actingAs($actor)
            ->from('/halaman-yang-tidak-dipercaya')
            ->post(route('cuti.config.employee-chain.store', $employee), ['steps' => []])
            ->assertRedirect(route('cuti.config', [
                'tab' => 'pegawai',
                'employee_id' => $employee->id,
            ]))
            ->assertSessionHasErrors('steps');

        $page = $this->get($response->headers->get('Location'))->assertOk();
        $this->assertRestoredEditor($page, 'pegawai');
        $this->assertTrue(str_contains($page->getContent(), 'data-config-error-summary tabindex="-1"'), 'Error rangkaian perlu fallback fokus di dalam form.');
    }

    public function test_validation_exception_action_chain_kembali_ke_panel_individual(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();
        $supervisor = Employee::factory()->create();
        $duplicateVerifier = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        SupervisorAssignment::query()->create([
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisor->id,
            'tanggal_mulai' => today()->subDay(),
        ]);

        $response = $this->actingAs($actor)
            ->from('/halaman-yang-tidak-dipercaya')
            ->post(route('cuti.config.employee-chain.store', $employee), [
                'steps' => [
                    ['step_type' => 'verifier', 'role_label' => 'Verifikator 1', 'approver_employee_id' => $duplicateVerifier->id],
                    ['step_type' => 'verifier', 'role_label' => 'Verifikator 2', 'approver_employee_id' => $duplicateVerifier->id],
                    ['step_type' => 'kepala_bagian', 'role_label' => 'Atasan Langsung', 'approver_employee_id' => $supervisor->id],
                    ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id],
                ],
            ]);

        $response->assertRedirect(route('cuti.config', [
            'tab' => 'pegawai',
            'employee_id' => $employee->id,
        ]));
        $response->assertSessionHasErrors('steps');

        $page = $this->get($response->headers->get('Location'))->assertOk();
        $this->assertRestoredEditor($page, 'pegawai');
        $this->assertTrue(str_contains($page->getContent(), 'data-config-error-summary tabindex="-1"'), 'Error Action perlu fallback fokus di dalam form.');
    }

    public function test_validation_exception_action_chain_mempertahankan_kontrak_json_422(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();
        $supervisor = Employee::factory()->create();
        $duplicateVerifier = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        SupervisorAssignment::query()->create([
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisor->id,
            'tanggal_mulai' => today()->subDay(),
        ]);

        $this->actingAs($actor)
            ->postJson(route('cuti.config.employee-chain.store', $employee), [
                'steps' => [
                    ['step_type' => 'verifier', 'role_label' => 'Verifikator 1', 'approver_employee_id' => $duplicateVerifier->id],
                    ['step_type' => 'verifier', 'role_label' => 'Verifikator 2', 'approver_employee_id' => $duplicateVerifier->id],
                    ['step_type' => 'kepala_bagian', 'role_label' => 'Atasan Langsung', 'approver_employee_id' => $supervisor->id],
                    ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('steps');
    }

    public function test_validasi_global_kembali_ke_tab_pybmc(): void
    {
        $actor = User::factory()->superAdmin()->create();

        $this->actingAs($actor)
            ->from('/halaman-yang-tidak-dipercaya')
            ->post(route('cuti.config.pybmc-global'), [])
            ->assertRedirect(route('cuti.config', ['tab' => 'pybmc']))
            ->assertSessionHasErrors(['approver_employee_id', 'pybmc_reason']);
    }

    public function test_validasi_global_memulihkan_marker_editor_dengan_isian_lama(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $candidate = Employee::factory()->create(['nama_lengkap' => 'PYBMC Draft Invalid']);

        $response = $this->actingAs($actor)->post(route('cuti.config.pybmc-global'), [
            'approver_employee_id' => $candidate->id,
            'pybmc_reason' => 'abc',
        ]);

        $response->assertRedirect(route('cuti.config', ['tab' => 'pybmc']))
            ->assertSessionHasErrors('pybmc_reason');

        $page = $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('PYBMC Draft Invalid')
            ->assertSee('>abc</textarea>', false);
        $this->assertRestoredEditor($page, 'pybmc');
        $this->assertDatabaseCount('leave_pybmc_global_config', 0);
    }

    public function test_validasi_global_memakai_label_field_bahasa_indonesia(): void
    {
        $actor = User::factory()->superAdmin()->create();

        $this->actingAs($actor)->post(route('cuti.config.pybmc-global'), [
            'approver_employee_id' => 'bukan-uuid',
            'pybmc_reason' => 'abc',
        ])->assertSessionHasErrors(['approver_employee_id', 'pybmc_reason']);

        $errors = session('errors');
        $this->assertStringContainsString('PYBMC Global', $errors->first('approver_employee_id'));
        $this->assertStringContainsString('Alasan PYBMC Global', $errors->first('pybmc_reason'));
        $this->assertStringNotContainsString('pybmc reason', $errors->first('pybmc_reason'));
    }

    public function test_validasi_inline_memulihkan_marker_atasan_meski_pilihan_lama_null(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();

        $response = $this->actingAs($actor)
            ->withSession(['_token' => 'test-token'])
            ->post(route('pegawai.assign-atasan', $employee->id), [
                '_token' => 'test-token',
                'kepala_bagian_id' => null,
                'effective_date' => 'tanggal-tidak-valid',
                'redirect_to' => 'cuti-config',
            ]);

        $response->assertRedirect(route('cuti.config', ['tab' => 'pegawai', 'employee_id' => $employee->id]))
            ->assertSessionHasErrors('effective_date');

        $page = $this->get($response->headers->get('Location'))->assertOk();
        $this->assertRestoredEditor($page, 'atasan');
        $this->assertDatabaseCount('supervisor_assignments', 0);
    }

    #[DataProvider('nonRestorableInputs')]
    public function test_marker_editor_tidak_menganggap_form_bersih_atau_tidak_terjangkau_sebagai_draft(
        array $oldInput,
        array $messages,
        bool $scoped,
    ): void {
        $identity = Employee::factory()->create();
        $actor = $scoped
            ? User::factory()->pegawai()->create(['employee_id' => $identity->id])
            : User::factory()->superAdmin()->create();
        if ($scoped) {
            $this->grant('pegawai', 'cuti.configure');
        }

        $page = $this->actingAs($actor)
            ->withSession([
                '_old_input' => $oldInput,
                'errors' => (new ViewErrorBag)->put('default', new MessageBag($messages)),
            ])
            ->get(route('cuti.config', $scoped ? ['employee_id' => $identity->id] : []))
            ->assertOk();
        $this->assertRestoredEditor($page, null);
    }

    public static function nonRestorableInputs(): array
    {
        return [
            'halaman bersih' => [[], [], false],
            'old input tanpa error' => [['pybmc_reason' => 'abc'], [], false],
            'error tanpa old input' => [[], ['pybmc_reason' => 'Alasan belum sah.'], false],
            'error pencarian bukan draft' => [['search' => 'abc'], ['search' => 'Pencarian belum sah.'], false],
            'pegawai tanpa editor target' => [['steps' => []], ['steps' => 'Rangkaian belum sah.'], false],
            'global tidak dirender' => [['pybmc_reason' => 'abc'], ['pybmc_reason' => 'Alasan belum sah.'], true],
            'inline tidak dirender' => [['kepala_bagian_id' => null], ['kepala_bagian_id' => 'Atasan belum sah.'], true],
        ];
    }

    private function assertRestoredEditor(TestResponse $response, ?string $editor): void
    {
        $marker = $editor === null ? 'null' : "'{$editor}'";
        $this->assertTrue(
            str_contains($response->getContent(), 'restoredEditor: '.$marker),
            'Marker editor yang dipulihkan harus '.$marker.'.',
        );
    }

    private function grant(string $roleName, string $permissionName): void
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $permission = Permission::query()->where('name', $permissionName)->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }
}
