<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeMutationResponsePrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceSeeder::class, RbacSeeder::class]);
    }

    /** @return array<string, array{string}> */
    public static function delegatedRoles(): array
    {
        return [
            'Pimpinan' => ['pimpinan'],
            'Kepala Bagian' => ['kepala_bagian'],
            'Pegawai' => ['pegawai'],
        ];
    }

    #[DataProvider('delegatedRoles')]
    public function test_put_delegated_tidak_mengembalikan_identitas_atau_blind_index_dan_tidak_mengubahnya(string $role): void
    {
        [$actor, $employee] = $this->delegatedFixture($role);
        $before = DB::table('employees')->where('id', $employee->id)->first(['nik', 'no_kk', 'nik_hash']);
        $this->assertNotEmpty($before->nik_hash);

        $response = $this->actingAs($actor)->putJson($this->endpoint($employee), [
            'nama_lengkap' => 'Nama Setelah Mutasi API',
        ])->assertOk()
            ->assertJsonPath('message', 'Data pegawai berhasil diperbarui.')
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('employee.nama_lengkap', 'Nama Setelah Mutasi API')
            ->assertJsonPath('employee.nip', '199001012020011001')
            ->assertJsonPath('employee.email_pribadi', 'target-privasi@example.test');

        $employee->refresh();
        $this->assertSame('Nama Setelah Mutasi API', $employee->nama_lengkap);
        $this->assertSame('7171010101010001', $employee->nik);
        $this->assertSame('7171010101019999', $employee->no_kk);
        $this->assertEquals($before, DB::table('employees')->where('id', $employee->id)->first(['nik', 'no_kk', 'nik_hash']));
        foreach (['nik', 'no_kk', 'nik_hash'] as $field) {
            $response->assertJsonMissingPath('employee.'.$field);
        }
        foreach (['7171010101010001', '7171010101019999', $before->nik_hash] as $sensitiveValue) {
            $response->assertDontSee($sensitiveValue, false);
        }
    }

    /** @return array<string, array{string}> */
    public static function hrRoles(): array
    {
        return ['Super Admin' => ['super_admin'], 'Admin Kepegawaian' => ['admin_kepegawaian']];
    }

    #[DataProvider('hrRoles')]
    public function test_put_hr_mempertahankan_kontrak_identitas_dan_field_umum(string $role): void
    {
        $employee = $this->employeeWithIdentifiers();
        $actor = User::factory()->create(['role' => $role]);

        $this->actingAs($actor)->putJson($this->endpoint($employee), ['nama_lengkap' => 'Nama Setelah Edit HR'])
            ->assertOk()
            ->assertJsonPath('message', 'Data pegawai berhasil diperbarui.')
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('employee.nama_lengkap', 'Nama Setelah Edit HR')
            ->assertJsonPath('employee.nip', '199001012020011001')
            ->assertJsonPath('employee.nik', '7171010101010001')
            ->assertJsonPath('employee.no_kk', '7171010101019999');
    }

    #[DataProvider('delegatedRoles')]
    public function test_pencabutan_permission_put_tetap_menolak_tanpa_mutasi(string $role): void
    {
        [$actor, $employee] = $this->delegatedFixture($role);
        Role::where('name', $role)->firstOrFail()->permissions()
            ->detach(Permission::where('name', 'employees.update')->firstOrFail()->id);

        $this->actingAs($actor)->putJson($this->endpoint($employee), ['nama_lengkap' => 'Tidak Disimpan'])
            ->assertForbidden()->assertJsonMissingPath('employee');
        $this->assertSame('Pegawai Target Privasi API', $employee->fresh()->nama_lengkap);
    }

    /** @return array<string, array{string}> */
    public static function scopedRoles(): array
    {
        return ['Kepala Bagian' => ['kepala_bagian'], 'Pegawai' => ['pegawai']];
    }

    #[DataProvider('scopedRoles')]
    public function test_put_target_di_luar_scope_tetap_ditolak(string $role): void
    {
        [$actor] = $this->delegatedFixture($role);
        $other = Employee::factory()->create(['nama_lengkap' => 'Target Asing']);

        $this->actingAs($actor)->putJson($this->endpoint($other), ['nama_lengkap' => 'Tidak Disimpan'])
            ->assertForbidden()->assertJsonMissingPath('employee');
        $this->assertSame('Target Asing', $other->fresh()->nama_lengkap);
    }

    public function test_put_uuid_tidak_valid_tetap_404(): void
    {
        [$actor] = $this->delegatedFixture('pimpinan');
        $this->actingAs($actor)->putJson('/api/v1/pegawai/bukan-uuid', ['nama_lengkap' => 'Tidak Disimpan'])
            ->assertNotFound()->assertJsonMissingPath('employee');
    }

    public function test_store_delegated_tidak_mengembalikan_identitas_atau_blind_index(): void
    {
        $this->grant('pimpinan', 'employees.create');
        $actor = User::factory()->pimpinan()->create();
        $response = $this->actingAs($actor)->postJson('/api/v1/pegawai', [
            'nama_lengkap' => 'Pegawai Baru Privasi API',
            'nik' => '7171010101010001',
            'no_kk' => '7171010101019999',
        ])->assertCreated()
            ->assertJsonPath('message', 'Data pegawai berhasil ditambahkan.')
            ->assertJsonPath('employee.nama_lengkap', 'Pegawai Baru Privasi API');

        $employee = Employee::findOrFail($response->json('employee.id'));
        $this->assertSame('7171010101010001', $employee->nik);
        $this->assertSame('7171010101019999', $employee->no_kk);
        $this->assertNotEmpty($employee->nik_hash);
        foreach (['nik', 'no_kk', 'nik_hash'] as $field) {
            $response->assertJsonMissingPath('employee.'.$field);
        }
    }

    /** @return array{User, Employee} */
    private function delegatedFixture(string $role): array
    {
        $this->grant($role, 'employees.update');
        $identity = Employee::factory()->create();
        $actor = User::factory()->create(['role' => $role, 'employee_id' => $identity->id]);
        $employee = $role === 'pegawai' ? $identity : Employee::factory()->create(['kepala_bagian_id' => $identity->id]);
        $employee->update($this->identifierAttributes());

        return [$actor, $employee];
    }

    private function employeeWithIdentifiers(): Employee
    {
        return Employee::factory()->create($this->identifierAttributes());
    }

    /** Nilai uji tetap membuktikan identitas tersimpan tidak ikut berubah dalam mutasi field umum. */
    private function identifierAttributes(): array
    {
        return [
            'nama_lengkap' => 'Pegawai Target Privasi API',
            'nip' => '199001012020011001',
            'email_pribadi' => 'target-privasi@example.test',
            'nik' => '7171010101010001',
            'no_kk' => '7171010101019999',
        ];
    }

    private function grant(string $role, string $permission): void
    {
        Role::where('name', $role)->firstOrFail()->permissions()
            ->syncWithoutDetaching([Permission::where('name', $permission)->firstOrFail()->id]);
    }

    private function endpoint(Employee $employee): string
    {
        return '/api/v1/pegawai/'.$employee->id;
    }
}
