<?php

namespace Tests\Feature;

use App\Http\Requests\Employee\DeactivateEmployeeRequest;
use App\Http\Requests\Employee\RestoreEmployeeRequest;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EmployeeDeactivateRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_employee_deactivate_and_restore_permissions_are_seeded(): void
    {
        $this->assertDatabaseHas('permissions', [
            'name' => 'employees.deactivate',
            'module' => 'employees',
        ]);
        $this->assertDatabaseHas('permissions', [
            'name' => 'employees.restore',
            'module' => 'employees',
        ]);

        $admin = User::factory()->adminKepegawaian()->create();

        $this->assertTrue($admin->hasPermission('employees.deactivate'));
        $this->assertTrue($admin->hasPermission('employees.restore'));
    }

    public function test_admin_kepegawaian_can_deactivate_employee_via_api_and_audit_is_written(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Nonaktif API',
            'status_aktif' => 'Aktif',
        ]);

        $this->actingAs($user);
        $response = $this->deleteJsonWithCsrf("/api/v1/pegawai/{$employee->id}");

        $response->assertOk();
        $response->assertJsonPath('message', 'Data pegawai berhasil dinonaktifkan.');
        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'SOFT_DELETE',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_super_admin_can_deactivate_employee_via_api(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);

        $this->actingAs($user)
            ->deleteJsonWithCsrf("/api/v1/pegawai/{$employee->id}")
            ->assertOk();

        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
    }

    public function test_admin_kepegawaian_melihat_aksi_nonaktifkan_di_daftar_pegawai(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->get(route('data-pegawai', ['show_nonaktif' => 1]))
            ->assertOk()
            ->assertSee('Nonaktifkan', false)
            ->assertSee('Tampilkan Pegawai Non-Aktif', false)
            ->assertSee('show_nonaktif: true', false)
            ->assertSee("show_nonaktif: this.filters.show_nonaktif ? '1' : '0'", false)
            ->assertSee('filters.show_nonaktif ? null : `/pegawai/${p.id}`', false)
            ->assertSee('x-show="!filters.show_nonaktif" onclick="exportFilteredData()"', false)
            ->assertSee('x-show="!filters.show_nonaktif" onclick="exportFilteredDataPdf()"', false)
            ->assertSee('x-show="!filters.show_nonaktif" type="button" @click="openDocumentStatus(p)"', false)
            ->assertSee('aria-label="\'Nonaktifkan pegawai \' + p.nama_lengkap"', false)
            ->assertSeeText('riwayat, dan dokumen tetap disimpan dan dapat dipulihkan kembali oleh pengguna yang')
            ->assertSeeText('permission pemulihan')
            ->assertDontSee('Pemulihan dilakukan oleh <strong>Super Admin</strong>', false)
            ->assertDontSee('30 hari', false)
            ->assertDontSee('dihapus permanen otomatis', false);
    }

    public function test_admin_kepegawaian_melihat_aksi_nonaktifkan_di_detail_pegawai(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Detail Nonaktif']);

        $this->actingAs($user)
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('Nonaktifkan', false)
            ->assertSee('Apakah Anda yakin ingin menonaktifkan pegawai', false)
            ->assertSee('Data tetap disimpan dan dapat dipulihkan kembali oleh pengguna yang memiliki permission pemulihan.', false);
    }

    public function test_tombol_nonaktifkan_tidak_tampil_tanpa_permission(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.deactivate')->value('id');
        $role->permissions()->detach($permissionId);
        $employee = Employee::factory()->create();

        $this->actingAs($user)
            ->get(route('data-pegawai'))
            ->assertOk()
            ->assertDontSee('aria-label="Nonaktifkan pegawai', false);

        $this->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertDontSee('Nonaktifkan', false);
    }

    public function test_admin_can_restore_employee_via_api_and_audit_is_written(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Restore API',
            'status_aktif' => 'Aktif',
        ]);
        $employee->delete();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/restore");

        $response->assertOk();
        $response->assertJsonPath('message', 'Data pegawai berhasil diaktifkan kembali.');
        $response->assertJsonPath('employee.id', $employee->id);
        $response->assertJsonMissingPath('employee.nik');
        $response->assertJsonMissingPath('employee.no_kk');
        $response->assertJsonMissingPath('employee.keycloak_id');
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'RESTORE',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_api_inactive_list_only_returns_trashed_employees(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nama_lengkap' => 'Pegawai Aktif']);
        $inactive = Employee::factory()->create(['nama_lengkap' => 'Pegawai Nonaktif']);
        $inactive->delete();

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/pegawai/nonaktif');

        $response->assertOk();
        $response->assertJsonPath('employees.data.0.nama_lengkap', 'Pegawai Nonaktif');
        $response->assertJsonMissingPath('employees.data.0.nik');
        $response->assertJsonMissingPath('employees.data.0.no_kk');
        $response->assertJsonMissingPath('employees.data.0.keycloak_id');
        $response->assertJsonMissing(['nama_lengkap' => 'Pegawai Aktif']);
    }

    public function test_api_employee_list_can_filter_nonaktif_employees(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nama_lengkap' => 'Pegawai Aktif Filter']);
        $inactive = Employee::factory()->create(['nama_lengkap' => 'Pegawai Nonaktif Filter']);
        $inactive->delete();

        $this->actingAs($user)
            ->getJson('/api/v1/pegawai?show_nonaktif=true')
            ->assertOk()
            ->assertJsonPath('employees.data.0.nama_lengkap', 'Pegawai Nonaktif Filter')
            ->assertJsonMissing(['nama_lengkap' => 'Pegawai Aktif Filter']);
    }

    public function test_nonaktif_filter_returns_trashed_employees_of_every_lifecycle_status(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        // Pegawai yang dinonaktifkan justru sering berstatus Pensiun atau Mutasi, sehingga filter
        // daftar nonaktif tidak boleh lagi menerapkan default hanya-Aktif.
        $names = [
            'Aktif' => 'Nonaktif Berstatus Aktif',
            'Pensiun' => 'Nonaktif Berstatus Pensiun',
            'Mutasi' => 'Nonaktif Berstatus Mutasi',
            'Non-Aktif' => 'Nonaktif Berstatus Non-Aktif',
        ];

        foreach ($names as $statusAktif => $nama) {
            $employee = Employee::factory()->create([
                'nama_lengkap' => $nama,
                'status_aktif' => $statusAktif,
            ]);
            $employee->delete();
        }

        Employee::factory()->create(['nama_lengkap' => 'Masih Aktif Tidak Boleh Muncul']);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/pegawai?show_nonaktif=true&per_page=50')
            ->assertOk();

        $returned = collect($response->json('employees.data'))->pluck('nama_lengkap');

        foreach ($names as $nama) {
            $this->assertContains($nama, $returned->all());
        }

        $this->assertNotContains('Masih Aktif Tidak Boleh Muncul', $returned->all());
        $this->assertSame(4, $response->json('employees.total'));
    }

    public function test_nonaktif_filter_still_honours_explicit_status_choice(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $pensiun = Employee::factory()->create([
            'nama_lengkap' => 'Nonaktif Pensiun Terpilih',
            'status_aktif' => 'Pensiun',
        ]);
        $pensiun->delete();

        $mutasi = Employee::factory()->create([
            'nama_lengkap' => 'Nonaktif Mutasi Tidak Terpilih',
            'status_aktif' => 'Mutasi',
        ]);
        $mutasi->delete();

        $this->actingAs($user)
            ->getJson('/api/v1/pegawai?show_nonaktif=true&status_aktif=Pensiun')
            ->assertOk()
            ->assertJsonPath('employees.total', 1)
            ->assertJsonPath('employees.data.0.nama_lengkap', 'Nonaktif Pensiun Terpilih');
    }

    public function test_active_employee_list_keeps_default_aktif_filter(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        Employee::factory()->create(['nama_lengkap' => 'Aktif Muncul Default']);
        Employee::factory()->create([
            'nama_lengkap' => 'Pensiun Tidak Muncul Default',
            'status_aktif' => 'Pensiun',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/pegawai')
            ->assertOk()
            ->assertJsonPath('employees.total', 1)
            ->assertJsonPath('employees.data.0.nama_lengkap', 'Aktif Muncul Default');
    }

    public function test_web_deactivate_redirects_and_writes_soft_delete_audit(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Web Nonaktif']);

        $this->actingAs($user);
        $response = $this->postWithCsrf(route('pegawai.destroy', $employee->id));

        $response->assertRedirect(route('data-pegawai'));
        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'SOFT_DELETE',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_deactivation_modals_describe_backup_without_automatic_purge(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->get(route('data-pegawai'))
            ->assertOk()
            ->assertSeeText('Nonaktifkan Pegawai')
            ->assertSeeText('akan dinonaktifkan dan dipindahkan dari daftar pegawai aktif ke')
            ->assertSeeText('Pegawai terpilih akan dinonaktifkan')
            ->assertSeeText('dapat dipulihkan kembali oleh pengguna yang memiliki')
            ->assertSeeText('permission pemulihan')
            ->assertDontSee('Pemulihan dilakukan oleh <strong>Super Admin</strong>', false)
            ->assertSeeText('Data tidak dihapus permanen secara otomatis.')
            ->assertDontSeeText('Data tidak dihapus dan bisa diaktifkan kembali.')
            ->assertDontSeeText('30 hari')
            ->assertDontSeeText('dihapus permanen otomatis');
    }

    public function test_web_restore_redirects_and_writes_restore_audit(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Web Restore']);
        $employee->delete();

        $this->actingAs($user);
        $response = $this->postWithCsrf(route('pegawai.restore', $employee->id));

        // Daftar nonaktif memakai gate yang sama dengan aksi restore, sehingga Admin Kepegawaian
        // tidak boleh diarahkan ke halaman khusus Super Admin seperti data-backup.
        $response->assertRedirect(route('data-nonaktif'));

        // Redirect wajib diikuti karena assert 302 saja tidak membuktikan tujuannya dapat diakses.
        $this->followRedirects($response)->assertOk();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'RESTORE',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_web_restore_does_not_redirect_admin_kepegawaian_to_super_admin_page(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $employee->delete();

        $this->actingAs($user);

        // Menegaskan halaman tujuan lama memang tertutup bagi Admin Kepegawaian, sehingga
        // redirect ke sana akan mengubah restore yang berhasil menjadi dead-end 403.
        $this->get(route('data-backup'))->assertForbidden();

        $this->postWithCsrf(route('pegawai.restore', $employee->id))
            ->assertRedirect(route('data-nonaktif'));
    }

    public function test_web_restore_still_works_for_super_admin(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Web Restore Super']);
        $employee->delete();

        $this->actingAs($user);
        $response = $this->postWithCsrf(route('pegawai.restore', $employee->id));

        $response->assertRedirect(route('data-nonaktif'));
        $this->followRedirects($response)->assertOk();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'RESTORE',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_daftar_pegawai_mode_nonaktif_menyediakan_aksi_aktifkan_kembali(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $inactive = Employee::factory()->create(['nama_lengkap' => 'Pegawai Nonaktif Restore UI']);
        $inactive->delete();

        $this->actingAs($user)
            ->get(route('data-pegawai', ['show_nonaktif' => 1]))
            ->assertOk()
            // Aksi restore harus melekat pada baris hasil filter nonaktif, bukan hanya di halaman terpisah.
            ->assertSee('x-show="filters.show_nonaktif"', false)
            ->assertSee('restorePegawai(p.id, p.nama_lengkap)', false)
            ->assertSee("'Aktifkan kembali pegawai ' + p.nama_lengkap", false)
            ->assertSee('show="showRestoreModal"', false)
            ->assertSee('confirmRestorePegawai()', false)
            ->assertSee('/api/v1/pegawai/${this.restorePegawaiId}/restore', false)
            // Halaman kelola nonaktif tetap dapat dicapai tanpa mengetik URL manual.
            ->assertSee(route('data-nonaktif'), false);
    }

    public function test_aksi_aktifkan_kembali_tidak_tampil_tanpa_permission_restore(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.restore')->value('id');
        $role->permissions()->detach($permissionId);

        $trashed = Employee::factory()->create();
        $trashed->delete();

        $this->actingAs($user)
            ->get(route('data-pegawai', ['show_nonaktif' => 1]))
            ->assertOk()
            ->assertDontSee('restorePegawai(p.id, p.nama_lengkap)', false)
            ->assertDontSee("'Aktifkan kembali pegawai ' + p.nama_lengkap", false);

        $this->postWithCsrf(route('pegawai.restore', $trashed->id))->assertForbidden();
        $this->get(route('data-nonaktif'))->assertForbidden();
    }

    public function test_inactive_page_renders_database_trashed_employees_not_static_rows(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nama_lengkap' => 'Pegawai Aktif Tidak Muncul']);
        $inactive = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Nonaktif Database',
            'nip' => '198001012006041001',
            'jabatan_terakhir' => 'Analis Kepegawaian',
            'golongan_terakhir' => 'III/a',
        ]);
        $inactive->delete();

        $this->actingAs($user);
        $response = $this->get(route('data-nonaktif'));

        $response->assertOk();
        $response->assertSeeText('Pegawai Nonaktif Database');
        $response->assertSeeText('198001012006041001');
        $response->assertDontSeeText('Pegawai Aktif Tidak Muncul');
        $response->assertDontSeeText('Yucna Dara');
        $response->assertSee(route('pegawai.restore', $inactive->id), false);
    }

    public function test_pegawai_cannot_deactivate_or_restore_employee(): void
    {
        $user = User::factory()->pegawai()->create();
        $employee = Employee::factory()->create();
        $trashed = Employee::factory()->create();
        $trashed->delete();

        $this->actingAs($user);

        $this->deleteJsonWithCsrf("/api/v1/pegawai/{$employee->id}")->assertForbidden();
        $this->postJsonWithCsrf("/api/v1/pegawai/{$trashed->id}/restore")->assertForbidden();
    }

    public function test_permission_removal_blocks_deactivate_and_restore(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionIds = Permission::whereIn('name', [
            'employees.deactivate',
            'employees.restore',
        ])->pluck('id');
        $role->permissions()->detach($permissionIds);
        $employee = Employee::factory()->create();
        $trashed = Employee::factory()->create();
        $trashed->delete();

        $this->actingAs($user);

        $this->deleteJsonWithCsrf("/api/v1/pegawai/{$employee->id}")->assertForbidden();
        $this->postJsonWithCsrf("/api/v1/pegawai/{$trashed->id}/restore")->assertForbidden();
    }

    public function test_bulk_mutations_validate_selected_ids_as_uuid_array(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->postWithCsrf(route('pegawai.bulkDestroy'), ['ids' => ['not-a-uuid']])
            ->assertSessionHasErrors('ids.0');

        $this->actingAs($superAdmin)
            ->postWithCsrf(route('pegawai.bulkRestore'), ['ids' => ['not-a-uuid']])
            ->assertSessionHasErrors('ids.0');
    }

    public function test_daftar_mode_nonaktif_menutup_selector_dan_aksi_massal(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $trashed = Employee::factory()->create();
        $trashed->delete();

        $response = $this->actingAs($user)
            ->get(route('data-pegawai', ['show_nonaktif' => 1]))
            ->assertOk();

        // Select-all dan checkbox baris harus benar-benar tertutup, bukan hanya tersembunyi,
        // agar aksi massal pegawai aktif tidak bisa dipicu terhadap data nonaktif.
        $response->assertSee('x-show="!filters.show_nonaktif" x-bind:disabled="!(!filters.show_nonaktif)"', false);
        $response->assertSee('x-bind:disabled="filters.show_nonaktif"', false);
        $response->assertSee('id="bulk-bar" x-show="!filters.show_nonaktif"', false);

        // Seluruh pengumpulan pilihan mengabaikan checkbox yang dinonaktifkan.
        $response->assertSee('.row-check:not([disabled]):checked', false);
        $response->assertDontSee("document.querySelectorAll('.row-check:checked')", false);
    }

    public function test_mutasi_daftar_memuat_ulang_halaman_agar_pagination_sinkron(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)
            ->get(route('data-pegawai'))
            ->assertOk();

        // Restore dan nonaktifkan mengubah jumlah data server, sehingga halaman dimuat ulang
        // dan metadata pagination tidak boleh lagi ditebak di sisi klien.
        $response->assertSee('async refreshAfterListMembershipChange()', false);
        $response->assertSee('await this.refreshAfterListMembershipChange();', false);
        $response->assertSee('if (requestedPage > lastPage)', false);
        $response->assertSee("this.clearCacheByPrefixes(['pegawai_']);", false);
        $response->assertSee("this.clearCacheByPrefixes(['pegawai_', 'backup_']);", false);
        $this->assertSame(2, substr_count($response->getContent(), 'this.clearEmployeeLifecycleCache();'));
        $response->assertDontSee('this.meta.total = Math.max(0, this.meta.total - 1);', false);
    }

    public function test_local_api_auth_bypass_still_applies_to_deactivate_and_restore_requests(): void
    {
        // Route pegawai melepas middleware auth saat flag lokal aktif, sehingga FormRequest
        // tidak boleh mengubah kontrak itu menjadi 403.
        $this->app->detectEnvironment(fn () => 'local');
        config(['services.simpeg.disable_employee_api_auth' => true]);

        $this->assertTrue((new DeactivateEmployeeRequest)->authorize());
        $this->assertTrue((new RestoreEmployeeRequest)->authorize());
    }

    public function test_deactivate_and_restore_requests_stay_fail_closed_without_local_bypass(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['services.simpeg.disable_employee_api_auth' => false]);

        $withoutFlag = [new DeactivateEmployeeRequest, new RestoreEmployeeRequest];

        foreach ($withoutFlag as $request) {
            $request->setUserResolver(fn () => null);
            $this->assertFalse($request->authorize());
        }

        // Flag hanya berlaku di environment local; production tetap menegakkan role dan permission.
        $this->app->detectEnvironment(fn () => 'production');
        config(['services.simpeg.disable_employee_api_auth' => true]);

        $inProduction = [new DeactivateEmployeeRequest, new RestoreEmployeeRequest];

        foreach ($inProduction as $request) {
            $request->setUserResolver(fn () => null);
            $this->assertFalse($request->authorize());
        }
    }

    private function postJsonWithCsrf(string $uri): TestResponse
    {
        return $this
            ->withSession(['_token' => 'test-token'])
            ->postJson($uri, ['_token' => 'test-token']);
    }

    private function postWithCsrf(string $uri, array $data = []): TestResponse
    {
        return $this
            ->withSession(['_token' => 'test-token'])
            ->post($uri, array_merge(['_token' => 'test-token'], $data));
    }

    private function deleteJsonWithCsrf(string $uri): TestResponse
    {
        return $this
            ->withSession(['_token' => 'test-token'])
            ->deleteJson($uri, ['_token' => 'test-token']);
    }
}
