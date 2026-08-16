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

    public function test_admin_kepegawaian_can_view_and_manage_but_not_delete(): void
    {
        $user = User::factory()->create(['role' => 'admin_kepegawaian']);

        $this->assertTrue(DocumentAuthorization::canViewArchive($user));
        $this->assertTrue(DocumentAuthorization::canManage($user));
        $this->assertFalse(DocumentAuthorization::canDelete($user));
    }

    public function test_super_admin_can_view_manage_and_delete(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);

        $this->assertTrue(DocumentAuthorization::canViewArchive($user));
        $this->assertTrue(DocumentAuthorization::canManage($user));
        $this->assertTrue(DocumentAuthorization::canDelete($user));
    }

    public function test_pimpinan_has_no_document_access(): void
    {
        $user = User::factory()->create(['role' => 'pimpinan']);

        $this->assertFalse(DocumentAuthorization::canViewArchive($user));
        $this->assertFalse(DocumentAuthorization::canManage($user));
        $this->assertFalse(DocumentAuthorization::canDelete($user));
    }

    public function test_guest_has_no_document_access(): void
    {
        $this->assertFalse(DocumentAuthorization::canViewArchive(null));
        $this->assertFalse(DocumentAuthorization::canManage(null));
        $this->assertFalse(DocumentAuthorization::canDelete(null));
    }
}
