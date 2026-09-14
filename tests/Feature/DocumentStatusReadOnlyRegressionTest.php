<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression untuk bug 262a73c → fix 8bb07752:
 * Admin read-only (employees.read + dokumen_sk.read, tanpa mutation) harus tetap
 * mendapat tombol dan method Alpine openDocumentStatus, tanpa bergantung pada hasAnyMutation.
 */
class DocumentStatusReadOnlyRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(array $permNames): User
    {
        $employee = Employee::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin_kepegawaian'], ['description' => 'Admin Kepegawaian']);

        $ids = [];
        foreach ($permNames as $name) {
            $perm = Permission::firstOrCreate(['name' => $name], ['module' => explode('.', $name)[0], 'description' => $name]);
            $ids[] = $perm->id;
        }
        $role->permissions()->sync($ids);

        return User::factory()->create(['employee_id' => $employee->id, 'role' => 'admin_kepegawaian']);
    }

    public function test_admin_doc_read_only_gets_button_and_method(): void
    {
        $user = $this->makeAdmin(['employees.read', 'dokumen_sk.read']);
        $target = Employee::factory()->create();

        $this->actingAs($user);

        // Halaman Data Pegawai harus 200 dan merender kontrol dokumen
        $page = $this->get(route('data-pegawai'));
        $page->assertOk();
        $html = $page->getContent();
        // Tombol memanggil openDocumentStatus harus ada
        $this->assertStringContainsString('openDocumentStatus(p)', $html, 'Tombol Rincian Dokumen harus dirender untuk doc read-only');
        // Method Alpine harus ada (bukan hanya tombol)
        $this->assertStringContainsString('async openDocumentStatus(employee)', $html, 'Method openDocumentStatus harus dirender ketika canViewDocumentStatus true');
        // State modal harus ada
        $this->assertStringContainsString('showDocumentStatusModal', $html);

        // Endpoint status-dokumen harus reachable (200) untuk target sah
        $api = $this->getJson("/api/v1/pegawai/{$target->id}/status-dokumen");
        $api->assertOk();
        $api->assertJsonStructure(['employee', 'document_status']);
    }

    public function test_admin_without_doc_read_no_button_and_api_forbidden(): void
    {
        $user = $this->makeAdmin(['employees.read']); // dokumen_sk.read dicabut
        $target = Employee::factory()->create();

        $this->actingAs($user);
        $page = $this->get(route('data-pegawai'));
        $page->assertOk();
        $html = $page->getContent();
        // Tombol tidak boleh dirender, hanya badge
        $this->assertStringNotContainsString('openDocumentStatus(p)', $html, 'Tombol tidak boleh dirender tanpa dokumen_sk.read');
        $this->assertStringNotContainsString('async openDocumentStatus(employee)', $html);

        // Endpoint harus ditolak
        $api = $this->getJson("/api/v1/pegawai/{$target->id}/status-dokumen");
        // Bisa 403 atau 404 tergantung scope, tapi tidak boleh 200
        $this->assertTrue(in_array($api->status(), [403, 404]), 'Endpoint harus ditolak tanpa dokumen_sk.read, got '.$api->status());
    }

    public function test_admin_doc_read_only_no_export_selector(): void
    {
        $user = $this->makeAdmin(['employees.read', 'dokumen_sk.read']);
        $this->actingAs($user);
        $page = $this->get(route('data-pegawai'));
        $page->assertOk();
        // Export Pilihan hanya bila employees.export
        $page->assertDontSee('Export Pilihan');
    }

    public function test_admin_export_only_has_selector(): void
    {
        $user = $this->makeAdmin(['employees.read', 'dokumen_sk.read', 'employees.export']);
        $this->actingAs($user);
        $page = $this->get(route('data-pegawai'));
        $page->assertOk();
        $page->assertSee('Export Pilihan');
        // Bulk bar harus ada, checkbox column ditandai via Bulk bar existence
        $page->assertSee('bulk-bar', false);
    }
}
