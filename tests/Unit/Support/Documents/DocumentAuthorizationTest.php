<?php

namespace Tests\Unit\Support\Documents;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Documents\DocumentAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbac();
    }

    public function test_admin_kepegawaian_can_view_and_manage_documents(): void
    {
        $user = User::factory()->create(['role' => 'admin_kepegawaian']);

        $this->assertTrue(DocumentAuthorization::canViewArchive($user));
        $this->assertTrue(DocumentAuthorization::canManage($user));
    }

    public function test_super_admin_can_view_and_manage_documents(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);

        $this->assertTrue(DocumentAuthorization::canViewArchive($user));
        $this->assertTrue(DocumentAuthorization::canManage($user));
    }

    public function test_pimpinan_can_view_but_not_manage_documents(): void
    {
        $user = User::factory()->create(['role' => 'pimpinan']);

        // RbacSeeder memberi pimpinan dokumen_sk.read → boleh lihat arsip, tapi tanpa hak mutasi.
        $this->assertTrue(DocumentAuthorization::canViewArchive($user));
        $this->assertFalse(DocumentAuthorization::canManage($user));
    }

    public function test_kepala_bagian_can_view_archive_with_read_permission(): void
    {
        $user = User::factory()->create(['role' => 'kepala_bagian']);

        // RbacSeeder default tanpa dokumen_sk.read → awalnya tidak boleh; setelah grant manual boleh (A1 arsip).
        $this->assertFalse(DocumentAuthorization::canViewArchive($user));

        $user->refresh();
        Role::where('name', 'kepala_bagian')->firstOrFail()
            ->permissions()->syncWithoutDetaching([
                Permission::where('name', 'dokumen_sk.read')->firstOrFail()->id,
            ]);

        $this->assertTrue(DocumentAuthorization::canViewArchive($user->refresh()));
        $this->assertFalse(DocumentAuthorization::canManage($user));
    }

    public function test_guest_has_no_document_access(): void
    {
        $this->assertFalse(DocumentAuthorization::canViewArchive(null));
        $this->assertFalse(DocumentAuthorization::canManage(null));
    }

    public function test_authorization_uses_effective_role_during_role_simulation(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'temporary_role' => 'pegawai',
        ]);

        $this->assertFalse(DocumentAuthorization::canViewArchive($user));
        $this->assertFalse(DocumentAuthorization::canManage($user));
    }
}
