<?php

namespace Tests\Feature;

use App\Actions\Cuti\ReconcileAnnualLeaveUsageAction;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\SimpegNotification;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Menguji perjalanan lengkap pengajuan cuti melalui endpoint web sebenarnya:
 * pegawai mengajukan, Kepala Bagian memutus lewat halaman kabag, PYBMC memutus
 * final lewat halaman pimpinan, lalu saldo tahunan terpotong otomatis.
 */
class CutiEndToEndApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_alur_lengkap_pengajuan_kabag_pybmc_sampai_saldo_terpotong(): void
    {
        // === Setup struktur: pemohon, Kepala Bagian, PYBMC, chain, dan saldo awal ===
        $jenisPegawai = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $kabagEmployee = Employee::factory()->create(['nama_lengkap' => 'Kepala Bagian E2E']);
        $pybmcEmployee = Employee::factory()->create(['nama_lengkap' => 'Pejabat PYBMC E2E']);
        $pemohon = Employee::factory()->create([
            'nama_lengkap' => 'Pemohon E2E',
            'jenis_pegawai_id' => $jenisPegawai->id,
            'kepala_bagian_id' => $kabagEmployee->id,
        ]);

        Appointment::create([
            'employee_id' => $pemohon->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-E2E-001',
            'tanggal_sk' => '2020-01-01',
        ]);
        SupervisorAssignment::create([
            'employee_id' => $pemohon->id,
            'supervisor_id' => $kabagEmployee->id,
            'kepala_bagian_id' => $kabagEmployee->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);

        $chain = LeaveApprovalChain::create([
            'employee_id' => $pemohon->id,
            'name' => 'Chain E2E',
            'effective_from' => '2026-01-01',
            'change_reason' => 'Setup chain untuk uji alur lengkap.',
        ]);
        $chain->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $kabagEmployee->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $pybmcEmployee->id,
                'is_final' => true,
            ],
        ]);

        $pegawaiUser = User::factory()->pegawai()->create(['employee_id' => $pemohon->id]);
        $kabagUser = User::factory()->kepalaBagian()->create(['employee_id' => $kabagEmployee->id]);
        $pimpinanUser = User::factory()->pimpinan()->create(['employee_id' => $pybmcEmployee->id]);

        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $admin = User::factory()->adminKepegawaian()->create();
        app(ReconcileAnnualLeaveUsageAction::class)->execute(
            $pemohon->id,
            [
                'balance_year' => 2026,
                'usage_n2' => 12,
                'usage_n1' => 12,
                'usage_current' => 0,
                'administrative_note' => 'Rekonsiliasi saldo awal fixture alur lengkap.',
            ],
            $admin,
            $this->actorRequest($admin),
        );

        // === Tahap 1: pegawai mengajukan cuti (3-7 Agustus 2026 = 5 hari kerja) ===
        $this->actingAs($pegawaiUser)
            ->post(route('cuti.store'), [
                'jenis_cuti_id' => $jenis->id,
                'tanggal_mulai' => '2026-08-03',
                'tanggal_selesai' => '2026-08-07',
                'alasan' => 'Keperluan keluarga.',
                'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 1, Manado',
                'nomor_telepon' => '+62 (431) 123-456',
            ])
            ->assertRedirect(route('cuti'));

        $leave = LeaveRequest::firstOrFail();
        $this->assertSame('menunggu_approval', $leave->status);
        $this->assertSame(5, $leave->jumlah_hari_kerja);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'status' => 'active',
            'approver_employee_id' => $kabagEmployee->id,
        ]);
        // Approver pertama menerima notifikasi pengajuan baru.
        $this->assertTrue(SimpegNotification::query()->where('user_id', $kabagEmployee->id)->exists());

        // === Tahap 2: Kepala Bagian menyetujui lewat endpoint halaman kabag ===
        $this->actingAs($kabagUser)
            ->post(route('kepala-bagian.cuti.decision', $leave), [
                'active_step_id' => $leave->steps()->where('status', 'active')->valueOrFail('id'),
                'keputusan' => 'DISETUJUI',
            ])
            ->assertRedirect(route('kepala-bagian.cuti.show', $leave))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 2,
            'status' => 'active',
            'approver_employee_id' => $pybmcEmployee->id,
        ]);
        $this->assertSame('menunggu_approval', $leave->refresh()->status);
        // Saldo belum boleh terpotong sebelum keputusan final.
        $this->assertSame(0, LeaveBalance::query()
            ->where('employee_id', $pemohon->id)
            ->where('tahun', 2026)
            ->firstOrFail()
            ->terpakai);
        // PYBMC menerima notifikasi giliran memutus.
        $this->assertTrue(SimpegNotification::query()->where('user_id', $pybmcEmployee->id)->exists());

        // === Tahap 3: PYBMC menyetujui final lewat endpoint halaman pimpinan ===
        $this->actingAs($pimpinanUser)
            ->post(route('pimpinan.cuti.decision', $leave), [
                'active_step_id' => $leave->steps()->where('status', 'active')->valueOrFail('id'),
                'keputusan' => 'DISETUJUI',
                'catatan' => 'Disetujui.',
            ])
            ->assertRedirect(route('pimpinan.cuti.show', $leave));

        $leave->refresh();
        $this->assertSame('disetujui', $leave->status);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 2,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $leave->id,
            'approver_id' => $kabagEmployee->id,
            'action' => 'APPROVE',
        ]);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $leave->id,
            'approver_id' => $pybmcEmployee->id,
            'action' => 'APPROVE',
        ]);

        // === Hasil akhir: saldo terpotong otomatis, ledger dan bukti QR tercatat ===
        $balance = LeaveBalance::query()
            ->where('employee_id', $pemohon->id)
            ->where('tahun', 2026)
            ->firstOrFail();
        $this->assertSame(5, $balance->terpakai);
        $this->assertSame(7, $balance->sisa);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $pemohon->id,
        ]);
        $this->assertDatabaseHas('leave_proofs', [
            'leave_request_id' => $leave->id,
        ]);
        // Pemohon menerima notifikasi hasil keputusan final.
        $this->assertTrue(SimpegNotification::query()->where('user_id', $pemohon->id)->exists());
    }

    public static function jumlahVerifierProvider(): array
    {
        return [
            'satu verifier' => [1],
            'banyak verifier' => [2],
        ];
    }

    #[DataProvider('jumlahVerifierProvider')]
    public function test_alur_generic_mengaktifkan_verifier_sebelum_kepala_bagian_dan_pybmc(int $jumlahVerifier): void
    {
        $jenisPegawai = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $kepalaBagian = Employee::factory()->create(['nama_lengkap' => 'Kepala Bagian Dinamis']);
        $pybmc = Employee::factory()->create(['nama_lengkap' => 'PYBMC Dinamis']);
        $verifiers = collect(range(1, $jumlahVerifier))
            ->map(fn (int $urutan): Employee => Employee::factory()->create([
                'nama_lengkap' => "Verifikator Dinamis {$urutan}",
            ]));
        $pemohon = Employee::factory()->create([
            'nama_lengkap' => 'Pemohon Chain Dinamis',
            'jenis_pegawai_id' => $jenisPegawai->id,
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);

        Appointment::create([
            'employee_id' => $pemohon->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-E2E-DINAMIS',
            'tanggal_sk' => '2020-01-01',
        ]);
        SupervisorAssignment::create([
            'employee_id' => $pemohon->id,
            'supervisor_id' => $kepalaBagian->id,
            'kepala_bagian_id' => $kepalaBagian->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);

        $chain = LeaveApprovalChain::create([
            'employee_id' => $pemohon->id,
            'name' => 'Chain E2E verifier dinamis',
            'effective_from' => '2026-01-01',
            'change_reason' => 'Fixture alur verifier dinamis.',
        ]);
        $chain->steps()->createMany([
            ...$verifiers->values()->map(fn (Employee $verifier, int $index): array => [
                'step_order' => $index + 1,
                'step_type' => 'verifier',
                'role_label' => 'Verifikator '.($index + 1),
                'approver_employee_id' => $verifier->id,
                'is_final' => false,
            ])->all(),
            [
                'step_order' => $jumlahVerifier + 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $kepalaBagian->id,
                'is_final' => false,
            ],
            [
                'step_order' => $jumlahVerifier + 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $pybmc->id,
                'is_final' => true,
            ],
        ]);

        $pemohonUser = User::factory()->pegawai()->create(['employee_id' => $pemohon->id]);
        $approverEmployees = $verifiers->push($kepalaBagian)->push($pybmc)->values();
        $approverUsers = $approverEmployees->mapWithKeys(fn (Employee $approver): array => [
            $approver->id => User::factory()->pegawai()->create(['employee_id' => $approver->id]),
        ]);
        $jenis = RefJenisCuti::create([
            'nama' => "Cuti Sakit E2E {$jumlahVerifier} Verifier",
            'code' => "cuti_sakit_e2e_{$jumlahVerifier}",
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);

        $this->actingAs($pemohonUser)
            ->post(route('cuti.store'), [
                'jenis_cuti_id' => $jenis->id,
                'tanggal_mulai' => '2026-08-03',
                'tanggal_selesai' => '2026-08-04',
                'alasan' => 'Menguji urutan chain dinamis.',
                'alamat_selama_cuti' => 'Jl. E2E Dinamis, Manado',
                'nomor_telepon' => '+62 431 555',
            ])
            ->assertRedirect(route('cuti'));

        $leave = LeaveRequest::query()->sole();
        $steps = $leave->steps()->orderBy('step_order')->get();
        $expectedTypes = [
            ...array_fill(0, $jumlahVerifier, 'verifier'),
            'kepala_bagian',
            'pybmc',
        ];
        $this->assertSame($expectedTypes, $steps->pluck('step_type')->all());
        $this->assertSame(
            ['active', ...array_fill(0, $steps->count() - 1, 'pending')],
            $steps->pluck('status')->all(),
        );

        $initialNotification = SimpegNotification::query()
            ->where('user_id', $approverEmployees->firstOrFail()->id)
            ->where('type', 'cuti.pengajuan_baru')
            ->sole();
        $this->assertSame($leave->id, $initialNotification->data['leave_request_id'] ?? null);
        $this->assertSame($steps->firstOrFail()->id, $initialNotification->data['leave_request_step_id'] ?? null);

        foreach ($steps as $index => $step) {
            $approver = $approverEmployees[$index];
            $user = $approverUsers->get($approver->id);
            $this->assertInstanceOf(User::class, $user);

            if ($index + 1 < $steps->count()) {
                $nextStep = $steps[$index + 1];
                $this->assertFalse(SimpegNotification::query()
                    ->where('user_id', $nextStep->approver_employee_id)
                    ->where('type', 'cuti.menunggu_persetujuan')
                    ->where('data->leave_request_step_id', $nextStep->id)
                    ->exists());
            }

            $this->actingAs($user)
                ->post(route('cuti.approve', $leave->id), [
                    'active_step_id' => $step->id,
                    'komentar' => "Menyetujui tahap {$step->step_order}.",
                ])
                ->assertRedirect(route('cuti.approval'));

            $this->assertDatabaseHas('leave_request_steps', [
                'id' => $step->id,
                'status' => 'approved',
            ]);

            if ($index + 1 < $steps->count()) {
                $nextStep = $steps[$index + 1];
                $this->assertDatabaseHas('leave_request_steps', [
                    'id' => $nextStep->id,
                    'status' => 'active',
                ]);
                $nextNotification = SimpegNotification::query()
                    ->where('user_id', $nextStep->approver_employee_id)
                    ->where('type', 'cuti.menunggu_persetujuan')
                    ->where('data->leave_request_step_id', $nextStep->id)
                    ->sole();
                $this->assertSame($leave->id, $nextNotification->data['leave_request_id'] ?? null);
                $this->assertSame($nextStep->id, $nextNotification->data['leave_request_step_id'] ?? null);
            }
        }

        $this->assertSame('disetujui', $leave->refresh()->status);
        foreach ($approverEmployees as $approver) {
            $this->assertSame(1, $leave->approvals()->where('approver_id', $approver->id)->count());
        }
    }

    public static function duplicateApproverProvider(): array
    {
        return [
            'verifier juga Kepala Bagian' => ['verifier_kepala_bagian'],
            'verifier juga PYBMC' => ['verifier_pybmc'],
            'Kepala Bagian juga PYBMC' => ['kepala_bagian_pybmc'],
            'verifier, Kepala Bagian, dan PYBMC' => ['semua_peran'],
        ];
    }

    #[DataProvider('duplicateApproverProvider')]
    public function test_submit_mempertahankan_approver_lintas_peran_dan_meminta_satu_tindakan_per_tahap(string $skenario): void
    {
        $jenisPegawai = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $actorDuplikat = Employee::factory()->create(['nama_lengkap' => 'Actor Duplikat E2E']);
        $verifierEfektif = Employee::factory()->create(['nama_lengkap' => 'Verifier Efektif E2E']);
        $pybmcLain = Employee::factory()->create(['nama_lengkap' => 'PYBMC Lain E2E']);
        $pemohon = Employee::factory()->create([
            'nama_lengkap' => 'Pemohon Duplikat E2E',
            'jenis_pegawai_id' => $jenisPegawai->id,
            'kepala_bagian_id' => $actorDuplikat->id,
        ]);
        Appointment::create([
            'employee_id' => $pemohon->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-E2E-DUPLIKAT',
            'tanggal_sk' => '2020-01-01',
        ]);
        SupervisorAssignment::create([
            'employee_id' => $pemohon->id,
            'supervisor_id' => $actorDuplikat->id,
            'kepala_bagian_id' => $actorDuplikat->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);

        $chain = LeaveApprovalChain::create([
            'employee_id' => $pemohon->id,
            'name' => 'Chain E2E actor duplikat',
            'effective_from' => '2026-01-01',
            'change_reason' => 'Fixture duplicate last occurrence.',
        ]);
        $stepDefinitions = match ($skenario) {
            'verifier_kepala_bagian' => [
                ['step_type' => 'verifier', 'role_label' => 'Verifikator Duplikat', 'approver' => $actorDuplikat, 'is_final' => false],
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver' => $actorDuplikat, 'is_final' => false],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver' => $pybmcLain, 'is_final' => true],
            ],
            'verifier_pybmc' => [
                ['step_type' => 'verifier', 'role_label' => 'Verifikator Efektif', 'approver' => $verifierEfektif, 'is_final' => false],
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver' => $pybmcLain, 'is_final' => false],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC Duplikat', 'approver' => $verifierEfektif, 'is_final' => true],
            ],
            'kepala_bagian_pybmc' => [
                ['step_type' => 'verifier', 'role_label' => 'Verifikator Efektif', 'approver' => $verifierEfektif, 'is_final' => false],
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian Duplikat', 'approver' => $actorDuplikat, 'is_final' => false],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC Duplikat', 'approver' => $actorDuplikat, 'is_final' => true],
            ],
            'semua_peran' => [
                ['step_type' => 'verifier', 'role_label' => 'Verifikator Duplikat', 'approver' => $actorDuplikat, 'is_final' => false],
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian Duplikat', 'approver' => $actorDuplikat, 'is_final' => false],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC Duplikat', 'approver' => $actorDuplikat, 'is_final' => true],
            ],
        };
        $chain->steps()->createMany(collect($stepDefinitions)->map(
            fn (array $definition, int $index): array => [
                'step_order' => $index + 1,
                'step_type' => $definition['step_type'],
                'role_label' => $definition['role_label'],
                'approver_employee_id' => $definition['approver']->id,
                'is_final' => $definition['is_final'],
            ],
        )->all());

        $pemohonUser = User::factory()->pegawai()->create(['employee_id' => $pemohon->id]);
        $approverUsers = collect([$actorDuplikat, $verifierEfektif, $pybmcLain])
            ->unique('id')
            ->mapWithKeys(fn (Employee $approver): array => [
                $approver->id => User::factory()->pegawai()->create(['employee_id' => $approver->id]),
            ]);
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Sakit Duplicate '.str($skenario)->headline(),
            'code' => 'cuti_sakit_duplicate_'.$skenario,
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);

        $this->actingAs($pemohonUser)
            ->post(route('cuti.store'), [
                'jenis_cuti_id' => $jenis->id,
                'tanggal_mulai' => '2026-08-03',
                'tanggal_selesai' => '2026-08-04',
                'alasan' => 'Menguji actor duplikat.',
                'alamat_selama_cuti' => 'Jl. E2E Duplikat, Manado',
                'nomor_telepon' => '+62 431 556',
            ])
            ->assertRedirect(route('cuti'));

        $leave = LeaveRequest::query()->sole();
        $steps = $leave->steps()->orderBy('step_order')->get();
        $this->assertSame(['active', ...array_fill(0, count($steps) - 1, 'pending')], $steps->pluck('status')->all());
        $this->assertFalse($steps->contains(fn ($step): bool => $step->skipped_reason === 'duplicate_approver'));

        $firstEffective = $steps->firstOrFail();
        $initialNotification = SimpegNotification::query()
            ->where('user_id', $firstEffective->approver_employee_id)
            ->where('type', 'cuti.pengajuan_baru')
            ->sole();
        $this->assertSame($firstEffective->id, $initialNotification->data['leave_request_step_id'] ?? null);

        foreach ($steps as $step) {
            $user = $approverUsers->get($step->approver_employee_id);
            $this->assertInstanceOf(User::class, $user);
            $this->actingAs($user)
                ->post(route('cuti.approve', $leave->id), [
                    'active_step_id' => $step->id,
                    'komentar' => 'Satu tindakan per tahap approval.',
                ])
                ->assertRedirect(route('cuti.approval'));
        }

        $this->assertSame('disetujui', $leave->refresh()->status);
        $approvedSteps = $leave->steps()->orderBy('step_order')->get();
        $approvalTimeline = $leave->approvals()->orderBy('stage')->get();
        $this->assertSame(array_fill(0, $steps->count(), 'approved'), $approvedSteps->pluck('status')->all());
        $this->assertSame(range(1, $steps->count()), $approvalTimeline->pluck('stage')->all());
        $this->assertSame(0, $leave->approvals()->where('action', 'SKIP')->count());
        $this->assertSame(
            $steps->count(),
            $approvalTimeline->where('action', 'APPROVE')->count(),
        );

        if ($skenario === 'semua_peran') {
            $this->assertSame([$actorDuplikat->id, $actorDuplikat->id, $actorDuplikat->id], $approvalTimeline->pluck('approver_id')->all());
            $auditEventsByStage = AuditLog::query()
                ->where('auditable_type', 'LeaveRequest')
                ->where('auditable_id', $leave->id)
                ->whereIn('event', ['VERIFY', 'DECIDE'])
                ->get()
                ->mapWithKeys(fn (AuditLog $audit): array => [(int) ($audit->new_values['step_order'] ?? 0) => $audit->event])
                ->all();
            ksort($auditEventsByStage);

            $this->assertSame([1 => 'VERIFY', 2 => 'VERIFY', 3 => 'DECIDE'], $auditEventsByStage);
        }
    }

    private function actorRequest(User $actor): Request
    {
        $request = Request::create('/cuti/reconciliation', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }
}
