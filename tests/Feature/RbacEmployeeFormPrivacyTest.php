<?php

namespace Tests\Feature;

use App\Actions\Employees\PrepareEmployeeEditFormDataAction;
use App\Livewire\Admin\Pegawai\Edit;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RbacEmployeeFormPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceSeeder::class, RbacSeeder::class]);
    }

    public static function delegatedRoles(): array
    {
        return [['pimpinan'], ['kepala_bagian'], ['pegawai']];
    }

    #[DataProvider('delegatedRoles')]
    public function test_form_delegated_tidak_mengirim_identitas_sensitif_dan_submit_mempertahankannya(string $role): void
    {
        [$actor, $employee] = $this->delegatedFixture($role);
        $response = $this->actingAs($actor)->get(route('rbac.pegawai.edit', $employee))->assertOk();
        $response->assertDontSee('7171010101010001')->assertDontSee('7171010101019999');
        $response->assertDontSee('name="nik"', false)->assertDontSee('name="no_kk"', false);
        $presented = app(PrepareEmployeeEditFormDataAction::class)->execute($employee->id, true)['p'];
        $this->assertArrayNotHasKey('nik', $presented->getAttributes());
        $this->assertArrayNotHasKey('no_kk', $presented->getAttributes());
        $this->assertArrayNotHasKey('nik_hash', $presented->getAttributes());

        $destination = $this->formDestination($response->getContent());
        $this->assertSame(route('rbac.pegawai.update', $employee), $destination);
        $saved = $this->post($destination, ['nama_lengkap' => 'Nama Sesudah Edit']);
        $saved->assertSessionHasNoErrors()->assertSessionHas('success')->assertRedirect(route('dashboard'));
        $saved->assertSessionMissing('edited_employee_data');
        $employee->refresh();
        $this->assertSame('Nama Sesudah Edit', $employee->nama_lengkap);
        $this->assertSame('7171010101010001', $employee->nik);
        $this->assertSame('7171010101019999', $employee->no_kk);
        $this->followingRedirects()->get($saved->headers->get('Location'))->assertOk();
    }

    public static function forbiddenIdentifiers(): array
    {
        return [['nik', '7171010101010002'], ['no_kk', '7171010101010002'], ['nik', null], ['no_kk', null]];
    }

    #[DataProvider('forbiddenIdentifiers')]
    public function test_payload_identitas_sensitif_delegated_ditolak_termasuk_pengosongan(string $field, ?string $value): void
    {
        [$actor, $employee] = $this->delegatedFixture('pimpinan');
        $this->actingAs($actor)->from(route('rbac.pegawai.edit', $employee))
            ->post(route('rbac.pegawai.update', $employee), ['nama_lengkap' => 'Tidak Disimpan', $field => $value])
            ->assertSessionHasErrors($field);
        $employee->refresh();
        $this->assertSame('Pegawai Target Privasi', $employee->nama_lengkap);
        $this->assertSame('7171010101010001', $employee->nik);
        $this->assertSame('7171010101019999', $employee->no_kk);
    }

    #[DataProvider('delegatedRoles')]
    public function test_form_tambah_delegated_submit_ke_surface_yang_dapat_diakses(string $role): void
    {
        $this->grant($role, 'employees.create');
        $actor = User::factory()->create(['role' => $role]);
        $response = $this->actingAs($actor)->get(route('rbac.pegawai.create'))->assertOk();
        $destination = $this->formDestination($response->getContent());
        $this->assertSame(route('rbac.pegawai.store'), $destination);
        $saved = $this->post($destination, ['nama_lengkap' => 'Pegawai Baru Delegated']);
        $saved->assertSessionHasNoErrors()->assertSessionHas('success')->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('employees', ['nama_lengkap' => 'Pegawai Baru Delegated']);
        $this->followingRedirects()->get($saved->headers->get('Location'))->assertOk();

        Role::where('name', $role)->firstOrFail()->permissions()->detach(Permission::where('name', 'employees.create')->firstOrFail()->id);
        $this->get(route('rbac.pegawai.create'))->assertForbidden();
        $this->post($destination, ['nama_lengkap' => 'Tidak Disimpan'])->assertForbidden();
    }

    public function test_scope_dan_pencabutan_permission_edit_tetap_ditegakkan(): void
    {
        [$actor, $employee] = $this->delegatedFixture('kepala_bagian');
        $other = Employee::factory()->create();
        $this->actingAs($actor)->get(route('rbac.pegawai.edit', $other))->assertForbidden();
        $this->post(route('rbac.pegawai.update', $other), ['nama_lengkap' => 'Tidak Disimpan'])->assertForbidden();
        Role::where('name', 'kepala_bagian')->firstOrFail()->permissions()->detach(Permission::where('name', 'employees.update')->firstOrFail()->id);
        $this->get(route('rbac.pegawai.edit', $employee))->assertForbidden();
        $this->post(route('rbac.pegawai.update', $employee), ['nama_lengkap' => 'Tidak Disimpan'])->assertForbidden();
    }

    public function test_operator_hr_tetap_mendapat_field_identitas_di_form_admin(): void
    {
        $employee = Employee::factory()->create(['nik' => '7171010101010001', 'no_kk' => '7171010101019999']);
        foreach (['super_admin', 'admin_kepegawaian'] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $response = $this->actingAs($actor)->get(route('pegawai.edit', $employee))->assertOk();
            $response->assertSee('7171010101010001')->assertSee('7171010101019999');
            $this->assertSame(route('pegawai.update', $employee), $this->formDestination($response->getContent()));
        }
    }

    private function delegatedFixture(string $role): array
    {
        $this->grant($role, 'employees.update');
        $identity = Employee::factory()->create();
        $actor = User::factory()->create(['role' => $role, 'employee_id' => $identity->id]);
        $employee = $role === 'pegawai' ? $identity : Employee::factory()->create(['kepala_bagian_id' => $identity->id]);
        $employee->update(['nama_lengkap' => 'Pegawai Target Privasi', 'nik' => '7171010101010001', 'no_kk' => '7171010101019999']);

        return [$actor, $employee];
    }

    public function test_target_livewire_tidak_dapat_diganti_ke_pegawai_lain(): void
    {
        [$actor, $employee] = $this->delegatedFixture('kepala_bagian');
        $other = Employee::factory()->create();
        $component = Livewire::actingAs($actor)->test(Edit::class, ['id' => $employee->id]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('pegawaiId', $other->id);
    }

    public function test_render_ulang_menolak_permission_yang_dicabut(): void
    {
        [$actor, $employee] = $this->delegatedFixture('pimpinan');
        $component = Livewire::actingAs($actor)->test(Edit::class, ['id' => $employee->id]);
        $component->assertOk();
        Role::where('name', 'pimpinan')->firstOrFail()->permissions()->detach(Permission::where('name', 'employees.update')->firstOrFail()->id);
        $component->call('$refresh')->assertForbidden();
    }

    public function test_form_mempertahankan_fallback_kelas_jabatan(): void
    {
        [$actor, $employee] = $this->delegatedFixture('pimpinan');
        DB::table('employees')->where('id', $employee->id)->update(['kelas_jabatan' => '7', 'kelas_jabatan_terakhir' => null]);
        $response = $this->actingAs($actor)->get(route('rbac.pegawai.edit', $employee))->assertOk();
        $response->assertSee('name="kelas_jabatan_terakhir" value="7"', false);
        $this->post($this->formDestination($response->getContent()), ['nama_lengkap' => 'Nama Sesudah Edit', 'kelas_jabatan_terakhir' => '7'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('7', $employee->fresh()->kelas_jabatan_terakhir);
    }

    private function grant(string $role, string $permission): void
    {
        Role::where('name', $role)->firstOrFail()->permissions()->syncWithoutDetaching([Permission::where('name', $permission)->firstOrFail()->id]);
    }

    /** Ambil tujuan yang benar-benar dirender browser, bukan POST ke route tebakan test. */
    private function formDestination(string $html): string
    {
        preg_match('/<form\b[^>]*action="([^"]+)"[^>]*enctype="multipart\/form-data"/i', $html, $matches);
        $this->assertNotEmpty($matches, 'Form pegawai harus memiliki tujuan submit.');

        return html_entity_decode($matches[1], ENT_QUOTES);
    }
}
