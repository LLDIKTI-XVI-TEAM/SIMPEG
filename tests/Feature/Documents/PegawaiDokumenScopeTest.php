<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PegawaiDokumenScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Document::STORAGE_DISK);
        $this->seed(RbacSeeder::class);
        $this->seed(ReferenceSeeder::class);
    }

    private function grantDokumenRead(): void
    {
        Role::where('name', 'pegawai')->firstOrFail()
            ->permissions()->syncWithoutDetaching([
                Permission::where('name', 'dokumen_sk.read')->firstOrFail()->id,
            ]);
    }

    public function test_pegawai_tanpa_grant_tetap_403(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Arsip']);
        $user = User::factory()->create(['role' => 'pegawai', 'employee_id' => $employee->id]);

        $this->actingAs($user)->get(route('dokumen'))->assertForbidden();
    }

    public function test_pegawai_dengan_grant_hanya_lihat_dokumen_sendiri(): void
    {
        $self = Employee::factory()->create(['nama_lengkap' => 'Pegawai Self']);
        $other = Employee::factory()->create(['nama_lengkap' => 'Pegawai Other']);
        $this->grantDokumenRead();
        $user = User::factory()->create(['role' => 'pegawai', 'employee_id' => $self->id]);

        $docSelf = Document::create([
            'employee_id' => $self->id,
            'jenis_dokumen' => 'sk_pangkat',
            'nama_dokumen' => 'SK Self',
            'file_path' => $self->id.'/sk_pangkat/self.pdf',
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($docSelf->file_path, 'self');
        $docOther = Document::create([
            'employee_id' => $other->id,
            'jenis_dokumen' => 'sk_pangkat',
            'nama_dokumen' => 'SK Other',
            'file_path' => $other->id.'/sk_pangkat/other.pdf',
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($docOther->file_path, 'other');

        $this->actingAs($user)->get(route('dokumen'))->assertOk();

        $list = $this->actingAs($user)->getJson('/api/v1/dokumen?per_page=25')->assertOk();
        $ids = collect($list->json('documents.data'))->pluck('id')->all();
        $this->assertContains($docSelf->id, $ids);
        $this->assertNotContains($docOther->id, $ids);

        // Filter milik sendiri lolos; filter milik orang lain 403.
        $this->actingAs($user)->getJson("/api/v1/dokumen?employee_id={$self->id}")->assertOk();
        $this->actingAs($user)->getJson("/api/v1/dokumen?employee_id={$other->id}")->assertForbidden();

        // Show/download milik orang lain 403, milik sendiri 200.
        $this->actingAs($user)->get(route('dokumen.show', $docSelf->id))->assertOk();
        $this->actingAs($user)->get(route('dokumen.show', $docOther->id))->assertForbidden();
        $this->actingAs($user)->get(route('dokumen.download', $docSelf->id))->assertOk();
        $this->actingAs($user)->get(route('dokumen.download', $docOther->id))->assertForbidden();
    }
}
