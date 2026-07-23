<?php

namespace Tests\Feature;

use App\Actions\Cuti\SubmitLeaveRequestAction;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Menjaga antrean approval cuti dipaginasi di database, diurutkan stabil, dan tidak melakukan query per-baris.
 */
class CutiApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_approval_queue_paginates_and_scopes_to_active_approver(): void
    {
        $approverEmployee = Employee::factory()->create(['nama_lengkap' => 'Approver Aktif']);
        $approverUser = User::factory()->superAdmin()->create(['employee_id' => $approverEmployee->id]);
        $otherApprover = Employee::factory()->create();

        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);

        // 15 requests waiting on this approver, 3 waiting on someone else.
        foreach (range(1, 15) as $i) {
            $this->makeWaitingRequest($jenis, $approverEmployee, "Butuh approver aktif {$i}");
        }
        foreach (range(1, 3) as $i) {
            $this->makeWaitingRequest($jenis, $otherApprover, "Approver lain {$i}");
        }

        $queries = [];
        DB::listen(function (QueryExecuted $q) use (&$queries): void {
            $queries[] = $q->sql;
        });

        $this->actingAs($approverUser);
        $response = $this->get(route('cuti.approval'));

        $response->assertOk();
        $response->assertViewHas('pending');
        $pending = $response->viewData('pending');

        // Database pagination: default 10 per page, only this approver's requests count.
        $this->assertSame(15, $pending->total());
        $this->assertLessThanOrEqual(10, $pending->count());

        // Query-count ceiling: no per-row active-step lookups.
        $this->assertLessThan(15, count($queries), 'Antrean approval tidak boleh melakukan query per baris.');
    }

    public function test_approval_queue_ordering_is_stable_across_pages(): void
    {
        $approverEmployee = Employee::factory()->create();
        $approverUser = User::factory()->superAdmin()->create(['employee_id' => $approverEmployee->id]);
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);

        // Create 15 waiting requests with the SAME created_at so ordering must tie-break deterministically by id.
        $sameTimestamp = now()->subDay();
        $ids = [];
        foreach (range(1, 15) as $i) {
            $ids[] = $this->makeWaitingRequest($jenis, $approverEmployee, "Antre {$i}", $sameTimestamp);
        }

        $this->actingAs($approverUser);

        $page1 = $this->get(route('cuti.approval', ['per_page' => 10, 'page' => 1]));
        $page2 = $this->get(route('cuti.approval', ['per_page' => 10, 'page' => 2]));
        $page1->assertOk();
        $page2->assertOk();

        $page1Ids = $page1->viewData('pending')->pluck('id')->all();
        $page2Ids = $page2->viewData('pending')->pluck('id')->all();

        // No overlap between pages and full coverage => deterministic ordering across pagination.
        $this->assertCount(10, $page1Ids);
        $this->assertCount(5, $page2Ids);
        $this->assertEmpty(array_intersect($page1Ids, $page2Ids), 'Halaman tidak boleh tumpang tindih; urutan harus deterministik.');
        $this->assertEqualsCanonicalizing($ids, array_merge($page1Ids, $page2Ids));
    }

    public function test_approval_queue_requires_keyboard_dismissible_confirmation_before_approve(): void
    {
        $approverEmployee = Employee::factory()->create();
        $approverUser = User::factory()->superAdmin()->create(['employee_id' => $approverEmployee->id]);
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $this->makeWaitingRequest($jenis, $approverEmployee, 'Konfirmasi setuju');

        $this->actingAs($approverUser)
            ->get(route('cuti.approval'))
            ->assertOk()
            ->assertSee('Ya, setujui', false)
            ->assertSee('Batal', false)
            ->assertSee('@keydown.escape="close()"', false)
            ->assertSee('@click="open($event)"', false)
            ->assertSee('$refs.confirmApprove?.focus()', false)
            ->assertSee('lastTrigger: null', false)
            ->assertSee('this.lastTrigger?.focus()', false);
    }

    public function test_pengajuan_sendiri_tidak_masuk_antrean_approval_pemohon(): void
    {
        $pemohon = Employee::factory()->create();
        $approver = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $pemohon->id]);
        // Kepala Bagian efektif kebetulan pemohon sendiri; resolver lolos gerbang lalu langkah pemohon disaring dari antrean.
        SupervisorAssignment::create([
            'employee_id' => $pemohon->id,
            'kepala_bagian_id' => $pemohon->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Sakit Mandiri',
            'code' => 'sakit_mandiri',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $chain = LeaveApprovalChain::create([
            'employee_id' => $pemohon->id,
            'name' => 'Chain konflik kepentingan',
            'effective_from' => '2026-01-01',
        ]);
        $chain->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Pemohon',
                'approver_employee_id' => $pemohon->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $approver->id,
                'is_final' => true,
            ],
        ]);
        $request = Request::create('/dashboard/cuti', 'POST');
        $request->setUserResolver(fn () => $user);

        app(SubmitLeaveRequestAction::class)->execute($pemohon, [
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-12',
            'alasan' => 'Uji antrean sendiri.',
            'alamat_selama_cuti' => 'Jl. Uji',
            'nomor_telepon' => '+62 123',
        ], $request);

        $response = $this->actingAs($user)->get(route('cuti.approval'));

        $response->assertOk();
        $this->assertSame(0, $response->viewData('pending')->total());
    }

    private function makeWaitingRequest(RefJenisCuti $jenis, Employee $approver, string $alasan, ?Carbon $createdAt = null): string
    {
        $employee = Employee::factory()->create();
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-12',
            'jumlah_hari_kerja' => 3,
            'alasan' => $alasan,
            'status' => 'menunggu_approval',
        ]);
        if ($createdAt !== null) {
            // Force an identical created_at to prove the id tie-break keeps ordering deterministic.
            $leaveRequest->forceFill(['created_at' => $createdAt])->save();
        }
        $leaveRequest->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => false,
        ]);

        return $leaveRequest->id;
    }
}
