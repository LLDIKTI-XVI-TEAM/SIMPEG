<?php

namespace Tests\Feature;

use App\Actions\Cuti\ShowLeaveBalanceAdminAction;
use App\Models\Employee;
use App\Models\LeaveUsageRecord;
use App\Models\Permission;
use App\Models\PositionHistory;
use App\Models\RefJenisCuti;
use App\Models\RefUnitKerja;
use App\Models\Role;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManualLeaveUsageScopePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-07 10:00:00');
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[DataProvider('globalRoles')]
    public function test_grant_manual_membuka_daftar_global_editor_dan_menu(string $role): void
    {
        $actor = $this->actor($role);
        $this->grant($role, 'cuti.manual.manage');
        $first = Employee::factory()->create(['nama_lengkap' => 'Scope Global Pertama']);
        $second = Employee::factory()->create(['nama_lengkap' => 'Scope Global Kedua']);

        $response = $this->actingAs($actor)->get(route('cuti.saldo.administrasi', [
            'status' => 'semua_pegawai',
            'search' => 'Scope Global',
        ]))->assertOk();

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $response->viewData('employeeRows')->pluck('employee_id')->all());
        $this->assertSame(2, $response->viewData('statusCounts')['semua_pegawai']);
        $response->assertSee('href="'.route('cuti.saldo.administrasi').'"', false);

        $this->get(route('cuti.saldo.administrasi', ['pegawai' => $second->id, 'tab' => 'manual']))
            ->assertOk()
            ->assertViewHas('canManageManual', true)
            ->assertSee('action="'.route('cuti.manual.store', $second).'"', false);
    }

    public function test_kabag_hanya_melihat_bawahan_efektif_pada_daftar_count_dan_pencarian(): void
    {
        $actor = $this->actor('kepala_bagian');
        $this->grant('kepala_bagian', 'cuti.manual.manage');
        $direct = Employee::factory()->create(['nama_lengkap' => 'Scope Bawahan Efektif']);
        $future = Employee::factory()->create(['nama_lengkap' => 'Scope Bawahan Mendatang', 'kepala_bagian_id' => $actor->employee_id]);
        $ended = Employee::factory()->create(['nama_lengkap' => 'Scope Bawahan Berakhir', 'kepala_bagian_id' => $actor->employee_id]);
        $peer = Employee::factory()->create(['nama_lengkap' => 'Scope Rekan Satu Unit']);
        $unit = RefUnitKerja::query()->firstOrFail();
        foreach ([$actor->employee, $direct, $peer] as $employee) {
            PositionHistory::query()->create([
                'employee_id' => $employee->id,
                'unit_kerja_id' => $unit->id,
                'nama_jabatan' => 'Pelaksana pengujian scope',
                'tmt_jabatan' => '2026-01-01',
                'is_latest' => true,
            ]);
        }
        $this->assign($direct, $actor, '2026-09-01');
        $this->assign($future, $actor, '2026-09-08');
        $this->assign($ended, $actor, '2026-08-01', '2026-09-06');

        $response = $this->actingAs($actor)->get(route('cuti.saldo.administrasi', ['status' => 'semua_pegawai']))->assertOk();

        $this->assertSame([$direct->id], $response->viewData('employeeRows')->pluck('employee_id')->all());
        $this->assertSame(['perlu_tindakan' => 1, 'sudah_terdaftar' => 0, 'semua_pegawai' => 1], $response->viewData('statusCounts'));
        foreach ([$future, $ended, $peer] as $foreign) {
            $response->assertDontSee($foreign->nama_lengkap);
            $this->get(route('cuti.saldo.administrasi', ['pegawai' => $foreign->id]))->assertNotFound();
        }

        $search = $this->get(route('cuti.saldo.administrasi', ['search' => $peer->nama_lengkap]))->assertOk();
        $this->assertSame(0, $search->viewData('employeeRows')->total());
        $this->assertSame(0, $search->viewData('statusCounts')['semua_pegawai']);
        $response->assertSee('href="'.route('cuti.saldo.administrasi').'"', false);
        $response->assertDontSee('href="'.route('cuti.rekap').'"', false);
    }

    public function test_pegawai_hanya_melihat_diri_sendiri_dan_menolak_uuid_editor_asing(): void
    {
        $actor = $this->actor('pegawai');
        $this->grant('pegawai', 'cuti.manual.manage');
        $foreign = Employee::factory()->create(['nama_lengkap' => 'Pegawai Rahasia Asing']);
        $record = $this->manualFact($foreign, $actor);

        $response = $this->actingAs($actor)->get(route('cuti.saldo.administrasi', ['status' => 'semua_pegawai']))->assertOk();

        $this->assertSame([$actor->employee_id], $response->viewData('employeeRows')->pluck('employee_id')->all());
        $this->assertSame(1, $response->viewData('statusCounts')['semua_pegawai']);
        $response->assertDontSee($foreign->nama_lengkap);
        $response->assertSee('href="'.route('cuti.saldo.administrasi').'"', false);
        $response->assertDontSee('href="'.route('cuti.rekap').'"', false);
        $this->get(route('cuti.saldo.administrasi', ['pegawai' => $foreign->id]))->assertNotFound();
        $this->get(route('cuti.saldo.administrasi', [
            'pegawai' => $actor->employee_id,
            'tab' => 'manual',
            'edit_usage' => $record->id,
        ]))->assertNotFound();
        $this->get(route('cuti.saldo.administrasi', ['edit_usage' => $record->id]))->assertNotFound();
        $this->get(route('cuti.saldo.administrasi', ['pegawai' => $actor->employee_id, 'tab' => 'manual']))
            ->assertOk()
            ->assertSee('action="'.route('cuti.manual.store', $actor->employee_id).'"', false);
    }

    #[DataProvider('allRoles')]
    public function test_revoke_manual_dan_read_menolak_halaman_serta_menyembunyikan_menu(string $role): void
    {
        $actor = $this->actor($role);
        $this->grant($role, 'cuti.manual.manage');
        $dashboardRoute = match ($role) {
            'pimpinan' => 'pimpinan.dashboard',
            'kepala_bagian' => 'kepala-bagian.dashboard',
            default => 'dashboard',
        };
        $this->actingAs($actor)->get(route($dashboardRoute))->assertOk()
            ->assertSee('href="'.route('cuti.saldo.administrasi').'"', false);

        $this->revoke($role, ['cuti.manual.manage', 'cuti.balance.reconcile', 'cuti.balance.read']);

        $this->get(route('cuti.saldo.administrasi'))->assertForbidden();
        $this->get(route($dashboardRoute))->assertOk()
            ->assertDontSee('href="'.route('cuti.saldo.administrasi').'"', false);
    }

    public function test_action_langsung_menolak_aktor_tanpa_permission(): void
    {
        $actor = $this->actor('pegawai');
        $this->revoke('pegawai', ['cuti.manual.manage', 'cuti.balance.reconcile', 'cuti.balance.read']);

        $this->expectException(AuthorizationException::class);

        app(ShowLeaveBalanceAdminAction::class)->execute([], $actor);
    }

    public function test_action_langsung_membaca_revoke_setelah_capability_tercache_pada_request_yang_sama(): void
    {
        $actor = $this->actor('pegawai');
        $this->grant('pegawai', 'cuti.manual.manage');
        $action = app(ShowLeaveBalanceAdminAction::class);
        $this->assertTrue($action->execute([], $actor)['canManageManual']);
        $this->revoke('pegawai', ['cuti.manual.manage', 'cuti.balance.reconcile', 'cuti.balance.read']);

        $this->expectException(AuthorizationException::class);

        $action->execute([], $actor);
    }

    public function test_permission_reconcile_existing_tetap_memberi_halaman_baca_saja(): void
    {
        $actor = $this->actor('admin_kepegawaian');
        $employee = Employee::factory()->create();
        $this->revoke('admin_kepegawaian', ['cuti.manual.manage']);
        $this->grant('admin_kepegawaian', 'cuti.balance.reconcile');

        $this->actingAs($actor)->get(route('cuti.saldo.administrasi', ['pegawai' => $employee->id, 'tab' => 'manual']))
            ->assertOk()
            ->assertViewHas('canManageManual', false)
            ->assertDontSee('action="'.route('cuti.manual.store', $employee).'"', false)
            ->assertSee('href="'.route('cuti.saldo.administrasi').'"', false);
    }

    public function test_rekap_pimpinan_menyediakan_tautan_administrasi_sesuai_permission(): void
    {
        $actor = $this->actor('pimpinan');
        $this->grant('pimpinan', 'cuti.manual.manage');

        $this->actingAs($actor)->get(route('cuti.rekap'))->assertOk()
            ->assertViewHas('canAdministerBalance', true);
    }

    #[DataProvider('manualEditorMutations')]
    public function test_redirect_editor_setelah_mutasi_berhasil_tetap_menampilkan_riwayat_baca_saja(string $action, string $status): void
    {
        $actor = $this->actor('pegawai');
        $this->grant('pegawai', 'cuti.manual.manage');
        $payload = [
            'leave_type_id' => RefJenisCuti::query()->where('code', 'sakit')->firstOrFail()->id,
            'tanggal_mulai' => '2026-08-03',
            'tanggal_selesai' => '2026-08-04',
            'alasan' => 'Fakta persetujuan cuti sakit di luar SIMPEG.',
            'approval_steps' => $this->validManualApprovalPayload(),
        ];
        $this->actingAs($actor)->post(route('cuti.manual.store', $actor->employee_id), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $record = LeaveUsageRecord::query()->where('employee_id', $actor->employee_id)->sole();
        $editorUrl = route('cuti.saldo.administrasi', [
            'pegawai' => $actor->employee_id,
            'tab' => 'manual',
            'edit_usage' => $record->id,
            'manual_action' => $action,
        ]);
        $this->get($editorUrl)->assertOk()
            ->assertViewHas('editableUsage', fn ($usage): bool => $usage?->id === $record->id);
        $mutationPayload = $action === 'correct'
            ? array_replace($payload, ['tanggal_selesai' => '2026-08-03', 'correction_reason' => 'Perbaikan tanggal selesai fakta.'])
            : ['correction_reason' => 'Pembatalan fakta yang keliru dicatat.'];

        $response = $this->from($editorUrl)->post(route('cuti.manual.'.$action, $record), $mutationPayload)
            ->assertRedirect($editorUrl)
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $this->assertSame($status, $record->refresh()->record_status);
        $this->followRedirects($response)->assertOk()
            ->assertSee($action === 'correct'
                ? 'Pemakaian cuti manual berhasil dikoreksi.'
                : 'Pemakaian cuti manual berhasil dibatalkan.')
            ->assertSessionHasNoErrors()
            ->assertViewHas('editableUsage', fn ($usage): bool => $usage === null);
    }

    /** @return iterable<string, array{string, string}> */
    public static function manualEditorMutations(): iterable
    {
        yield 'koreksi' => ['correct', LeaveUsageRecord::STATUS_SUPERSEDED];
        yield 'pembatalan' => ['cancel', LeaveUsageRecord::STATUS_CANCELLED];
    }

    /** @return iterable<string, array{string}> */
    public static function globalRoles(): iterable
    {
        yield 'Super Admin' => ['super_admin'];
        yield 'Admin Kepegawaian' => ['admin_kepegawaian'];
        yield 'Pimpinan' => ['pimpinan'];
    }

    /** @return iterable<string, array{string}> */
    public static function allRoles(): iterable
    {
        yield from self::globalRoles();
        yield 'Kepala Bagian' => ['kepala_bagian'];
        yield 'Pegawai' => ['pegawai'];
    }

    private function actor(string $role): User
    {
        return User::factory()->create(['role' => $role, 'employee_id' => Employee::factory()->create()->id]);
    }

    private function grant(string $role, string $permission): void
    {
        Role::query()->where('name', $role)->firstOrFail()->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', $permission)->firstOrFail()->id,
        ]);
    }

    /** @param list<string> $permissions */
    private function revoke(string $role, array $permissions): void
    {
        Role::query()->where('name', $role)->firstOrFail()->permissions()->detach(
            Permission::query()->whereIn('name', $permissions)->pluck('id'),
        );
    }

    private function assign(Employee $employee, User $actor, string $start, ?string $end = null): void
    {
        SupervisorAssignment::query()->create([
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $actor->employee_id,
            'tanggal_mulai' => $start,
            'tanggal_berakhir' => $end,
        ]);
    }

    private function manualFact(Employee $employee, User $actor): LeaveUsageRecord
    {
        return LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail()->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'usage_year' => 2026,
            'effective_date' => '2026-09-01',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
            'workdays' => 1,
            'administrative_note' => 'Fakta privat pegawai di luar scope.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $actor->id,
        ]);
    }
}
