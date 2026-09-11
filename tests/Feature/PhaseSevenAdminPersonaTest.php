<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PhaseSevenBrowserQaSeeder;
use Database\Seeders\SsoRoleMappedAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression untuk blocker PR #21 poin #4:
 * PhaseSevenBrowserQaSeeder::upsertAdminSession() harus memakai kontrak kanonis
 * email → Employee → User.employee_id, bukan username-first.
 */
class PhaseSevenAdminPersonaTest extends TestCase
{
    use RefreshDatabase;

    private function canonicalAdminEmail(): string
    {
        $uat = collect(SsoRoleMappedAccountSeeder::UAT_ACCOUNTS)->firstWhere('username', 'demo-klabat-kepeg');

        return $uat['email'];
    }

    private function canonicalAdminUsername(): string
    {
        $uat = collect(SsoRoleMappedAccountSeeder::UAT_ACCOUNTS)->firstWhere('username', 'demo-klabat-kepeg');

        return $uat['username'];
    }

    public function test_admin_persona_accepts_internal_email_mismatch_when_canonical_employee_and_employee_id_correct(): void
    {
        $this->seed(DatabaseSeeder::class);

        $adminUser = User::where('keycloak_username', $this->canonicalAdminUsername())->firstOrFail();
        $employee = Employee::findOrFail($adminUser->employee_id);

        // Ubah email internal User agar berbeda dari SSO kanonis — harus tetap diterima
        // bila Employee kanonis + employee_id benar (regression minimum #1 reviewer).
        $adminUser->email = 'internal-different@example.internal';
        $adminUser->save();

        // Employee harus tetap kanonis
        $this->assertSame(strtolower($this->canonicalAdminEmail()), strtolower($employee->email));

        // Tidak boleh throw
        $this->seed(PhaseSevenBrowserQaSeeder::class);

        $this->assertDatabaseHas('users', [
            'keycloak_username' => $this->canonicalAdminUsername(),
            'employee_id' => $employee->id,
        ]);
    }

    public function test_admin_throws_when_username_matches_but_canonical_employee_missing(): void
    {
        $this->seed(DatabaseSeeder::class);

        $email = $this->canonicalAdminEmail();
        // Hapus Employee kanonis, biarkan User dengan username tetap ada (orphan)
        Employee::whereRaw('lower(email) = ? OR lower(email_pribadi) = ?', [strtolower($email), strtolower($email)])->delete();

        // User masih ada tapi Employee kanonis hilang — harus throw, tidak boleh PASS palsu via username
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ambiguous or missing active Employee for demo admin email/');
        $this->seed(PhaseSevenBrowserQaSeeder::class);
    }

    public function test_admin_throws_when_ambiguous_employee(): void
    {
        $this->seed(DatabaseSeeder::class);

        $email = $this->canonicalAdminEmail();
        $jenisPegawaiId = RefJenisPegawai::where('nama', 'PNS')->value('id');
        $statusAktifId = RefStatusPegawai::where('nama', 'Aktif')->value('id');

        // Buat duplikat yang lolos unique lower(email_pribadi) tapi tetap match OR query:
        // - existing admin: email=admin..., email_pribadi=admin... (lower match via both)
        // - duplicate: email=admin... (lower match via email), email_pribadi=other (distinct lower)
        // sehingga seeder lower(email) OR lower(email_pribadi) menemukan 2 kandidat.
        DB::table('employees')->insert([
            'id' => (string) Str::uuid(),
            'nama_lengkap' => 'Duplicate Admin',
            'nip' => '198001012026009999',
            'tempat_lahir' => 'Gorontalo',
            'tanggal_lahir' => '1980-01-01',
            'jenis_kelamin' => 'L',
            'jenis_pegawai_id' => $jenisPegawaiId,
            'status_pegawai_id' => $statusAktifId,
            'status_aktif' => 'Aktif',
            'email' => $email,
            'email_pribadi' => 'ambiguous-duplicate-'.uniqid().'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ambiguous or missing active Employee/');
        $this->seed(PhaseSevenBrowserQaSeeder::class);
    }

    public function test_admin_throws_when_missing_user(): void
    {
        $this->seed(DatabaseSeeder::class);

        $email = $this->canonicalAdminEmail();
        $employee = Employee::whereRaw('lower(email) = ? OR lower(email_pribadi) = ?', [strtolower($email), strtolower($email)])->firstOrFail();
        User::where('employee_id', $employee->id)->delete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/admin User not found via employee_id/');
        $this->seed(PhaseSevenBrowserQaSeeder::class);
    }

    public function test_admin_throws_when_missing_employee_and_user(): void
    {
        // Seed dulu agar Ref dan data lain ada, lalu hapus persona admin sepenuhnya
        $this->seed(DatabaseSeeder::class);
        $email = $this->canonicalAdminEmail();
        $employee = Employee::whereRaw('lower(email) = ? OR lower(email_pribadi) = ?', [strtolower($email), strtolower($email)])->first();
        if ($employee) {
            User::where('employee_id', $employee->id)->delete();
            $employee->delete();
        }
        // Pastikan benar-benar hilang
        $this->assertSame(0, Employee::whereRaw('lower(email) = ? OR lower(email_pribadi) = ?', [strtolower($email), strtolower($email)])->count());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ambiguous or missing active Employee/');
        $this->seed(PhaseSevenBrowserQaSeeder::class);
    }

    public function test_admin_throws_when_status_nonaktif(): void
    {
        $this->seed(DatabaseSeeder::class);

        $email = $this->canonicalAdminEmail();
        $employee = Employee::whereRaw('lower(email) = ? OR lower(email_pribadi) = ?', [strtolower($email), strtolower($email)])->firstOrFail();
        $nonaktifId = RefStatusPegawai::where('kode', 'NONAKTIF')->where('kelompok', 'Nonaktif')->value('id');
        $employee->status_pegawai_id = $nonaktifId;
        $employee->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/admin employee not Aktif/');
        $this->seed(PhaseSevenBrowserQaSeeder::class);
    }

    public function test_admin_throws_when_role_mismatch(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::where('keycloak_username', $this->canonicalAdminUsername())->firstOrFail();
        $user->role = 'pegawai';
        $user->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/admin user role\/keycloak_username\/employee_id mismatch/');
        $this->seed(PhaseSevenBrowserQaSeeder::class);
    }

    public function test_admin_throws_when_username_mismatch(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::where('keycloak_username', $this->canonicalAdminUsername())->firstOrFail();
        $user->keycloak_username = 'wrong-username';
        $user->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/admin user role\/keycloak_username\/employee_id mismatch/');
        $this->seed(PhaseSevenBrowserQaSeeder::class);
    }

    public function test_admin_throws_when_employee_id_mismatch(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::where('keycloak_username', $this->canonicalAdminUsername())->firstOrFail();
        $otherEmployee = Employee::factory()->create([
            'email' => 'other-admin@example.test',
            'status_aktif' => 'Aktif',
        ]);
        $user->employee_id = $otherEmployee->id;
        $user->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/admin User not found via employee_id|admin user role\/keycloak_username\/employee_id mismatch/');
        $this->seed(PhaseSevenBrowserQaSeeder::class);
    }

    public function test_admin_rerun_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(PhaseSevenBrowserQaSeeder::class);

        $countsBefore = [
            'users' => User::where('keycloak_username', $this->canonicalAdminUsername())->count(),
            'employees' => Employee::whereRaw('lower(email) = ?', [strtolower($this->canonicalAdminEmail())])->count(),
        ];

        $this->seed(PhaseSevenBrowserQaSeeder::class);

        $countsAfter = [
            'users' => User::where('keycloak_username', $this->canonicalAdminUsername())->count(),
            'employees' => Employee::whereRaw('lower(email) = ?', [strtolower($this->canonicalAdminEmail())])->count(),
        ];

        $this->assertSame($countsBefore, $countsAfter);
        $this->assertSame(1, $countsAfter['users']);
        $this->assertSame(1, $countsAfter['employees']);
    }
}
