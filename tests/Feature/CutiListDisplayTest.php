<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
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
}
