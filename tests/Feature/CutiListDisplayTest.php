<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\RefJenisCuti;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menjaga daftar cuti menampilkan status resmi dan langkah aktif dinamis, bukan slot tetap Atasan/Kepala.
 */
class CutiListDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_list_rows_expose_current_step_label_from_the_active_snapshot(): void
    {
        $user = User::factory()->superAdmin()->create();
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $approver = Employee::factory()->create(['nama_lengkap' => 'Budi Kabag']);
        $employee = Employee::factory()->create();

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-12',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Uji langkah aktif',
            'status' => 'menunggu_approval',
        ]);
        $leaveRequest->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => false,
        ]);

        $this->actingAs($user);
        $response = $this->get(route('cuti'));

        $response->assertOk();
        $riwayatCuti = $response->viewData('riwayatCuti');
        $row = $riwayatCuti->first();

        $this->assertArrayHasKey('current_step_label', $row);
        $this->assertSame('Atasan Langsung', $row['current_step_label']);
        $this->assertArrayNotHasKey('current_step', $row);
        $this->assertArrayNotHasKey('stage_atasan', $row);
        $this->assertArrayNotHasKey('stage_kepala', $row);
    }

    public function test_completed_list_row_exposes_a_null_current_step_label(): void
    {
        $user = User::factory()->superAdmin()->create();
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Sakit Kontrak Tahap Terminal',
            'code' => 'sakit-kontrak-tahap-terminal',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leaveRequest = $this->createLeave(
            Employee::factory()->create(['nama_lengkap' => 'Pegawai Tahap Terminal Admin']),
            $jenis,
            'Uji tahap terminal Admin',
            'disetujui',
        );

        $response = $this->actingAs($user)->get(route('cuti'));
        $row = $response->viewData('riwayatCuti')->getCollection()->firstWhere('id', $leaveRequest->id);

        $response->assertOk();
        $this->assertSame(1, $response->viewData('riwayatCuti')->total());
        $this->assertArrayHasKey('current_step_label', $row);
        $this->assertNull($row['current_step_label']);
        $this->assertArrayNotHasKey('current_step', $row);
    }

    public function test_list_renders_active_role_label_and_dash_for_a_terminal_row(): void
    {
        $user = User::factory()->superAdmin()->create();
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Kontrak Tampilan Tahap Admin',
            'code' => 'cuti-kontrak-tampilan-tahap-admin',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $activeLeave = $this->createLeave(
            Employee::factory()->create(['nama_lengkap' => 'Pegawai Tahap Aktif Admin']),
            $jenis,
            'Baris tahap aktif Admin',
            'menunggu_approval',
        );
        $activeLeave->steps()->create([
            'step_order' => 1,
            'step_type' => 'verifikator_kontrak_admin',
            'role_label' => 'Verifikator Kontrak Admin',
            'approver_employee_id' => Employee::factory()->create()->id,
            'status' => 'active',
            'is_final' => false,
        ]);
        $this->createLeave(
            Employee::factory()->create(['nama_lengkap' => 'Pegawai Tanpa Tahap Admin']),
            $jenis,
            'Baris tanpa tahap Admin',
            'disetujui',
        );

        $response = $this->actingAs($user)->get(route('cuti'));

        $response->assertOk()
            ->assertSee('Verifikator Kontrak Admin')
            ->assertSee('Langkah Aktif');
        $this->assertMatchesRegularExpression(
            '/data-nama="Pegawai Tanpa Tahap Admin".*?<span class="text-muted">-<\/span>/s',
            $response->getContent(),
        );
    }

    public function test_empty_list_renders_clear_empty_state(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->get(route('cuti'))
            ->assertOk()
            ->assertSee('Belum ada pengajuan cuti yang sesuai dengan filter.', false);
    }

    public function test_list_uses_official_perubahan_label(): void
    {
        $user = User::factory()->superAdmin()->create();
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $employee = Employee::factory()->create();

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-12',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Uji label perubahan',
            'status' => 'perlu_perubahan',
        ]);

        $response = $this->actingAs($user)->get(route('cuti'));

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/<tr\\b[^>]*\\bdata-status="perlu_perubahan"[^>]*>.*?Perubahan/s',
            $response->getContent(),
        );
        $response->assertDontSee('Perlu Perubahan');
    }

    public function test_list_renders_and_filters_duty_postponement_with_consistent_counter(): void
    {
        $user = User::factory()->superAdmin()->create();
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan Rule 3',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $this->createLeave(
            Employee::factory()->create(['nama_lengkap' => 'Pegawai Tugas Dinas']),
            $jenis,
            'Penangguhan terminal Rule 3',
            LeaveRequest::STATUS_DUTY_POSTPONED,
        );
        $this->createLeave(
            Employee::factory()->create(['nama_lengkap' => 'Pegawai Ditangguhkan Biasa']),
            $jenis,
            'Penangguhan sementara biasa',
            'ditangguhkan',
        );

        $response = $this->actingAs($user)->get(route('cuti', [
            'status' => LeaveRequest::STATUS_DUTY_POSTPONED,
        ]));

        $response->assertOk()
            ->assertSee('Pegawai Tugas Dinas')
            ->assertSee('Ditangguhkan karena Tugas Dinas')
            ->assertDontSee('Pegawai Ditangguhkan Biasa')
            ->assertViewHas('jumlahDitangguhkan', 2);
    }

    public function test_list_renders_and_filters_rollover_return_as_a_non_approval_status(): void
    {
        $user = User::factory()->superAdmin()->create();
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan Rollover',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $returned = $this->createLeave(
            Employee::factory()->create(['nama_lengkap' => 'Pegawai Rollover']),
            $jenis,
            'Pengajuan harus dipindahkan ke tahun target.',
            'dikembalikan_karena_rollover',
        );
        $returned->forceFill([
            'rollover_source_year' => 2026,
            'rollover_target_year' => 2027,
        ])->save();
        $this->createLeave(
            Employee::factory()->create(['nama_lengkap' => 'Pegawai Menunggu']),
            $jenis,
            'Pengajuan reguler.',
            'menunggu_approval',
        );

        $response = $this->actingAs($user)->get(route('cuti', [
            'status' => 'dikembalikan_karena_rollover',
        ]));

        $response->assertOk()
            ->assertSee('Pegawai Rollover')
            ->assertSee('Dikembalikan karena Rollover')
            ->assertDontSee('Pegawai Menunggu');
        $this->assertSame(1, $response->viewData('jumlahMenunggu'));
    }

    public function test_pegawai_only_sees_own_rows_and_counters(): void
    {
        [$user, $employee, $peer, $jenis] = $this->makePegawaiContext();

        $this->createLeave($employee, $jenis, 'Pengajuan sendiri menunggu', 'menunggu_approval');
        $this->createLeave($employee, $jenis, 'Pengajuan sendiri disetujui', 'disetujui');
        $this->createLeave($employee, $jenis, 'Pengajuan sendiri ditangguhkan', 'ditangguhkan');
        $this->createLeave($employee, $jenis, 'Pengajuan sendiri perubahan', 'perlu_perubahan');
        $this->createLeave($peer, $jenis, 'Pengajuan rekan rahasia', 'menunggu_approval');

        $response = $this->actingAs($user)->get(route('cuti'));

        $response->assertOk()
            ->assertSee('Pengajuan sendiri menunggu')
            ->assertDontSee('Pengajuan rekan rahasia')
            ->assertViewHas('totalPengajuan', 4)
            ->assertViewHas('jumlahMenunggu', 1)
            ->assertViewHas('jumlahDisetujui', 1)
            ->assertViewHas('jumlahDitangguhkan', 1);
        $this->assertSame(1, $response->viewData('riwayatCuti')->where('status', 'menunggu_approval')->count());
    }

    public function test_role_pegawai_stays_own_scoped_even_with_read_all_permission(): void
    {
        [$user, $employee, $peer, $jenis] = $this->makePegawaiContext();
        Role::query()->where('name', 'pegawai')->firstOrFail()->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'cuti.read_all')->firstOrFail()->id,
        ]);
        $this->createLeave($employee, $jenis, 'Cuti milik pegawai', 'disetujui');
        $this->createLeave($peer, $jenis, 'Cuti rekan tidak boleh bocor', 'disetujui');

        $response = $this->actingAs($user)->get(route('cuti'));

        $response->assertOk()
            ->assertSee('Cuti milik pegawai')
            ->assertDontSee('Cuti rekan tidak boleh bocor')
            ->assertViewHas('totalPengajuan', 1);
    }

    public function test_pegawai_global_query_parameters_cannot_reveal_peer_record(): void
    {
        [$user, $employee, $peer, $jenis] = $this->makePegawaiContext();
        $this->createLeave($employee, $jenis, 'Cuti pribadi aman', 'menunggu_approval');
        $this->createLeave($peer, $jenis, 'Cuti target pencarian', 'menunggu_approval');

        $response = $this->actingAs($user)->get(route('cuti', [
            'search' => $peer->nama_lengkap,
            'unit' => $peer->jabatan_terakhir,
        ]));

        $response->assertOk()
            ->assertSee('Cuti pribadi aman')
            ->assertDontSee('Cuti target pencarian')
            ->assertViewHas('search', '')
            ->assertViewHas('unit', '')
            ->assertViewHas('optUnits', fn ($units): bool => $units->isEmpty());
    }

    public function test_pegawai_sees_personal_copy_create_cta_and_reduced_columns(): void
    {
        [$user, $employee, , $jenis] = $this->makePegawaiContext();
        $this->createLeave($employee, $jenis, 'Cuti tampilan pribadi', 'menunggu_approval');

        $response = $this->actingAs($user)->get(route('cuti'));
        $content = $response->getContent();

        $response->assertOk()
            ->assertSee('Riwayat Pengajuan Cuti Saya')
            ->assertSee('Ajukan Cuti Baru')
            ->assertSee('href="'.route('cuti.create').'"', false)
            ->assertSee('Total Pengajuan')
            ->assertSee('Seluruh pengajuan Anda')
            ->assertDontSee('name="search"', false)
            ->assertDontSee('name="unit"', false);
        $this->assertDoesNotMatchRegularExpression('/<th\b[^>]*>\s*Pegawai\s*<\/th>/s', $content);
        $this->assertDoesNotMatchRegularExpression('/<th\b[^>]*>\s*Unit Kerja\s*<\/th>/s', $content);
    }

    public function test_global_monitor_keeps_global_copy_filters_and_columns(): void
    {
        $user = User::factory()->superAdmin()->create();

        $response = $this->actingAs($user)->get(route('cuti'));
        $content = $response->getContent();

        $response->assertOk()
            ->assertSee('Monitoring Cuti')
            ->assertSee('name="search"', false)
            ->assertSee('name="unit"', false);
        $this->assertMatchesRegularExpression('/<th\b[^>]*>\s*Pegawai\s*<\/th>/s', $content);
        $this->assertMatchesRegularExpression('/<th\b[^>]*>\s*Unit Kerja\s*<\/th>/s', $content);
    }

    public function test_super_admin_tidak_melihat_cta_pengajuan_cuti(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)->get(route('cuti'))
            ->assertOk()
            ->assertDontSee('Ajukan Cuti Baru')
            ->assertDontSee('href="'.route('cuti.create').'"', false);
    }

    public function test_kepala_lembaga_tidak_melihat_cta_pengajuan_cuti(): void
    {
        $employee = Employee::factory()->create(['is_kepala_lembaga' => true]);
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->actingAs($user)->get(route('cuti'))
            ->assertOk()
            ->assertDontSee('Ajukan Cuti Baru')
            ->assertDontSee('href="'.route('cuti.create').'"', false);
    }

    /** @return array{User, Employee, Employee, RefJenisCuti} */
    private function makePegawaiContext(): array
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Pemilik',
            'jabatan_terakhir' => 'Unit Pemilik',
        ]);
        $peer = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Rekan',
            'jabatan_terakhir' => 'Unit Rekan',
        ]);
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Sakit',
            'code' => 'sakit',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);

        return [$user, $employee, $peer, $jenis];
    }

    private function createLeave(Employee $employee, RefJenisCuti $jenis, string $alasan, string $status): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-12',
            'jumlah_hari_kerja' => 3,
            'alasan' => $alasan,
            'status' => $status,
        ]);
    }

    public function test_simulated_pegawai_is_scoped_to_own_leave_even_when_read_all_granted(): void
    {
        // Pegawai sengaja diberi cuti.read_all (salah konfigurasi) untuk memastikan pengaman
        // scope data-milik-sendiri memakai role efektif, bukan role asli penyerang.
        $pegawaiRole = Role::where('name', 'pegawai')->firstOrFail();
        $readAll = Permission::where('name', 'cuti.read_all')->firstOrFail();
        $pegawaiRole->permissions()->syncWithoutDetaching([$readAll->id]);

        $ownEmployee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);

        $ownLeave = LeaveRequest::create([
            'employee_id' => $ownEmployee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-01',
            'tanggal_selesai' => '2026-08-02',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Cuti milik sendiri',
            'status' => 'menunggu_approval',
        ]);
        LeaveRequest::create([
            'employee_id' => $otherEmployee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-03',
            'tanggal_selesai' => '2026-08-04',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Cuti pegawai lain',
            'status' => 'menunggu_approval',
        ]);

        $user = User::factory()->superAdmin()->create(['employee_id' => $ownEmployee->id]);

        // Simulasi pegawai: role efektif = pegawai, meski role asli tetap super_admin.
        $this->actingAs($user)->post(route('switch-role'), ['target_role' => 'pegawai']);
        $user->refresh();
        $this->assertEquals('pegawai', $user->getEffectiveRole());
        $this->assertTrue($user->hasPermission('cuti.read_all'));

        $response = $this->actingAs($user)->get(route('cuti'));
        $response->assertOk();

        // Scope data-milik-sendiri harus tetap membatasi ke cuti milik sendiri.
        $riwayat = $response->viewData('riwayatCuti');
        $this->assertSame(1, $riwayat->total());
        $this->assertSame($ownLeave->id, $riwayat->getCollection()->first()['id']);
    }
}
