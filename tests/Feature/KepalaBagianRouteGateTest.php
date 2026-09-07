<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KepalaBagianRouteGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_non_kepala_bagian_roles_cannot_open_kepala_bagian_urls_directly(): void
    {
        $users = [
            User::factory()->superAdmin()->create(),
            User::factory()->adminKepegawaian()->create(),
            User::factory()->pimpinan()->create(),
            User::factory()->pegawai()->create(),
        ];
        $routes = [
            route('kepala-bagian.dashboard'),
            route('kepala-bagian.bawahan.index'),
            route('kepala-bagian.ews.index'),
        ];

        foreach ($users as $user) {
            foreach ($routes as $route) {
                $this->actingAs($user)->get($route)->assertForbidden();
            }
        }
    }

    public function test_duty_postponement_route_rejects_every_non_kepala_bagian_role_before_mutation(): void
    {
        $leave = LeaveRequest::create([
            'employee_id' => Employee::factory()->create()->id,
            'jenis_cuti_id' => RefJenisCuti::create([
                'nama' => 'Cuti Tahunan Gate', 'code' => 'tahunan',
                'mengurangi_saldo_tahunan' => true, 'khusus_pns' => false,
            ])->id,
            'tanggal_mulai' => '2026-08-03', 'tanggal_selesai' => '2026-08-07',
            'jumlah_hari_kerja' => 5, 'alasan' => 'Cuti tahunan.', 'status' => 'menunggu_approval',
        ]);
        $users = [
            User::factory()->superAdmin()->create(),
            User::factory()->adminKepegawaian()->create(),
            User::factory()->pimpinan()->create(),
            User::factory()->pegawai()->create(),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)
                ->post(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $leave), [
                    'active_step_id' => '00000000-0000-4000-8000-000000000001',
                    'revision_version' => $leave->fresh()->revision_version,
                    'alasan' => 'Penugasan mendesak mewakili instansi.',
                ])
                ->assertForbidden();
            $this->assertSame('menunggu_approval', $leave->fresh()->status);
        }
    }
}
