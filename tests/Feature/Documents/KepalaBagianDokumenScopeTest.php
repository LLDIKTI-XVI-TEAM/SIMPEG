<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KepalaBagianDokumenScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Document::STORAGE_DISK);
        $this->seed(RbacSeeder::class);
        $this->seed(ReferenceSeeder::class);
    }

    private function grantDokumenRead(string $role): void
    {
        Role::where('name', $role)->firstOrFail()
            ->permissions()->syncWithoutDetaching([
                Permission::where('name', 'dokumen_sk.read')->firstOrFail()->id,
            ]);
    }

    private function makeKabagWithBawahan(): array
    {
        $kabag = Employee::factory()->create(['nama_lengkap' => 'Kabag Arsip']);
        $bawahan = Employee::factory()->create(['nama_lengkap' => 'Bawahan Arsip']);
        $other = Employee::factory()->create(['nama_lengkap' => 'Lain Arsip']);
        SupervisorAssignment::create([
            'employee_id' => $bawahan->id,
            'kepala_bagian_id' => $kabag->id,
            'supervisor_id' => $kabag->id,
            'tanggal_mulai' => now()->subDay()->toDateString(),
            'tanggal_berakhir' => null,
        ]);

        $docBawahan = Document::create([
            'employee_id' => $bawahan->id,
            'jenis_dokumen' => 'sk_pangkat',
            'nama_dokumen' => 'SK Bawahan',
            'file_path' => $bawahan->id.'/sk_pangkat/bawahan.pdf',
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($docBawahan->file_path, 'bawahan');
        $docOther = Document::create([
            'employee_id' => $other->id,
            'jenis_dokumen' => 'sk_pangkat',
            'nama_dokumen' => 'SK Lain',
            'file_path' => $other->id.'/sk_pangkat/lain.pdf',
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($docOther->file_path, 'lain');

        return [$kabag, $bawahan, $other, $docBawahan, $docOther];
    }

    public function test_kabag_tanpa_grant_tetap_403(): void
    {
        [$kabag] = $this->makeKabagWithBawahan();
        $user = User::factory()->create(['role' => 'kepala_bagian', 'employee_id' => $kabag->id]);

        $this->actingAs($user)->get(route('dokumen'))->assertForbidden();
    }

    public function test_kabag_dengan_grant_hanya_lihat_bawahan(): void
    {
        [$kabag, $bawahan, $other, $docBawahan, $docOther] = $this->makeKabagWithBawahan();
        $this->grantDokumenRead('kepala_bagian');
        $user = User::factory()->create(['role' => 'kepala_bagian', 'employee_id' => $kabag->id]);

        $this->actingAs($user)->get(route('dokumen'))->assertOk();

        $list = $this->actingAs($user)->getJson('/api/v1/dokumen?per_page=25')->assertOk();
        $ids = collect($list->json('documents.data'))->pluck('id')->all();
        $this->assertContains($docBawahan->id, $ids);
        $this->assertNotContains($docOther->id, $ids);

        // Filter bawahan eksplisit lolos; filter non-bawahan 403.
        $this->actingAs($user)->getJson("/api/v1/dokumen?employee_id={$bawahan->id}")->assertOk();
        $this->actingAs($user)->getJson("/api/v1/dokumen?employee_id={$other->id}")->assertForbidden();

        // Show/download non-bawahan 403, bawahan 200.
        $this->actingAs($user)->get(route('dokumen.show', $docBawahan->id))->assertOk();
        $this->actingAs($user)->get(route('dokumen.show', $docOther->id))->assertForbidden();
        $this->actingAs($user)->get(route('dokumen.download', $docBawahan->id))->assertOk();
        $this->actingAs($user)->get(route('dokumen.download', $docOther->id))->assertForbidden();
    }
}
