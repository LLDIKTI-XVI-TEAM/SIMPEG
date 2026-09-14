<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeArchiveOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceSeeder::class, RbacSeeder::class]);
    }

    public function test_semua_arsip_dapat_ditemukan_per_halaman_tanpa_membocorkan_path_atau_dokumen_lain(): void
    {
        $employee = Employee::factory()->create();
        $documents = collect();
        for ($day = 1; $day <= 12; $day++) {
            $documents->push($this->document($employee, ['tanggal_dokumen' => sprintf('2026-01-%02d', $day)]));
        }
        $this->document($employee, ['jenis_dokumen' => 'sk_jabatan']);
        $this->document(Employee::factory()->create());
        $this->actingAs(User::factory()->adminKepegawaian()->create());

        $first = $this->getJson($this->url($employee))->assertOk()
            ->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $documents->last()->id);
        $second = $this->getJson($this->url($employee, ['page' => 2]))->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 2);
        $rows = collect($first->json('data'))->concat($second->json('data'));
        $this->assertSame($documents->reverse()->pluck('id')->values()->all(), $rows->pluck('id')->all());
        foreach ($rows as $row) {
            $this->assertSame(['id', 'label', 'nomor_dokumen', 'tanggal_dokumen', 'nama_dokumen'], array_keys($row));
        }
        $this->getJson($this->url($employee, ['page' => 3]))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pencarian_mencocokkan_nama_atau_nomor_dokumen_dan_menjaga_kategori(): void
    {
        $employee = Employee::factory()->create();
        $named = $this->document($employee, ['nama_dokumen' => 'Keputusan Pangkat Januari']);
        $numbered = $this->document($employee, ['nomor_dokumen' => 'KEPUTUSAN-42']);
        $this->document($employee, ['nama_dokumen' => 'Keputusan Jabatan', 'jenis_dokumen' => 'sk_jabatan']);
        $this->document($employee);
        $this->actingAs(User::factory()->adminKepegawaian()->create());
        $response = $this->getJson($this->url($employee, ['q' => '  keputusan  ']))
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->assertEqualsCanonicalizing([$named->id, $numbered->id], array_column($response->json('data'), 'id'));
        $this->getJson($this->url($employee, ['q' => 'Tidak Ada']))->assertOk()->assertJsonCount(0, 'data');
    }

    public static function requiredPermissions(): array
    {
        return [['employees.update'], ['dokumen_sk.read']];
    }

    #[DataProvider('requiredPermissions')]
    public function test_pencabutan_salah_satu_permission_menutup_lookup(string $permission): void
    {
        $employee = Employee::factory()->create();
        $this->actingAs(User::factory()->superAdmin()->create());
        Role::where('name', 'super_admin')->firstOrFail()->permissions()
            ->detach(Permission::where('name', $permission)->firstOrFail()->id);
        $this->getJson($this->url($employee))->assertForbidden();
    }

    public static function delegatedRoles(): array
    {
        return [['pimpinan'], ['kepala_bagian'], ['pegawai']];
    }

    #[DataProvider('delegatedRoles')]
    public function test_lookup_delegated_mengikuti_scope_form_edit(string $role): void
    {
        $this->grant($role);
        $identity = Employee::factory()->create();
        $employee = $role === 'pegawai' ? $identity : Employee::factory()->create(['kepala_bagian_id' => $identity->id]);
        $document = $this->document($employee);
        $this->actingAs(User::factory()->create(['role' => $role, 'employee_id' => $identity->id]));
        $this->getJson($this->url($employee))->assertOk()->assertJsonPath('data.0.id', $document->id);
        if ($role !== 'pimpinan') {
            $this->getJson($this->url(Employee::factory()->create()))->assertForbidden();
        }
    }

    public function test_switch_role_mempertahankan_scope_identitas_asli(): void
    {
        $this->grant('pegawai');
        $employee = Employee::factory()->create();
        $document = $this->document($employee);
        $this->actingAs(User::factory()->superAdmin()->create([
            'temporary_role' => 'pegawai',
            'temporary_role_started_at' => now(),
        ]));
        $this->getJson($this->url($employee))->assertOk()->assertJsonPath('data.0.id', $document->id);
    }

    public static function invalidFilters(): array
    {
        return [
            [['kategori' => 'ktp_kk'], 'kategori'],
            [['kategori' => null], 'kategori'],
            [['page' => 0], 'page'],
            [['q' => ['bukan string']], 'q'],
            [['q' => str_repeat('a', 101)], 'q'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_filter_tidak_valid_ditolak(array $filters, string $field): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create());
        $this->getJson($this->url(Employee::factory()->create(), $filters))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_uuid_tidak_valid_menghasilkan_404(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create());
        $this->getJson('/api/v1/pegawai/bukan-uuid/pilihan-arsip?kategori=sk_pangkat')->assertNotFound();
    }

    private function url(Employee $employee, array $filters = []): string
    {
        return '/api/v1/pegawai/'.$employee->id.'/pilihan-arsip?'.http_build_query(array_replace(['kategori' => 'sk_pangkat'], $filters));
    }

    private function document(Employee $employee, array $attributes = []): Document
    {
        return Document::create(array_replace([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pangkat',
            'nama_dokumen' => 'Dokumen Pengujian',
            'nomor_dokumen' => 'SK-123',
            'tanggal_dokumen' => '2026-01-01',
            'file_path' => 'privat/'.$employee->id.'/arsip.pdf',
        ], $attributes));
    }

    private function grant(string $role): void
    {
        Role::where('name', $role)->firstOrFail()->permissions()->syncWithoutDetaching(
            Permission::whereIn('name', ['employees.update', 'dokumen_sk.read'])->pluck('id'),
        );
    }
}
