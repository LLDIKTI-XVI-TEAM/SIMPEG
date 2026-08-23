<?php

namespace Tests\Unit\Support\Documents;

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

    public function test_pimpinan_has_no_document_access(): void
    {
        $user = User::factory()->create(['role' => 'pimpinan']);

        $this->assertFalse(DocumentAuthorization::canViewArchive($user));
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
