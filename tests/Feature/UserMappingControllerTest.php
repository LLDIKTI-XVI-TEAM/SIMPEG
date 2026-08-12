<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class UserMappingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    // -----------------------------------------------------------------------
    // Authorization and UI contract
    // -----------------------------------------------------------------------

    public function test_guest_cannot_access_user_mapping(): void
    {
        $this->get(route('user-management'))
            ->assertRedirect();
    }

    public function test_each_non_super_admin_role_cannot_access_or_update_user_mapping(): void
    {
        $employee = Employee::factory()->create();

        foreach (['adminKepegawaian', 'pimpinan', 'kepalaBagian', 'pegawai'] as $factoryState) {
            $actor = User::factory()->{$factoryState}()->create();

            $this->actingAs($actor)
                ->get(route('user-management'))
                ->assertForbidden();

            $this->actingAs($actor)
                ->post(route('user-management.update'), $this->mappingPayload($employee))
                ->assertForbidden();
        }
    }

    public function test_super_admin_can_access_user_mapping_index(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('user-management'))
            ->assertOk();
    }

    public function test_index_uses_internal_role_codes_and_represents_missing_role_honestly(): void
    {
        $admin = User::factory()->superAdmin()->create();
        Employee::factory()->create([
            'email' => 'belum-berrole@example.com',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('user-management'))
            ->assertOk()
            ->assertSee('<option value="super_admin">Super Admin</option>', false)
            ->assertSee('<option value="admin_kepegawaian">Admin Kepegawaian</option>', false)
            ->assertSee('<option value="kepala_bagian">Kepala Bagian</option>', false)
            ->assertDontSee('<option value="Super Admin">Super Admin</option>', false)
            ->assertSee('<input type="hidden" name="employee_id"', false)
            ->assertDontSee('<input type="hidden" name="email"', false)
            ->assertSee('Disconnect belum tersedia pada halaman ini.');

        foreach ([
            'super_admin' => 'Super Admin',
            'admin_kepegawaian' => 'Admin Kepegawaian',
            'pimpinan' => 'Pimpinan',
            'kepala_bagian' => 'Kepala Bagian',
            'pegawai' => 'Pegawai',
        ] as $role => $label) {
            $response->assertSee(sprintf('<option value="%s">%s</option>', $role, $label), false);
        }

        $dataResponse = $this->actingAs($admin)->getJson(route('user-management.data'));
        $this->assertNull($dataResponse->json('data.0.role'));
    }

    public function test_mapping_modal_has_keyboard_focus_management_contract(): void
    {
        Employee::factory()->create();

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('user-management'))
            ->assertOk()
            ->assertSee('openEdit(emp, $event.currentTarget)', false)
            ->assertSee('x-ref="keycloakIdentifier"', false)
            ->assertSee('x-ref="roleSelect"', false)
            ->assertSee('@keydown.tab="trapFocus($event, $el)"', false)
            ->assertSee('@keydown.escape.window="if (showEditModal) closeEdit()"', false)
            ->assertSee('this.lastFocusedElement.focus()', false);
    }

    public function test_index_prefers_canonical_employee_mapping_over_email_matching(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['email' => 'pegawai@example.com']);

        User::factory()->create([
            'email' => 'alamat-berbeda@example.com',
            'employee_id' => $employee->id,
            'keycloak_id' => 'canonical-keycloak-subject',
            'role' => 'pimpinan',
        ]);

        $dataResponse = $this->actingAs($admin)
            ->getJson(route('user-management.data'))
            ->assertOk();

        $this->assertSame('canonical-keycloak-subject', $dataResponse->json('data.0.keycloak_id'));
        $this->assertSame('pimpinan', $dataResponse->json('data.0.role'));
    }

    // -----------------------------------------------------------------------
    // Fase 3 — daftar mapping server-side
    // -----------------------------------------------------------------------

    public function test_index_paginates_filters_on_the_server_and_preserves_query_parameters(): void
    {
        $admin = User::factory()->superAdmin()->create();

        foreach (range(1, 12) as $number) {
            $employee = Employee::factory()->create([
                'nama_lengkap' => sprintf('Pegawai Pagination %02d', $number),
                'nip' => str_pad((string) $number, 18, '0', STR_PAD_LEFT),
            ]);

            User::factory()->pegawai()->create([
                'employee_id' => $employee->id,
                'keycloak_id' => 'kc-pagination-'.$number,
            ]);
        }

        $response = $this->actingAs($admin)->getJson(route('user-management.data', [
            'search' => 'Pegawai Pagination',
            'role' => 'pegawai',
            'status' => 'terhubung',
            'per_page' => 5,
        ]));

        $response->assertOk();

        $this->assertSame(12, $response->json('meta.total'));
        $this->assertCount(5, $response->json('data'));
        $this->assertSame(1, $response->json('meta.current_page'));

        $this->assertSame('Pegawai Pagination 01', $response->json('data.0.nama'));
        $this->assertSame('Pegawai Pagination 05', $response->json('data.4.nama'));

        $firstRow = $response->json('data.0');

        $this->assertArrayHasKey('id', $firstRow);
        $this->assertArrayHasKey('nama', $firstRow);
        $this->assertArrayHasKey('nip', $firstRow);
        $this->assertArrayHasKey('mapped_email', $firstRow);
        $this->assertArrayHasKey('keycloak_id', $firstRow);
        $this->assertArrayHasKey('role', $firstRow);
        $this->assertArrayHasKey('mapping_status', $firstRow);
        $this->assertArrayHasKey('mapping_status_label', $firstRow);
        $this->assertArrayNotHasKey('alamat', $firstRow);
        $this->assertArrayNotHasKey('nik', $firstRow);
        $this->assertArrayNotHasKey('no_hp', $firstRow);
    }

    public function test_index_filters_each_honest_mapping_status(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $connected = Employee::factory()->create(['nama_lengkap' => 'Status Terhubung']);
        $withoutLocalUser = Employee::factory()->create(['nama_lengkap' => 'Status Tanpa User']);
        $withoutIdentifier = Employee::factory()->create(['nama_lengkap' => 'Status Identifier Kosong']);
        $withoutRole = Employee::factory()->create(['nama_lengkap' => 'Status Role Kosong']);

        User::factory()->pegawai()->create([
            'employee_id' => $connected->id,
            'keycloak_id' => 'kc-status-connected',
        ]);
        User::factory()->pegawai()->create([
            'employee_id' => $withoutIdentifier->id,
            'keycloak_id' => null,
        ]);
        User::factory()->create([
            'employee_id' => $withoutRole->id,
            'keycloak_id' => 'kc-status-no-role',
            'role' => null,
        ]);

        foreach ([
            'terhubung' => [$connected->id, 'Terhubung'],
            'belum_ada_user' => [$withoutLocalUser->id, 'Belum Ada User Lokal'],
            'identifier_kosong' => [$withoutIdentifier->id, 'Identifier Keycloak Kosong'],
            'role_kosong' => [$withoutRole->id, 'Role Belum Ditetapkan'],
        ] as $status => [$employeeId, $label]) {
            $response = $this->actingAs($admin)->getJson(route('user-management.data', [
                'status' => $status,
            ]));

            $response->assertOk();

            $this->assertSame(1, $response->json('meta.total'));
            $this->assertSame($employeeId, $response->json('data.0.id'));
            $this->assertSame($status, $response->json('data.0.mapping_status'));
        }
    }

    public function test_index_searches_canonical_user_email_even_when_employee_email_differs(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create([
            'email' => 'pegawai-data@example.com',
        ]);

        User::factory()->pimpinan()->create([
            'employee_id' => $employee->id,
            'email' => 'akun-sso-berbeda@example.com',
            'keycloak_id' => 'kc-canonical-email-search',
        ]);

        $response = $this->actingAs($admin)->getJson(route('user-management.data', [
            'search' => 'akun-sso-berbeda@example.com',
        ]));

        $response->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($employee->id, $response->json('data.0.id'));
        $this->assertSame('pimpinan', $response->json('data.0.role'));
        $this->assertSame('akun-sso-berbeda@example.com', $response->json('data.0.mapped_email'));
    }

    public function test_index_uses_legacy_email_fallback_only_for_one_unambiguous_employee(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $legacyEmail = 'legacy-aman@example.com';
        $employee = Employee::factory()->create(['email' => $legacyEmail]);

        User::factory()->pegawai()->create([
            'email' => $legacyEmail,
            'keycloak_id' => 'kc-safe-legacy-user',
        ]);

        $response = $this->actingAs($admin)->getJson(route('user-management.data', [
            'status' => 'terhubung',
        ]));

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($employee->id, $response->json('data.0.id'));
        $this->assertSame('kc-safe-legacy-user', $response->json('data.0.keycloak_id'));

        $ambiguousEmployee = Employee::factory()->create([
            'email_pribadi' => 'kanonis-lain@example.com',
        ]);
        DB::table('employees')->where('id', $ambiguousEmployee->id)->update([
            'email' => $legacyEmail,
        ]);

        $ambiguousResponse = $this->actingAs($admin)->getJson(route('user-management.data', [
            'status' => 'terhubung',
        ]));

        $this->assertSame(0, $ambiguousResponse->json('meta.total'));
    }

    // -----------------------------------------------------------------------
    // Update — canonical employee mapping
    // -----------------------------------------------------------------------

    public function test_update_saves_role_keycloak_identifier_and_canonical_employee_id(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['email' => 'andi@example.com']);
        $user = User::factory()->create(['email' => 'andi@example.com', 'role' => 'pegawai']);

        $this->actingAs($admin)
            ->post(route('user-management.update'), $this->mappingPayload($employee, [
                'keycloak_id' => 'kc-subject-andi',
                'role' => 'admin_kepegawaian',
            ]))
            ->assertRedirect();

        $user->refresh();
        $this->assertSame('kc-subject-andi', $user->keycloak_id);
        $this->assertSame('admin_kepegawaian', $user->role);
        $this->assertSame($employee->id, $user->employee_id);
    }

    public function test_update_accepts_and_persists_each_internal_role_from_the_form_contract(): void
    {
        $admin = User::factory()->superAdmin()->create();

        foreach ([
            'super_admin',
            'admin_kepegawaian',
            'pimpinan',
            'kepala_bagian',
            'pegawai',
        ] as $role) {
            $employee = Employee::factory()->create();

            $this->actingAs($admin)
                ->post(route('user-management.update'), $this->mappingPayload($employee, [
                    'keycloak_id' => 'kc-subject-role-'.$role.'-'.$employee->id,
                    'role' => $role,
                ]))
                ->assertRedirect();

            $this->assertDatabaseHas('users', [
                'employee_id' => $employee->id,
                'role' => $role,
            ]);
        }
    }

    public function test_update_uses_employee_id_even_when_tampered_email_is_sent(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['email' => 'pegawai-canonical@example.com']);

        $this->actingAs($admin)
            ->post(route('user-management.update'), $this->mappingPayload($employee, [
                'email' => 'penyerang@example.com',
                'keycloak_id' => 'kc-subject-canonical',
            ]))
            ->assertRedirect();

        $user = User::query()->where('employee_id', $employee->id)->sole();

        $this->assertSame('pegawai-canonical@example.com', $user->email);
        $this->assertSame('kc-subject-canonical', $user->keycloak_id);
    }

    public function test_update_writes_masked_strict_audit_record(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['email' => 'citra@example.com']);
        $user = User::factory()->create(['email' => 'citra@example.com', 'role' => 'pegawai']);
        $identifier = 'keycloak-subject-citra-12345';

        $this->actingAs($admin)
            ->withServerVariables([
                'REMOTE_ADDR' => '203.0.113.42',
                'HTTP_USER_AGENT' => 'SIMPEG-QA/UserMappingAuditTest',
            ])
            ->post(route('user-management.update'), $this->mappingPayload($employee, [
                'keycloak_id' => $identifier,
                'role' => 'pimpinan',
            ]))
            ->assertRedirect();

        $audit = AuditLog::query()->where('auditable_id', $user->id)->sole();

        $this->assertSame('UPDATE', $audit->event);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame($employee->id, $audit->new_values['employee_id']);
        $this->assertSame('pimpinan', $audit->new_values['role']);
        $this->assertSame('connected', $audit->new_values['mapping_status']);
        $this->assertArrayHasKey('keycloak_id_masked', $audit->new_values);
        $this->assertArrayNotHasKey('keycloak_id', $audit->new_values);
        $this->assertNotSame($identifier, $audit->new_values['keycloak_id_masked']);
        $this->assertStringNotContainsString($identifier, json_encode([$audit->old_values, $audit->new_values]));
        $this->assertSame('203.0.113.42', $audit->ip_address);
        $this->assertSame('SIMPEG-QA/UserMappingAuditTest', $audit->user_agent);
    }

    public function test_update_rolls_back_mapping_when_strict_audit_fails(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['email' => 'audit-fail@example.com']);

        $failedAudit = new class extends AuditService
        {
            public static int $calls = 0;

            public static function logOrFail(
                string $event,
                string $auditableType,
                ?string $auditableId = null,
                ?array $oldValues = null,
                ?array $newValues = null,
                ?Request $request = null,
                ?string $ipAddress = null,
                ?string $userAgent = null,
            ): void {
                self::$calls++;

                throw new RuntimeException('Audit storage tidak tersedia.');
            }
        };

        $this->instance(AuditService::class, $failedAudit);

        $this->actingAs($admin)
            ->from(route('user-management'))
            ->post(route('user-management.update'), $this->mappingPayload($employee, [
                'form' => 'user-mapping',
                'keycloak_id' => 'kc-subject-audit-fail',
            ]))
            ->assertRedirect(route('user-management'))
            ->assertSessionHasErrors('mapping');

        $this->assertDatabaseMissing('users', [
            'email' => 'audit-fail@example.com',
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame(1, $failedAudit::$calls);
    }

    // -----------------------------------------------------------------------
    // Update — validation and integrity
    // -----------------------------------------------------------------------

    public function test_update_rejects_missing_employee_id_and_reopens_mapping_modal(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->from(route('user-management'))
            ->post(route('user-management.update'), [
                'form' => 'user-mapping',
                'keycloak_id' => 'kc-subject-missing-employee',
                'role' => 'pegawai',
            ])
            ->assertSessionHasErrors('employee_id');

        $this->get(route('user-management'))
            ->assertOk()
            ->assertSee('showEditModal: true', false)
            ->assertSee('id="user-mapping-employee-error"', false)
            ->assertSee('Pegawai wajib dipilih.');
    }

    public function test_update_rejects_unknown_employee_without_creating_an_orphan_user(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('user-management.update'), [
                'employee_id' => (string) Str::uuid(),
                'keycloak_id' => 'kc-subject-unknown-employee',
                'role' => 'pegawai',
            ])
            ->assertSessionHasErrors('employee_id');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_update_rejects_empty_keycloak_identifier_until_disconnect_is_designed(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($admin)
            ->post(route('user-management.update'), $this->mappingPayload($employee, [
                'keycloak_id' => '',
            ]))
            ->assertSessionHasErrors('keycloak_id');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_update_rejects_invalid_role_and_reopens_mapping_modal_with_accessible_field_error(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($admin)
            ->from(route('user-management'))
            ->post(route('user-management.update'), $this->mappingPayload($employee, [
                'form' => 'user-mapping',
                'role' => 'invalid_role',
            ]))
            ->assertRedirect(route('user-management'))
            ->assertSessionHasErrors('role');

        $this->get(route('user-management'))
            ->assertOk()
            ->assertSee('showEditModal: true', false)
            ->assertSee('id="user-mapping-role_error"', false)
            ->assertSee('aria-describedby="user-mapping-role_help user-mapping-role_error"', false)
            ->assertSee('Role yang dipilih tidak valid.');
    }

    public function test_update_rejects_legacy_role_label_and_malformed_employee_identifier(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($admin)
            ->post(route('user-management.update'), $this->mappingPayload($employee, [
                'role' => 'Super Admin',
            ]))
            ->assertSessionHasErrors('role');

        $this->actingAs($admin)
            ->post(route('user-management.update'), [
                'employee_id' => 'bukan-uuid',
                'keycloak_id' => 'kc-subject-malformed-employee',
                'role' => 'pegawai',
            ])
            ->assertSessionHasErrors('employee_id');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_update_rejects_keycloak_identifier_already_mapped_to_another_employee(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $firstEmployee = Employee::factory()->create();
        $targetEmployee = Employee::factory()->create();

        User::factory()->create([
            'employee_id' => $firstEmployee->id,
            'keycloak_id' => 'kc-subject-taken',
        ]);

        $this->actingAs($admin)
            ->from(route('user-management'))
            ->post(route('user-management.update'), $this->mappingPayload($targetEmployee, [
                'form' => 'user-mapping',
                'keycloak_id' => 'kc-subject-taken',
            ]))
            ->assertRedirect(route('user-management'))
            ->assertSessionHasErrors('keycloak_id');

        $this->get(route('user-management'))
            ->assertOk()
            ->assertSee('showEditModal: true', false)
            ->assertSee('id="user-mapping-keycloak-id-error"', false)
            ->assertSee('Identifier Keycloak tersebut sudah dipetakan ke pegawai lain.');
    }

    public function test_update_rejects_two_distinct_users_for_one_employee_without_mutating_either_mapping(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();
        $mappedUser = User::factory()->pegawai()->create([
            'employee_id' => $employee->id,
            'keycloak_id' => 'kc-subject-existing-employee',
        ]);
        $otherUser = User::factory()->pimpinan()->create([
            'keycloak_id' => 'kc-subject-other-user',
        ]);

        $this->actingAs($admin)
            ->post(route('user-management.update'), $this->mappingPayload($employee, [
                'keycloak_id' => $otherUser->keycloak_id,
                'role' => 'admin_kepegawaian',
            ]))
            ->assertSessionHasErrors('keycloak_id');

        $mappedUser->refresh();
        $otherUser->refresh();

        $this->assertSame($employee->id, $mappedUser->employee_id);
        $this->assertSame('kc-subject-existing-employee', $mappedUser->keycloak_id);
        $this->assertNull($otherUser->employee_id);
        $this->assertSame('kc-subject-other-user', $otherUser->keycloak_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mappingPayload(Employee $employee, array $overrides = []): array
    {
        return array_merge([
            'employee_id' => $employee->id,
            'keycloak_id' => 'kc-subject-'.$employee->id,
            'role' => 'pegawai',
        ], $overrides);
    }
}
