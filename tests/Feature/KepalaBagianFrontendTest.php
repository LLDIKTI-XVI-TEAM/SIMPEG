<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RefJenisCuti;
use App\Models\RefStatusPegawai;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdoptedLeaveAttachment;
use Tests\TestCase;

class KepalaBagianFrontendTest extends TestCase
{
    use CreatesAdoptedLeaveAttachment;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->setUpAdoptedLeaveAttachmentFixtures();
    }

    public function test_dashboard_redirects_kepala_bagian_and_only_shows_direct_reports(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create([
            'nama_lengkap' => 'Bawahan Langsung',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        $otherEmployee = Employee::factory()->create(['nama_lengkap' => 'Bukan Bawahan']);
        $pendingLeave = $this->leaveWithActiveStep($directReport, $kepalaBagian);
        $activeStepId = $pendingLeave->steps()->where('status', 'active')->sole()->id;

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('kepala-bagian.dashboard'));

        $dashboardResponse = $this->actingAs($user)
            ->get(route('kepala-bagian.dashboard'))
            ->assertOk()
            ->assertSee('Bawahan Langsung')
            ->assertDontSee('Bukan Bawahan')
            ->assertSee(route('kepala-bagian.cuti.index'), false)
            ->assertSee('aria-describedby="kabag-dashboard-decision-description"', false)
            ->assertSee('id="kabag-dashboard-decision-description"', false)
            ->assertSee('@keydown.escape.window="if (confirmOpen) { if (!isSubmitting) confirmOpen = false }"', false)
            ->assertSee('name="active_step_id"', false)
            ->assertSee('x-bind:value="selectedActiveStepId"', false)
            ->assertSee($activeStepId, false)
            ->assertDontSee('@keydown.escape.window="if (!isSubmitting) confirmOpen = false"', false);

        $this->assertMatchesRegularExpression(
            '/<button(?=[^>]*\bid="kabag-dashboard-decision-cancel")(?=[^>]*\bdata-modal-initial-focus="true")[^>]*>/s',
            (string) $dashboardResponse->getContent(),
        );

        $this->assertNotNull($directReport->id);
        $this->assertNotNull($otherEmployee->id);
    }

    public function test_dashboard_menghitung_bawahan_aktif_khusus_dengan_kelompok_bervariasi(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $status = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        DB::table('ref_status_pegawai')->where('id', $status->id)->update([
            'kelompok' => ' aktif/KHUSUS ',
        ]);
        $bawahan = Employee::factory()->create([
            'nama_lengkap' => 'Bawahan Tugas Belajar Dashboard',
            'kepala_bagian_id' => $kepalaBagian->id,
            'status_pegawai_id' => $status->id,
        ]);
        DB::table('employees')->where('id', $bawahan->id)->update([
            'status_pegawai_id' => $status->id,
            'status_aktif' => 'Tugas Belajar',
        ]);

        $this->actingAs($user)
            ->get(route('kepala-bagian.dashboard'))
            ->assertOk()
            ->assertViewHas('totalBawahanAktif', 1)
            ->assertSee('Bawahan Tugas Belajar Dashboard');
    }

    public function test_navigation_menampilkan_cuti_bawahan_dan_pengajuan_cuti_sendiri(): void
    {
        [$user] = $this->kepalaBagian();

        $this->actingAs($user)
            ->get(route('kepala-bagian.dashboard'))
            ->assertOk()
            ->assertSee('Cuti Bawahan')
            ->assertSee('href="'.route('kepala-bagian.cuti.index').'"', false)
            ->assertSee('Pengajuan Cuti')
            ->assertSee('href="'.route('cuti').'"', false);
    }

    public function test_global_search_kepala_bagian_memakai_endpoint_nyata_dan_hanya_mengembalikan_bawahan_langsung(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $endpoint = url('/kepala-bagian/search');
        $directReport = Employee::factory()->create([
            'nama_lengkap' => 'Bawahan Global Search',
            'nip' => '199001012020121001',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Global Search Unit Lain',
            'nip' => '199001012020121002',
        ]);

        $this->actingAs($user)
            ->get(route('kepala-bagian.dashboard'))
            ->assertOk()
            ->assertSee($endpoint, false);

        $this->actingAs($user)
            ->getJson($endpoint.'?q='.urlencode('Global Search'))
            ->assertOk()
            ->assertJsonPath('Pegawai.0.title', 'Bawahan Global Search')
            ->assertJsonPath('Pegawai.0.url', route('kepala-bagian.bawahan.show', $directReport))
            ->assertJsonMissing(['title' => 'Pegawai Global Search Unit Lain']);

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->getJson($endpoint.'?q='.urlencode('Global Search'))
            ->assertForbidden();
    }

    public function test_global_search_cuti_kepala_bagian_tetap_terbatas_pada_bawahan_langsung_dan_lima_hasil(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $endpoint = url('/kepala-bagian/search');

        foreach (range(1, 6) as $index) {
            $directReport = Employee::factory()->create([
                'nama_lengkap' => "Bawahan Pencarian Cuti {$index}",
                'kepala_bagian_id' => $kepalaBagian->id,
            ]);
            $leave = $this->leaveWithActiveStep($directReport, $kepalaBagian);
            $leave->forceFill(['alasan' => "Agenda pencarian khusus {$index}"])->save();
        }

        $otherEmployee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Cuti Luar Scope']);
        $outsideLeave = $this->leaveWithActiveStep($otherEmployee, $kepalaBagian);
        $outsideLeave->forceFill(['alasan' => 'Agenda pencarian khusus luar scope'])->save();

        $response = $this->actingAs($user)
            ->getJson($endpoint.'?q='.urlencode('Agenda pencarian khusus'))
            ->assertOk()
            ->assertJsonCount(5, 'Cuti')
            ->assertJsonMissing(['title' => 'Pengajuan Cuti: Pegawai Cuti Luar Scope']);

        foreach ($response->json('Cuti') as $item) {
            $this->assertStringStartsWith(url('/kepala-bagian/cuti/'), $item['url']);
        }
    }

    public function test_employee_pages_are_limited_to_direct_reports(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Bawahan',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        $otherEmployee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Unit Lain']);

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.index'))
            ->assertOk()
            ->assertSee('Pegawai Bawahan')
            ->assertDontSee('Pegawai Unit Lain');

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.show', $directReport))
            ->assertOk()
            ->assertSee('Pegawai Bawahan');

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.show', $otherEmployee))
            ->assertForbidden();
    }

    public function test_status_bawahan_hanya_menampilkan_aktif_atau_cuti(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create([
            'nama_lengkap' => 'Bawahan Dengan Status Legacy',
            'kepala_bagian_id' => $kepalaBagian->id,
            'status_aktif' => 'Aktif',
        ]);
        // Simulasi data lama: kolom snapshot status berisi nilai legacy 'Pensiun' tetapi
        // relasi status (satu sumber klasifikasi) tetap mengarah ke kelompok Aktif —
        // halaman bawahan tetap menuntut pengklasifikasian aktif berdasarkan referensi.
        Employee::query()->whereKey($directReport->id)->update(['status_aktif' => 'Pensiun']);

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.index'))
            ->assertOk()
            ->assertSee('Bawahan Dengan Status Legacy')
            ->assertSee('Aktif')
            ->assertDontSee('Pensiun')
            ->assertDontSee('Dinas Luar');

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.show', $directReport))
            ->assertOk()
            ->assertSee('Aktif')
            ->assertDontSee('Pensiun')
            ->assertDontSee('Dinas Luar');

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.index', ['status' => 'aktif']))
            ->assertOk()
            ->assertSee('Bawahan Dengan Status Legacy');
    }

    public function test_dinas_luar_filter_is_rejected_for_kepala_bagian(): void
    {
        [$user] = $this->kepalaBagian();

        $this->actingAs($user)
            ->getJson(route('kepala-bagian.bawahan.index', ['status' => 'dinas_luar']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_legacy_status_employee_is_presented_as_active_without_raw_status_payload(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create([
            'nama_lengkap' => 'Bawahan Dengan Status Legacy',
            'kepala_bagian_id' => $kepalaBagian->id,
            'status_aktif' => 'Aktif',
        ]);
        // Snapshot legacy 'Pensiun' tanpa menyentuh relasi status: klasifikasi aktif tetap
        // memakai kelompok referensi, bukan string snapshot yang kedaluwarsa.
        Employee::query()->whereKey($directReport->id)->update(['status_aktif' => 'Pensiun']);

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.index'))
            ->assertOk()
            ->assertViewHas('employees', function ($employees): bool {
                $employee = $employees->firstWhere('nama_lengkap', 'Bawahan Dengan Status Legacy');

                return $employee !== null
                    && $employee->getAttribute('status_tampilan') === 'Aktif'
                    && ! array_key_exists('status_aktif', $employee->getAttributes())
                    && ! array_key_exists('status_pegawai_id', $employee->getAttributes())
                    && ! $employee->relationLoaded('statusPegawai');
            });

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.show', $directReport))
            ->assertOk()
            ->assertViewHas('employee', function (Employee $employee): bool {
                return $employee->getAttribute('status_tampilan') === 'Aktif'
                    && ! array_key_exists('status_aktif', $employee->getAttributes())
                    && ! array_key_exists('status_pegawai_id', $employee->getAttributes())
                    && ! $employee->relationLoaded('statusPegawai');
            });
    }

    public function test_current_approved_leave_displays_cuti_in_list_and_detail(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create([
            'nama_lengkap' => 'Bawahan Sedang Cuti',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        $leave = $this->leaveWithActiveStep($directReport, $kepalaBagian);
        $leave->forceFill([
            'tanggal_mulai' => now()->subDay()->toDateString(),
            'tanggal_selesai' => now()->addDay()->toDateString(),
            'status' => 'disetujui',
        ])->save();

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.index'))
            ->assertOk()
            ->assertSeeInOrder(['Bawahan Sedang Cuti', 'Cuti']);

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.show', $directReport))
            ->assertOk()
            ->assertSeeInOrder(['Bawahan Sedang Cuti', 'Cuti']);

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.index', ['status' => 'aktif']))
            ->assertOk()
            ->assertDontSee('Bawahan Sedang Cuti');
    }

    public function test_leave_queue_and_detail_use_real_scoped_data_and_contract(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create([
            'nama_lengkap' => 'Pemohon Bawahan',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        $otherEmployee = Employee::factory()->create(['nama_lengkap' => 'Pemohon Lain']);
        $visibleLeave = $this->leaveWithActiveStep($directReport, $kepalaBagian);
        $hiddenLeave = $this->leaveWithActiveStep($otherEmployee, $kepalaBagian);
        $activeStepId = $visibleLeave->steps()->where('status', 'active')->sole()->id;

        $this->actingAs($user)
            ->get(route('kepala-bagian.cuti.index'))
            ->assertOk()
            ->assertSee('Pemohon Bawahan')
            ->assertDontSee('Pemohon Lain')
            ->assertSee(route('kepala-bagian.cuti.show', $visibleLeave), false);

        $detailResponse = $this->actingAs($user)
            ->get(route('kepala-bagian.cuti.show', $visibleLeave))
            ->assertOk()
            ->assertSee(route('kepala-bagian.cuti.decision', $visibleLeave), false)
            ->assertSee('Konfirmasi Persetujuan')
            ->assertSee('aria-describedby="kabag-approval-confirmation-description"', false)
            ->assertSee('id="kabag-approval-confirmation-description"', false)
            ->assertSee('@keydown.escape.window="if (confirmOpen) { confirmOpen = false }"', false)
            ->assertSee('name="active_step_id"', false)
            ->assertSee('value="'.$activeStepId.'"', false)
            ->assertSee('required', false)
            ->assertDontSee('Simulasi');

        $this->assertMatchesRegularExpression(
            '/<button(?=[^>]*\bid="kabag-approval-confirmation-cancel")(?=[^>]*\bdata-modal-initial-focus="true")[^>]*>/s',
            (string) $detailResponse->getContent(),
        );

        $this->actingAs($user)
            ->get(route('kepala-bagian.cuti.show', $hiddenLeave))
            ->assertForbidden();
    }

    public function test_leave_index_defaults_to_menunggu_approval_status(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $pendingReport = Employee::factory()->create([
            'nama_lengkap' => 'Pemohon Menunggu',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        $approvedReport = Employee::factory()->create([
            'nama_lengkap' => 'Pemohon Disetujui',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);

        $pendingLeave = $this->leaveWithActiveStep($pendingReport, $kepalaBagian);
        $otherApprover = Employee::factory()->create();
        $pendingForOtherApprover = Employee::factory()->create([
            'nama_lengkap' => 'Pemohon Menunggu Approver Lain',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        $this->leaveWithActiveStep($pendingForOtherApprover, $otherApprover);

        $approvedLeave = LeaveRequest::create([
            'employee_id' => $approvedReport->id,
            'jenis_cuti_id' => $pendingLeave->jenis_cuti_id,
            'tanggal_mulai' => '2026-06-01',
            'tanggal_selesai' => '2026-06-02',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Sudah disetujui sebelumnya.',
            'status' => 'disetujui',
        ]);

        $this->actingAs($user)
            ->get(route('kepala-bagian.cuti.index'))
            ->assertOk()
            ->assertSee('Pemohon Menunggu')
            ->assertDontSee('Pemohon Menunggu Approver Lain')
            ->assertDontSee('Pemohon Disetujui');

        $this->actingAs($user)
            ->get(route('kepala-bagian.cuti.index', ['status' => 'all']))
            ->assertOk()
            ->assertSee('Pemohon Menunggu')
            ->assertSee('Pemohon Menunggu Approver Lain')
            ->assertSee('Pemohon Disetujui');
    }

    public function test_leave_queue_memakai_subquery_scope_dan_limit_paginator_database(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();

        foreach (range(1, 12) as $index) {
            $employee = Employee::factory()->create([
                'nama_lengkap' => "Bawahan Queue Subquery {$index}",
                'kepala_bagian_id' => $kepalaBagian->id,
            ]);
            $this->leaveWithActiveStep($employee, $kepalaBagian);
        }

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = mb_strtolower($query->sql);
        });

        $this->actingAs($user)
            ->get(route('kepala-bagian.cuti.index'))
            ->assertOk();

        $standaloneScopeQueries = collect($queries)->filter(
            fn (string $sql): bool => str_contains($sql, 'from "employees"')
                && ! str_contains($sql, 'from "leave_requests"')
                && preg_match('/select\s+"?(?:employees"\.)?"?id"?\s+from\s+"employees"/', $sql) === 1,
        );
        $paginatedLeaveQuery = collect($queries)->first(
            fn (string $sql): bool => str_contains($sql, 'from "leave_requests"')
                && str_contains($sql, 'select "employees"."id" from "employees"')
                && str_contains($sql, 'limit'),
        );

        $this->assertCount(0, $standaloneScopeQueries, 'Scope bawahan tidak boleh di-pluck penuh sebelum query cuti.');
        $this->assertNotNull($paginatedLeaveQuery, 'Queue harus memakai subquery scope dan LIMIT paginator pada query cuti.');
    }

    public function test_leave_index_membatasi_opsi_jenis_cuti_dan_mempertahankan_pilihan_aktif(): void
    {
        [$user] = $this->kepalaBagian();
        $selected = null;

        foreach (range(1, 101) as $index) {
            $type = RefJenisCuti::create([
                'nama' => sprintf('Jenis Cuti Bounded %03d', $index),
                'code' => sprintf('kabag_bounded_%03d', $index),
                'mengurangi_saldo_tahunan' => false,
                'khusus_pns' => false,
            ]);
            if ($index === 101) {
                $selected = $type;
            }
        }
        $this->assertInstanceOf(RefJenisCuti::class, $selected);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($user)
            ->get(route('kepala-bagian.cuti.index', ['jenis_cuti_id' => $selected->id]))
            ->assertOk()
            ->assertViewHas('jenisCutiOptions', function ($options) use ($selected): bool {
                return $options->count() === 100
                    && $options->first()?->id === $selected->id;
            });

        $optionQuery = collect($queries)->first(
            fn (string $sql): bool => str_contains($sql, 'from "ref_jenis_cuti"')
                && str_contains($sql, 'order by'),
        );
        $this->assertNotNull($optionQuery);
        $this->assertStringContainsString('limit', strtolower($optionQuery));
    }

    public function test_unduh_lampiran_cuti_hanya_untuk_laporan_langsung_kepala_bagian(): void
    {
        Storage::fake('local');
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        $leave = $this->leaveWithActiveStep($directReport, $kepalaBagian);
        $this->createAdoptedLeaveAttachment($leave, "%PDF-1.4\n% lampiran kabag privat\n%%EOF\n");
        $url = route('kepala-bagian.cuti.attachment.download', $leave);

        $download = $this->actingAs($user)->get($url);
        $download
            ->assertOk()
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        foreach (['private', 'no-store', 'max-age=0'] as $directive) {
            $this->assertStringContainsString($directive, (string) $download->headers->get('Cache-Control'));
        }
        $directReport->forceFill(['kepala_bagian_id' => Employee::factory()->create()->id])->save();
        $this->actingAs($user)->get($url)->assertForbidden();
    }

    public function test_leave_index_accepts_and_labels_duty_postponement_status(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pemohon Terminal Kepala Bagian',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        $leave = $this->leaveWithActiveStep($employee, $kepalaBagian);
        $leave->forceFill(['status' => LeaveRequest::STATUS_DUTY_POSTPONED])->save();

        $this->actingAs($user)
            ->get(route('kepala-bagian.cuti.index', ['status' => LeaveRequest::STATUS_DUTY_POSTPONED]))
            ->assertOk()
            ->assertSee('Pemohon Terminal Kepala Bagian')
            ->assertSee('Ditangguhkan karena Tugas Dinas')
            ->assertSee('value="'.LeaveRequest::STATUS_DUTY_POSTPONED.'"', false);
    }

    public function test_leave_surfaces_label_returned_rollover_without_offering_a_decision(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pemohon Rollover Kepala Bagian',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        $leave = $this->leaveWithActiveStep($employee, $kepalaBagian);
        $leave->forceFill([
            'status' => LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER,
            'rollover_source_year' => 2026,
            'rollover_target_year' => 2027,
        ])->save();

        $this->actingAs($user)
            ->get(route('kepala-bagian.cuti.index', ['status' => LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER]))
            ->assertOk()
            ->assertSee('Pemohon Rollover Kepala Bagian')
            ->assertSee('Dikembalikan karena Rollover')
            ->assertSee('value="'.LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER.'"', false);

        $this->actingAs($user)
            ->get(route('kepala-bagian.cuti.show', $leave))
            ->assertOk()
            ->assertSee('Dikembalikan karena Rollover')
            ->assertDontSee(route('kepala-bagian.cuti.decision', $leave), false);

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.show', $employee))
            ->assertOk()
            ->assertSee('Dikembalikan karena Rollover');
    }

    public function test_detail_pending_step_explains_waiting_role(): void
    {
        $fixture = $this->dutyPostponementFixture();

        $this->actingAs($fixture['user'])
            ->get(route('kepala-bagian.cuti.show', $fixture['leave']))
            ->assertOk()
            ->assertSeeInOrder(['Tahap 2 · PYBMC', 'Menunggu PYBMC']);
    }

    public function test_kepala_bagian_decision_uses_leave_workflow_and_requires_note_when_needed(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        $leave = $this->leaveWithActiveStep($directReport, $kepalaBagian);

        $this->actingAs($user)
            ->from(route('kepala-bagian.cuti.show', $leave))
            ->post(route('kepala-bagian.cuti.decision', $leave), [
                'active_step_id' => $leave->steps()->where('status', 'active')->valueOrFail('id'),
                'keputusan' => 'PERUBAHAN',
            ])
            ->assertRedirect(route('kepala-bagian.cuti.show', $leave))
            ->assertSessionHasErrors('catatan');

        $this->actingAs($user)
            ->post(route('kepala-bagian.cuti.decision', $leave), [
                'active_step_id' => $leave->steps()->where('status', 'active')->valueOrFail('id'),
                'keputusan' => 'DISETUJUI',
                'catatan' => 'Diteruskan ke tahapan berikutnya.',
            ])
            ->assertRedirect(route('kepala-bagian.cuti.show', $leave))
            ->assertSessionHas('success', 'Pengajuan cuti berhasil disetujui.');

        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $leave->id,
            'approver_id' => $kepalaBagian->id,
            'action' => 'APPROVE',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'DECIDE',
            'auditable_type' => 'LeaveRequest',
            'auditable_id' => $leave->id,
        ]);
    }

    public function test_form_keputusan_kepala_bagian_memiliki_label_focus_dan_relasi_error_yang_aksesibel(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $leave = $this->leaveWithActiveStep(
            Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]),
            $kepalaBagian,
        );

        $this->actingAs($user)
            ->get(route('kepala-bagian.cuti.show', $leave))
            ->assertOk()
            ->assertSee('<fieldset', false)
            ->assertSee('<legend', false)
            ->assertSee('id="kabag-decision-approved"', false)
            ->assertSee('for="kabag-decision-approved"', false)
            ->assertSee('peer-focus-visible:ring-2', false)
            ->assertSee('id="kabag-decision-note"', false)
            ->assertSee('for="kabag-decision-note"', false)
            ->assertSee('aria-describedby="kabag-decision-note-help"', false);

        $this->actingAs($user)
            ->from(route('kepala-bagian.cuti.show', $leave))
            ->followingRedirects()
            ->post(route('kepala-bagian.cuti.decision', $leave), [
                'active_step_id' => $leave->steps()->where('status', 'active')->valueOrFail('id'),
                'keputusan' => 'PERUBAHAN',
                'catatan' => 'abcd',
            ])
            ->assertOk()
            ->assertSee('id="kabag-decision-note-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="kabag-decision-note-help kabag-decision-note-error"', false)
            ->assertSee('role="alert"', false);
    }

    public function test_duty_postponement_kepala_bagian_route_records_terminal_workflow(): void
    {
        $fixture = $this->dutyPostponementFixture();

        $this->actingAs($fixture['user'])
            ->post(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), [
                'active_step_id' => $fixture['leave']->steps()->where('status', 'active')->valueOrFail('id'),
                'alasan' => 'Penugasan mendesak mewakili instansi.',
            ])
            ->assertRedirect(route('kepala-bagian.cuti.show', $fixture['leave']))
            ->assertSessionHas('success', 'Cuti Tahunan ditangguhkan karena tugas dinas dan hak terkait telah dilindungi untuk satu tahun berikutnya.');

        $this->assertDatabaseHas('leave_requests', ['id' => $fixture['leave']->id, 'status' => LeaveRequest::STATUS_DUTY_POSTPONED]);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $fixture['leave']->id,
            'approver_id' => $fixture['approver']->id,
            'action' => LeaveApproval::ACTION_DUTY_POSTPONEMENT,
        ]);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $fixture['leave']->id,
            'step_order' => 2,
            'status' => 'skipped',
            'skipped_reason' => LeaveRequestStep::SKIPPED_DUTY_POSTPONEMENT_TERMINAL,
        ]);
    }

    public function test_duty_postponement_kepala_bagian_route_validates_reason_without_mutation(): void
    {
        foreach ([
            '' => 'Alasan tugas dinas mendesak wajib diisi.',
            'abcd' => 'Alasan tugas dinas minimal berisi 5 karakter.',
            str_repeat('a', 501) => 'Alasan tugas dinas maksimal berisi 500 karakter.',
        ] as $reason => $message) {
            $fixture = $this->dutyPostponementFixture();
            $this->actingAs($fixture['user'])
                ->from(route('kepala-bagian.cuti.show', $fixture['leave']))
                ->post(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), [
                    'active_step_id' => $fixture['leave']->steps()->where('status', 'active')->valueOrFail('id'),
                    'alasan' => $reason,
                ])
                ->assertRedirect(route('kepala-bagian.cuti.show', $fixture['leave']))
                ->assertSessionHasErrorsIn('dutyPostponement', ['alasan' => $message]);
            $this->assertSame('menunggu_approval', $fixture['leave']->fresh()->status);
            $this->assertDatabaseMissing('leave_approvals', ['leave_request_id' => $fixture['leave']->id]);
        }
    }

    public function test_duty_postponement_kepala_bagian_route_rejects_malformed_uuid(): void
    {
        [$user] = $this->kepalaBagian();
        $this->actingAs($user)
            ->post('/kepala-bagian/cuti/bukan-uuid/penangguhan-tugas-dinas', ['alasan' => 'Penugasan mendesak mewakili instansi.'])
            ->assertNotFound();
    }

    public function test_duty_postponement_kepala_bagian_route_rejects_non_snapshot_actor_without_mutation(): void
    {
        $fixture = $this->dutyPostponementFixture();
        $other = Employee::factory()->create();
        $fixture['leave']->employee->forceFill(['kepala_bagian_id' => $other->id])->save();
        $user = User::factory()->kepalaBagian()->create(['employee_id' => $other->id]);

        $this->actingAs($user)
            ->post(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), [
                'active_step_id' => $fixture['leave']->steps()->where('status', 'active')->valueOrFail('id'),
                'alasan' => 'Penugasan mendesak mewakili instansi.',
            ])
            ->assertForbidden();
        $this->assertSame('menunggu_approval', $fixture['leave']->fresh()->status);
    }

    public function test_duty_postponement_kepala_bagian_route_rejects_request_outside_direct_report_scope(): void
    {
        $fixture = $this->dutyPostponementFixture();
        $fixture['leave']->employee->forceFill(['kepala_bagian_id' => Employee::factory()->create()->id])->save();

        $this->actingAs($fixture['user'])
            ->post(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), [
                'active_step_id' => $fixture['leave']->steps()->where('status', 'active')->valueOrFail('id'),
                'alasan' => 'Penugasan mendesak mewakili instansi.',
            ])
            ->assertForbidden();
        $this->assertSame('menunggu_approval', $fixture['leave']->fresh()->status);
    }

    public function test_duty_postponement_kepala_bagian_detail_shows_distinct_actions_only_for_eligible_actor(): void
    {
        $fixture = $this->dutyPostponementFixture();

        $this->actingAs($fixture['user'])
            ->get(route('kepala-bagian.cuti.show', $fixture['leave']))
            ->assertOk()
            ->assertSee('Ditangguhkan')
            ->assertDontSee('Tunda Sementara')
            ->assertSee('Tangguhkan karena Tugas Dinas')
            ->assertSee(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), false)
            ->assertSee('name="alasan"', false)
            ->assertSee('minlength="5"', false)
            ->assertSee('maxlength="500"', false)
            ->assertSee('Konfirmasi Penangguhan Tugas Dinas')
            ->assertSee('openDutyPostponement($event)', false)
            ->assertSee('closeDutyPostponement()', false)
            ->assertSee("document.getElementById('kabag-duty-postponement-reason')?.focus()", false)
            ->assertSee('dutyPostponementTrigger?.focus()', false)
            ->assertSee('aria-describedby="kabag-duty-postponement-description"', false)
            ->assertSee('data-modal-initial-focus="true"', false)
            ->assertSee('@keydown.escape.window="if (dutyPostponementOpen) { closeDutyPostponement() }"', false)
            ->assertDontSee('@keydown.escape.window="closeDutyPostponement()"', false)
            ->assertSee('@keydown.tab="trapModalFocus($event)"', false)
            ->assertSee('event.shiftKey', false);

        $other = Employee::factory()->create();
        $fixture['leave']->employee->forceFill(['kepala_bagian_id' => $other->id])->save();
        $otherUser = User::factory()->kepalaBagian()->create(['employee_id' => $other->id]);
        $this->actingAs($otherUser)
            ->get(route('kepala-bagian.cuti.show', $fixture['leave']))
            ->assertOk()
            ->assertDontSee(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), false);
    }

    public function test_duty_postponement_kepala_bagian_detail_hides_action_for_non_annual_and_renders_terminal_history(): void
    {
        $fixture = $this->dutyPostponementFixture();
        $annualTypeId = $fixture['leave']->jenis_cuti_id;
        $sickType = RefJenisCuti::create([
            'nama' => 'Cuti Sakit UI Kepala Bagian',
            'code' => 'sakit_ui_kepala_bagian',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $fixture['leave']->forceFill(['jenis_cuti_id' => $sickType->id])->save();

        $this->actingAs($fixture['user'])
            ->get(route('kepala-bagian.cuti.show', $fixture['leave']))
            ->assertOk()
            ->assertSee('Ditangguhkan')
            ->assertDontSee('Tunda Sementara')
            ->assertDontSee(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), false);

        $fixture['leave']->forceFill(['jenis_cuti_id' => $annualTypeId])->save();
        $this->actingAs($fixture['user'])
            ->post(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), [
                'active_step_id' => $fixture['leave']->steps()->where('status', 'active')->valueOrFail('id'),
                'alasan' => 'Penugasan mendesak mewakili instansi.',
            ]);

        $persistedSteps = $fixture['leave']->steps()->with('approver')->orderBy('step_order')->get();
        $persistedSteps[0]->approver->forceFill(['nama_lengkap' => 'Approver Tugas Dinas Kepala Bagian'])->save();
        $persistedSteps[1]->approver->forceFill(['nama_lengkap' => 'Approver Tahap Lanjutan Kepala Bagian'])->save();

        $response = $this->actingAs($fixture['user'])
            ->get(route('kepala-bagian.cuti.show', $fixture['leave']))
            ->assertOk();

        $response
            ->assertSeeInOrder([
                'Timeline',
                'Persetujuan',
                'Tahap 1 · Atasan Langsung',
                'Approver Tugas Dinas Kepala Bagian',
                'Ditangguhkan karena Tugas Dinas',
            ])
            ->assertSeeInOrder([
                'Timeline',
                'Persetujuan',
                'Tahap 2 · PYBMC',
                'Approver Tahap Lanjutan Kepala Bagian',
                'Dilewati',
                'Dilewati karena penangguhan tugas dinas menutup pengajuan.',
            ])
            ->assertSeeInOrder([
                'Riwayat Tindakan Resmi',
                'Tahap 1 · Approver Tugas Dinas Kepala Bagian',
                'Ditangguhkan karena Tugas Dinas',
            ])
            ->assertDontSee('ditangguhkan_tugas_dinas')
            ->assertDontSee('duty_postponement_terminal')
            ->assertDontSee(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), false);
    }

    public function test_duty_postponement_kepala_bagian_detail_localizes_other_skipped_reasons(): void
    {
        $fixture = $this->dutyPostponementFixture();
        $steps = $fixture['leave']->steps()->orderBy('step_order')->get();
        $steps[0]->forceFill(['status' => 'skipped', 'skipped_reason' => 'workflow_closed'])->save();
        $steps[1]->forceFill(['status' => 'skipped', 'skipped_reason' => 'request_not_approved'])->save();
        $fixture['leave']->forceFill(['status' => 'tidak_disetujui'])->save();

        $this->actingAs($fixture['user'])
            ->get(route('kepala-bagian.cuti.show', $fixture['leave']))
            ->assertOk()
            ->assertSeeInOrder([
                'Timeline',
                'Persetujuan',
                'Tahap 1 · Atasan Langsung',
                'Dilewati karena alur persetujuan telah ditutup.',
                'Tahap 2 · PYBMC',
                'Dilewati karena pengajuan telah diputus tidak disetujui.',
            ])
            ->assertDontSee('workflow_closed')
            ->assertDontSee('request_not_approved');
    }

    public function test_duty_postponement_kepala_bagian_invalid_reason_reopens_dedicated_modal_and_focuses_error(): void
    {
        $fixture = $this->dutyPostponementFixture();

        $this->actingAs($fixture['user'])
            ->from(route('kepala-bagian.cuti.show', $fixture['leave']))
            ->followingRedirects()
            ->post(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), [
                'active_step_id' => $fixture['leave']->steps()->where('status', 'active')->valueOrFail('id'),
                'alasan' => 'abcd',
            ])
            ->assertOk()
            ->assertSee('dutyPostponementOpen: true', false)
            ->assertSee('Alasan tugas dinas minimal berisi 5 karakter.')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('data-error-autofocus="true"', false)
            ->assertSee("document.getElementById('kabag-duty-postponement-reason')?.focus()", false)
            ->assertSee('confirmOpen: false', false);
    }

    public function test_duty_postponement_kepala_bagian_detail_uses_neutral_fallbacks_for_unknown_step_and_action(): void
    {
        $fixture = $this->dutyPostponementFixture();
        $step = $fixture['leave']->steps()->orderBy('step_order')->first();
        $step->forceFill(['status' => 'step_rahasia_kabag'])->save();
        LeaveApproval::create([
            'leave_request_id' => $fixture['leave']->id,
            'approver_id' => $fixture['approver']->id,
            'stage' => 1,
            'action' => 'ACTION_RAHASIA_KABAG',
            'komentar' => 'Fallback action kepala bagian.',
            'acted_at' => now(),
        ]);

        $response = $this->actingAs($fixture['user'])
            ->get(route('kepala-bagian.cuti.show', $fixture['leave']));

        $response->assertOk()
            ->assertSeeInOrder([
                'Timeline',
                'Persetujuan',
                'Tahap 1 · Atasan Langsung',
                'Status tidak tersedia',
            ])
            ->assertSeeInOrder([
                'Riwayat Tindakan Resmi',
                'Tahap 1 · ',
                'Tindakan tidak dikenal',
            ])
            ->assertDontSee('step_rahasia_kabag')
            ->assertDontSee('ACTION_RAHASIA_KABAG');
        $this->assertSame(1, substr_count($response->getContent(), 'Status tidak tersedia'));
    }

    public function test_detail_cuti_bawahan_menampilkan_status_tidak_disetujui(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        $leave = $this->leaveWithActiveStep($directReport, $kepalaBagian);
        $leave->forceFill(['status' => 'tidak_disetujui'])->save();
        $leave->steps()->delete();

        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian Baru',
            'approver_employee_id' => $kepalaBagian->id,
            'status' => 'tidak_disetujui',
            'is_final' => true,
            'acted_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('kepala-bagian.cuti.show', $leave));

        $response
            ->assertOk()
            ->assertSeeInOrder(['Atasan Langsung', 'Tidak Disetujui'])
            ->assertDontSee('Kepala Bagian Baru')
            ->assertDontSee('Kepala Bagian Legacy')
            ->assertDontSee('Ditolak')
            ->assertDontSee('Rejected');
        $this->assertGreaterThanOrEqual(1, substr_count($response->getContent(), 'border-danger'));
    }

    public function test_ews_page_only_exposes_alerts_for_direct_reports(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create([
            'nama_lengkap' => 'Bawahan EWS',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        $otherEmployee = Employee::factory()->create(['nama_lengkap' => 'Pegawai EWS Lain']);

        EwsAlert::create([
            'employee_id' => $directReport->id,
            'type' => 'KGB',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        EwsAlert::create([
            'employee_id' => $otherEmployee->id,
            'type' => 'KGB',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('kepala-bagian.ews.index'))
            ->assertOk()
            ->assertSee('Bawahan EWS')
            ->assertDontSee('Pegawai EWS Lain')
            ->assertSee(route('kepala-bagian.bawahan.show', $directReport), false);
    }

    /** @return array{0: User, 1: Employee} */
    private function kepalaBagian(): array
    {
        $employee = Employee::factory()->create();

        return [
            User::factory()->kepalaBagian()->create(['employee_id' => $employee->id]),
            $employee,
        ];
    }

    private function leaveWithActiveStep(Employee $applicant, Employee $approver): LeaveRequest
    {
        $leave = LeaveRequest::create([
            'employee_id' => $applicant->id,
            'jenis_cuti_id' => RefJenisCuti::create([
                'nama' => 'Cuti Sakit '.fake()->unique()->word(),
                'code' => 'cuti-sakit-kabag-'.fake()->unique()->numerify('############'),
                'mengurangi_saldo_tahunan' => false,
                'khusus_pns' => false,
            ])->id,
            'tanggal_mulai' => '2026-07-20',
            'tanggal_selesai' => '2026-07-22',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
        ]);

        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        return $leave;
    }

    /** @return array{user: User, approver: Employee, leave: LeaveRequest} */
    private function dutyPostponementFixture(): array
    {
        [$user, $approver] = $this->kepalaBagian();
        $applicant = Employee::factory()->create(['kepala_bagian_id' => $approver->id]);
        $applicantUser = User::factory()->pegawai()->create(['employee_id' => $applicant->id]);
        $type = RefJenisCuti::firstOrCreate(['code' => 'tahunan'], [
            'nama' => 'Cuti Tahunan', 'mengurangi_saldo_tahunan' => true, 'khusus_pns' => false,
        ]);
        $balance = LeaveBalance::create([
            'employee_id' => $applicant->id, 'tahun' => 2026, 'jatah_awal' => 12, 'carry_over' => 0,
            'terpakai' => 0, 'sisa' => 12, 'sisa_n2' => 0, 'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12, 'terpakai_tahun_berjalan' => 0, 'hangus' => 0,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $applicant->id, 'jenis_cuti_id' => $type->id,
            'tanggal_mulai' => '2026-08-03', 'tanggal_selesai' => '2026-08-07',
            'jumlah_hari_kerja' => 5, 'alasan' => 'Cuti tahunan keluarga.', 'status' => 'menunggu_approval',
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id, 'step_order' => 1, 'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian', 'approver_employee_id' => $approver->id, 'status' => 'active', 'is_final' => false,
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id, 'step_order' => 2, 'step_type' => 'pybmc',
            'role_label' => 'PYBMC', 'approver_employee_id' => Employee::factory()->create()->id,
            'status' => 'pending', 'is_final' => true,
        ]);
        LeaveBalanceReservationEvent::create([
            'employee_id' => $applicant->id, 'leave_request_id' => $leave->id, 'leave_balance_id' => $balance->id,
            'tahun' => 2026, 'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED, 'amount' => 5,
            'dedup_key' => "leave_reservation:{$leave->id}:reserved", 'created_by' => $applicantUser->id,
            'occurred_at' => Carbon::parse('2026-08-01 08:00:00'),
        ]);

        return compact('user', 'approver', 'leave');
    }
}
