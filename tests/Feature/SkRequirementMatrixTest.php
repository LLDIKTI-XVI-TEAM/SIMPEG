<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\RefJenisPegawai;
use App\Models\Role;
use App\Models\SkRequirement;
use App\Models\User;
use App\Services\Documents\SkRequirementMatrixVersionService;
use App\Support\Documents\SkCompleteness;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\SkRequirementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkRequirementMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(SkRequirementSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_admin_kepegawaian_can_see_restored_sk_requirement_matrix_design(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('data-pegawai'))
            ->assertOk()
            ->assertSee('id="sk-requirement-btn"', false)
            ->assertSee('SK Wajib per Jenis Pegawai')
            ->assertSee('max-w-3xl', false)
            ->assertSee('PPPK tidak memiliki konfigurasi awal dan dapat dikustom')
            ->assertSee('x-model="skMatrixDraft[typeId]"', false)
            ->assertSee('@keydown.escape.window="closeSkRequirementModal()"', false)
            ->assertDontSee("document.querySelectorAll('#sk-requirement-modal input[type=checkbox]')", false)
            ->assertSee('Simpan Matriks');
    }

    public function test_role_without_permission_cannot_see_or_update_matrix(): void
    {
        $pimpinan = User::factory()->pimpinan()->create();

        $this->actingAs($pimpinan)
            ->get(route('pimpinan.pegawai.index'))
            ->assertOk()
            ->assertDontSee('id="sk-requirement-btn"', false)
            ->assertDontSee('SK Wajib per Jenis Pegawai');

        $this->actingAs($pimpinan)
            ->postJson(route('sk-requirements.update'), ['matrix' => $this->defaultMatrix()])
            ->assertForbidden();
    }

    public function test_permission_is_enforced_even_when_admin_role_allows(): void
    {
        $permission = Permission::where('name', 'sk_requirements.manage')->firstOrFail();
        Role::where('name', 'admin_kepegawaian')->firstOrFail()
            ->permissions()
            ->detach($permission->id);
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($admin)
            ->get(route('data-pegawai'))
            ->assertOk()
            ->assertDontSee('id="sk-requirement-btn"', false);

        $this->actingAs($admin)
            ->postJson(route('sk-requirements.update'), ['matrix' => $this->defaultMatrix()])
            ->assertForbidden();
    }

    public function test_admin_can_save_custom_matrix_atomically_with_audit_context(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $matrix = $this->defaultMatrix();
        $pns = RefJenisPegawai::where('nama', 'PNS')->firstOrFail();
        $matrix[$pns->id] = ['sk_pangkat', 'sk_jabatan'];
        $oldVersion = app(SkRequirementMatrixVersionService::class)->current();

        $response = $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.24'])
            ->withHeader('User-Agent', 'PengujiMatriks/1.0')
            ->postJson(route('sk-requirements.update'), ['matrix' => $matrix]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Matriks SK wajib per jenis pegawai berhasil diperbarui.')
            ->assertJsonPath('version', app(SkRequirementMatrixVersionService::class)->current());
        $this->assertSame(['sk_jabatan', 'sk_pangkat'], $response->json('matrix')[$pns->id]);
        $this->assertNotSame($oldVersion, $response->json('version'));

        foreach (SkCompleteness::poolKeys() as $skKey) {
            $this->assertDatabaseHas('sk_requirements', [
                'jenis_pegawai_id' => $pns->id,
                'sk_key' => $skKey,
                'is_wajib' => in_array($skKey, $matrix[$pns->id], true),
            ]);
        }

        $audit = AuditLog::query()
            ->where('event', 'CONFIG_UPDATE')
            ->where('auditable_type', 'SkRequirement')
            ->sole();

        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('203.0.113.24', $audit->ip_address);
        $this->assertSame('PengujiMatriks/1.0', $audit->user_agent);
        $this->assertSame(['sk_jabatan', 'sk_pangkat'], $audit->new_values['matrix'][$pns->id]);
    }

    public function test_employee_lists_version_cache_and_listen_for_cross_tab_matrix_changes(): void
    {
        $version = app(SkRequirementMatrixVersionService::class)->current();

        foreach ([
            [User::factory()->adminKepegawaian()->create(), route('data-pegawai')],
            [User::factory()->pimpinan()->create(), route('pimpinan.pegawai.index')],
        ] as [$user, $route]) {
            $this->actingAs($user)
                ->get($route)
                ->assertOk()
                ->assertSee($version, false)
                ->assertSee('pegawai_mv${this.skRequirementVersion}_pp', false)
                ->assertSee("skRequirementStorageKey: 'simpeg:sk-requirements:version'", false)
                ->assertSee("window.addEventListener('storage', this.skRequirementStorageListener);", false)
                ->assertSee("window.removeEventListener('storage', this.skRequirementStorageListener);", false);
        }

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('data-pegawai'))
            ->assertOk()
            ->assertSee('localStorage.removeItem(this.skRequirementStorageKey);', false)
            ->assertSee('localStorage.setItem(this.skRequirementStorageKey, version);', false);
    }

    public function test_unchanged_matrix_does_not_create_redundant_audit(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $version = app(SkRequirementMatrixVersionService::class)->current();

        $this->actingAs($admin)
            ->postJson(route('sk-requirements.update'), ['matrix' => $this->defaultMatrix()])
            ->assertOk()
            ->assertJsonPath('version', $version);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_admin_can_activate_custom_pppk_matrix_without_default(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $matrix = $this->defaultMatrix();
        $pppk = RefJenisPegawai::where('nama', 'PPPK')->firstOrFail();
        $matrix[$pppk->id] = ['sk_pengangkatan'];

        $this->actingAs($admin)
            ->postJson(route('sk-requirements.update'), ['matrix' => $matrix])
            ->assertOk()
            ->assertJsonPath('matrix.'.$pppk->id.'.0', 'sk_pengangkatan');

        $this->assertDatabaseHas('sk_requirements', [
            'jenis_pegawai_id' => $pppk->id,
            'sk_key' => 'sk_pengangkatan',
            'is_wajib' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CONFIG_UPDATE',
            'auditable_type' => 'SkRequirement',
        ]);
    }

    public function test_seeder_preserves_custom_pppk_matrix(): void
    {
        $pppk = RefJenisPegawai::where('nama', 'PPPK')->firstOrFail();
        SkRequirement::query()->create([
            'jenis_pegawai_id' => $pppk->id,
            'sk_key' => 'sk_pengangkatan',
            'is_wajib' => true,
        ]);

        $this->seed(SkRequirementSeeder::class);

        $this->assertDatabaseHas('sk_requirements', [
            'jenis_pegawai_id' => $pppk->id,
            'sk_key' => 'sk_pengangkatan',
            'is_wajib' => true,
        ]);
    }

    public function test_non_array_matrix_is_rejected_as_validation_error(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($admin)
            ->postJson(route('sk-requirements.update'), ['matrix' => 'bukan-array'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('matrix');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_non_uuid_and_indexed_matrix_keys_are_rejected_before_database_query(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        foreach ([['not-a-uuid' => []], [[]]] as $matrix) {
            $this->actingAs($admin)
                ->postJson(route('sk-requirements.update'), ['matrix' => $matrix])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('matrix');
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_duplicate_category_is_rejected_only_within_the_same_employee_type(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $matrix = $this->defaultMatrix();
        $pns = RefJenisPegawai::where('nama', 'PNS')->firstOrFail();
        $matrix[$pns->id] = ['sk_pangkat', 'sk_pangkat'];

        $this->actingAs($admin)
            ->postJson(route('sk-requirements.update'), ['matrix' => $matrix])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('matrix.'.$pns->id);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /** @return array<string, list<string>> */
    private function defaultMatrix(): array
    {
        return RefJenisPegawai::query()
            ->get(['id', 'nama'])
            ->mapWithKeys(fn (RefJenisPegawai $type): array => [
                $type->id => in_array($type->nama, ['PNS', 'CPNS'], true)
                    ? SkCompleteness::poolKeys()
                    : [],
            ])
            ->all();
    }
}
