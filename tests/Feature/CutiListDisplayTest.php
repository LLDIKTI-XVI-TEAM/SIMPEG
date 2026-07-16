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

    public function test_waiting_row_shows_dynamic_current_step_label(): void
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

        $this->assertSame('Kepala Bagian', $row['current_step']);
        $this->assertArrayNotHasKey('stage_atasan', $row);
        $this->assertArrayNotHasKey('stage_kepala', $row);
        // The waiting cell renders "Menunggu <strong>Kepala Bagian</strong>"; assert both fragments.
        $response->assertSee('Menunggu', false);
        $response->assertSee('Kepala Bagian', false);
        // The stale two-slot markup must be gone.
        $response->assertDontSee('stage_atasan', false);
        $response->assertDontSee('stage_kepala', false);
    }

    public function test_empty_list_renders_clear_empty_state(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->get(route('cuti'))
            ->assertOk()
            ->assertSee('Belum ada pengajuan cuti yang sesuai dengan filter.', false);
    }

    public function test_list_uses_official_perlu_perubahan_label(): void
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
            '/<tr\\b[^>]*\\bdata-status="perlu_perubahan"[^>]*>.*?Perlu Perubahan/s',
            $response->getContent(),
        );
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
            ->assertSee('Monitoring Cuti Pegawai')
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
}
