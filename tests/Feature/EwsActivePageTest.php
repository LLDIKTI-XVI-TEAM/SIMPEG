<?php

namespace Tests\Feature;

use App\Models\DisciplineRecord;
use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\RefStatusPegawai;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\EwsEngineService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EwsActivePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_allowed_roles_can_open_ews_active_page(): void
    {
        foreach (['super_admin', 'admin_kepegawaian', 'pimpinan'] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get(route('ews'))
                ->assertOk()
                ->assertSee('EWS', false);
        }
    }

    public function test_admin_filter_event_dan_status_tidak_dikenal_ditolak(): void
    {
        $admin = $this->userWithRole('admin_kepegawaian');

        $this->actingAs($admin)
            ->getJson(route('ews', ['event' => 'EVENT_TIDAK_DIKENAL']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event');

        $this->actingAs($admin)
            ->getJson(route('ews', ['status' => 'STATUS_TIDAK_DIKENAL']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_admin_event_semua_menampilkan_seluruh_tipe_alert_aktif(): void
    {
        $admin = $this->userWithRole('admin_kepegawaian');
        $pangkat = Employee::factory()->create(['nama_lengkap' => 'Pegawai Pangkat Sentinel']);
        $pensiun = Employee::factory()->create(['nama_lengkap' => 'Pegawai Pensiun Sentinel']);

        foreach ([[$pangkat, 'KENAIKAN_PANGKAT'], [$pensiun, 'PENSIUN']] as [$employee, $type]) {
            EwsAlert::query()->create([
                'employee_id' => $employee->id,
                'type' => $type,
                'target_date' => now()->addDays(60)->toDateString(),
                'interval_days' => 60,
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
            ]);
        }

        $this->actingAs($admin)
            ->get(route('ews', ['event' => 'semua']))
            ->assertOk()
            ->assertSee('Pegawai Pangkat Sentinel')
            ->assertSee('Pegawai Pensiun Sentinel')
            ->assertViewHas('alerts', fn ($alerts): bool => $alerts->total() === 2);
    }

    public function test_pegawai_cannot_open_ews_active_page(): void
    {
        $this->actingAs(User::factory()->pegawai()->create())
            ->get(route('ews'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_from_ews_active_page(): void
    {
        $this->get(route('ews'))->assertRedirect(route('login'));
    }

    public function test_alerts_are_sorted_by_remaining_days(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $late = $this->alert(now()->addDays(90)->toDateString(), 'KGB', 'Pegawai KGB 90 Hari');
        $soon = $this->alert(now()->addDays(14)->toDateString(), 'KGB', 'Pegawai KGB 14 Hari');

        $response = $this->actingAs($user)->get(route('ews'));

        $response->assertOk();
        $response->assertSeeInOrder([
            $soon->employee->nama_lengkap,
            $late->employee->nama_lengkap,
        ]);
    }

    public function test_ews_active_page_paginates_alerts_and_keeps_filters_in_page_links(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        foreach (range(1, 26) as $day) {
            $this->alert(
                now()->addDays($day)->toDateString(),
                'KGB',
                sprintf('Pegawai Pagination %02d', $day),
            );
        }

        $response = $this->actingAs($user)->get(route('ews', [
            'event' => 'KGB',
            'search' => 'Pagination',
        ]));

        $alerts = $response->viewData('alerts');

        $response->assertOk()
            ->assertSee('Pegawai Pagination 01')
            ->assertSee('Pegawai Pagination 10')
            ->assertDontSee('Pegawai Pagination 11');
        $this->assertSame(10, $alerts->perPage());
        $this->assertSame(26, $alerts->total());
        $this->assertStringContainsString('event=KGB', $alerts->url(2));
        $this->assertStringContainsString('search=Pagination', $alerts->url(2));

        $this->actingAs($user)
            ->get(route('ews', ['event' => 'KGB', 'search' => 'Pagination', 'page' => 2]))
            ->assertOk()
            ->assertSee('Pegawai Pagination 11')
            ->assertDontSee('Pegawai Pagination 01');
    }

    public function test_ews_active_page_searches_name_case_insensitively_and_partial_nip(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $nameMatch = $this->alert(now()->addDays(20)->toDateString(), 'KGB', 'Siti Rahmawati Pencarian');
        $nameMatch->employee->update(['nip' => '199912341234123456']);
        $other = $this->alert(now()->addDays(30)->toDateString(), 'KGB', 'Budi Santoso Pencarian');

        $this->actingAs($user)
            ->get(route('ews', ['search' => 'sItI rAhMa']))
            ->assertOk()
            ->assertSee($nameMatch->employee->nama_lengkap)
            ->assertDontSee($other->employee->nama_lengkap);

        $this->actingAs($user)
            ->get(route('ews', ['search' => '12341234']))
            ->assertOk()
            ->assertSee($nameMatch->employee->nama_lengkap)
            ->assertDontSee($other->employee->nama_lengkap);
    }

    public function test_ews_active_page_search_uses_explicit_submit_instead_of_navigating_while_typing(): void
    {
        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('ews'));

        $response->assertOk()
            ->assertSee('aria-label="Terapkan pencarian EWS"', false)
            ->assertDontSee('requestSubmit()', false);
    }

    public function test_ews_active_page_treats_sql_wildcards_as_literal_search_characters(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $percentMatch = $this->alert(now()->addDays(20)->toDateString(), 'KGB', 'Pegawai 100% Literal');
        $underscoreMatch = $this->alert(now()->addDays(21)->toDateString(), 'KGB', 'Pegawai_Kode Literal');
        $other = $this->alert(now()->addDays(22)->toDateString(), 'KGB', 'Pegawai Biasa');

        $this->actingAs($user)
            ->get(route('ews', ['search' => '%']))
            ->assertOk()
            ->assertSee($percentMatch->employee->nama_lengkap)
            ->assertDontSee($other->employee->nama_lengkap);

        $this->actingAs($user)
            ->get(route('ews', ['search' => '_']))
            ->assertOk()
            ->assertSee($underscoreMatch->employee->nama_lengkap)
            ->assertDontSee($other->employee->nama_lengkap);
    }

    public function test_ews_active_page_pagination_uses_alert_id_as_stable_tie_breaker(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $alerts = collect();

        foreach (range(1, 11) as $index) {
            $alerts->push($this->alert(
                now()->addDays(30)->toDateString(),
                'KGB',
                sprintf('Pegawai Target Seragam %02d', $index),
            ));
        }

        $firstPage = $this->actingAs($user)->get(route('ews', ['per_page' => 10]));
        $secondPage = $this->actingAs($user)->get(route('ews', ['per_page' => 10, 'page' => 2]));

        $firstPage->assertOk();
        $secondPage->assertOk();

        $actualIds = collect($firstPage->viewData('alerts')->items())
            ->pluck('alert_id')
            ->merge(collect($secondPage->viewData('alerts')->items())->pluck('alert_id'))
            ->values()
            ->all();
        $expectedIds = $alerts->sortBy('id')->pluck('id')->values()->all();

        $this->assertSame($expectedIds, $actualIds);
    }

    public function test_event_filter_only_shows_selected_event(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $kgb = $this->alert(now()->addDays(60)->toDateString(), 'KGB', 'Pegawai KGB Filter');
        $pensiun = $this->alert(now()->addDays(90)->toDateString(), 'PENSIUN', 'Pegawai Pensiun Filter');

        $response = $this->actingAs($user)->get(route('ews', ['event' => 'KGB']));

        $response->assertOk();
        $response->assertSee($kgb->employee->nama_lengkap);
        $response->assertDontSee($pensiun->employee->nama_lengkap);
    }

    public function test_satyalancana_event_filter_is_available(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $satyalancana = $this->alert(now()->addDays(90)->toDateString(), 'SATYALANCANA', 'Pegawai Satyalancana Filter');
        $kgb = $this->alert(now()->addDays(60)->toDateString(), 'KGB', 'Pegawai KGB Lain');

        $response = $this->actingAs($user)->get(route('ews', ['event' => 'Satyalancana']));

        $response->assertOk();
        $response->assertSee('Satyalancana');
        $response->assertSee($satyalancana->employee->nama_lengkap);
        $response->assertDontSee($kgb->employee->nama_lengkap);
    }

    public function test_status_filter_only_shows_selected_followup_status(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $active = $this->alert(now()->addDays(60)->toDateString(), 'KGB', 'Pegawai Status Aktif');
        $handled = $this->alert(now()->addDays(60)->toDateString(), 'KGB', 'Pegawai Status Ditangani');
        $handled->update([
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_at' => now(),
            'handled_by' => $user->id,
            'handled_note' => 'Berkas sudah selesai diproses.',
            'is_processed' => true,
        ]);

        $response = $this->actingAs($user)->get(route('ews', ['status' => EwsAlert::FOLLOWUP_STATUS_HANDLED]));

        $response->assertOk();
        $response->assertSee($handled->employee->nama_lengkap);
        $response->assertSee('Berkas sudah selesai diproses.');
        $response->assertSee('Daftar EWS Ditangani');
        $response->assertDontSee('Tidak ada peringatan EWS aktif untuk kategori ini.');
        $response->assertDontSee($active->employee->nama_lengkap);
    }

    public function test_filter_tanpa_hasil_menjelaskan_status_dan_empty_state_secara_spesifik(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->get(route('ews', ['status' => EwsAlert::FOLLOWUP_STATUS_NOT_NEEDED]))
            ->assertOk()
            ->assertSee('Daftar EWS Tidak Perlu')
            ->assertSee('Tidak ada data EWS untuk filter yang dipilih.')
            ->assertDontSee('Tidak ada peringatan EWS aktif untuk kategori ini.');
    }

    public function test_unread_expired_reminder_is_reactivated_and_shown_as_active(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Reminder Dipulihkan',
            'tanggal_kgb_berikutnya' => now()->subDay()->toDateString(),
        ]);

        app(EwsEngineService::class)->run();
        $alert = EwsAlert::where('employee_id', $employee->id)->where('type', 'KGB')->firstOrFail();
        $notification = SimpegNotification::where('ews_alert_id', $alert->id)->firstOrFail();
        $alert->update([
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_EXPIRED,
            'is_processed' => true,
        ]);

        $this->travel(5)->minutes();
        app(EwsEngineService::class)->run();

        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_ACTIVE, $alert->refresh()->followup_status);
        $this->assertFalse($alert->is_processed);
        $this->assertSame(1, SimpegNotification::where('ews_alert_id', $alert->id)->count());
        $this->assertSame($notification->id, SimpegNotification::where('ews_alert_id', $alert->id)->firstOrFail()->id);

        $this->actingAs($user)
            ->get(route('ews'))
            ->assertOk()
            ->assertSee('Pegawai Reminder Dipulihkan');
    }

    public function test_admin_can_see_followup_action_for_active_alert(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $alert = $this->alert(now()->addDays(60)->toDateString(), 'KGB', 'Pegawai Followup Button');

        $this->actingAs($user)
            ->get(route('ews'))
            ->assertOk()
            ->assertSee($alert->id, false)
            ->assertSee('Catatan Tindak Lanjut EWS')
            ->assertSee('openFollowupFromButton($event)', false)
            ->assertSee('data-followup-action="'.route('ews.followup.update', $alert).'"', false)
            ->assertSee('data-followup-status="ditangani"', false)
            ->assertSee('data-followup-status="tidak_perlu"', false);
    }

    public function test_non_eligible_promotion_alert_still_appears_for_admin(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Kinerja Buruk',
            'is_kinerja_baik' => false,
        ]);

        EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => now()->addDays(90)->toDateString(),
            'interval_days' => 90,
            'is_processed' => false,
        ]);

        $this->actingAs($user)
            ->get(route('ews'))
            ->assertOk()
            ->assertSee('Pegawai Kinerja Buruk')
            ->assertSee('Tidak Eligible')
            ->assertSee('Kinerja perlu ditinjau');
    }

    public function test_active_discipline_makes_promotion_alert_non_eligible(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Disiplin Aktif',
            'is_kinerja_baik' => true,
        ]);

        DisciplineRecord::create([
            'employee_id' => $employee->id,
            'jenis_hukuman' => 'Sedang',
            'deskripsi' => 'Pelanggaran disiplin aktif',
            'tanggal_mulai' => now()->subDay()->toDateString(),
            'no_sk' => 'SK-DIS-001',
            'tanggal_sk' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);
        EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => now()->addDays(90)->toDateString(),
            'interval_days' => 90,
            'is_processed' => false,
        ]);

        $this->actingAs($user)
            ->get(route('ews'))
            ->assertOk()
            ->assertSee('Pegawai Disiplin Aktif')
            ->assertSee('Tidak Eligible')
            ->assertSee('Hukuman disiplin aktif');
    }

    public function test_employee_name_links_to_employee_detail(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $alert = $this->alert(now()->addDays(60)->toDateString(), 'KGB', 'Pegawai Link Detail');

        // Kode: admin/ews/aktif.blade.php:206 memakai rbac.pegawai.show untuk non-super_admin.
        $this->actingAs($user)
            ->get(route('ews'))
            ->assertOk()
            ->assertSee(route('rbac.pegawai.show', $alert->employee_id), false);
    }

    public function test_alert_pegawai_nonaktif_tidak_muncul_di_halaman_ews(): void
    {
        // US-2.9 + klasifikasi kelompok: setelah SoftDeletes dilepas, pegawai nonaktif
        // (termasuk hasil backfill legacy) tidak boleh lagi memunculkan alert EWS.
        $user = User::factory()->adminKepegawaian()->create();
        $nonaktifStatus = RefStatusPegawai::where('kode', 'NONAKTIF')->firstOrFail();
        $nonaktif = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Nonaktif EWS',
            'status_aktif' => 'Nonaktif',
            'status_pegawai_id' => $nonaktifStatus->id,
        ]);
        // Rekan aktif di daftar yang sama memastikan filter tidak membuang semua alert.
        $aktif = Employee::factory()->create(['nama_lengkap' => 'Pegawai Aktif EWS']);
        EwsAlert::create([
            'employee_id' => $nonaktif->id,
            'type' => 'KGB',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 60,
            'is_processed' => false,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        EwsAlert::create([
            'employee_id' => $aktif->id,
            'type' => 'KGB',
            'target_date' => now()->addDays(20)->toDateString(),
            'interval_days' => 60,
            'is_processed' => false,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('ews'))
            ->assertOk()
            ->assertSee('Pegawai Aktif EWS')
            ->assertDontSee('Pegawai Nonaktif EWS');
    }

    public function test_daftar_ews_memakai_kelompok_aktif_ternormalisasi_dan_gagal_tertutup(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $tugasBelajar = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        DB::table('ref_status_pegawai')->where('id', $tugasBelajar->id)->update([
            'kelompok' => ' AKTIF/KHUSUS ',
        ]);

        $aktifKhusus = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai EWS Aktif Khusus',
            'status_pegawai_id' => $tugasBelajar->id,
        ]);
        $relasiKosong = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai EWS Relasi Kosong',
            'status_aktif' => 'Aktif',
        ]);
        DB::table('employees')->where('id', $relasiKosong->id)->update(['status_pegawai_id' => null]);

        $kelompokInvalid = RefStatusPegawai::query()->create([
            'kode' => 'STATUS_INVALID_EWS',
            'nama' => 'Status Invalid EWS',
            'kelompok' => 'Tidak Diketahui',
        ]);
        $invalid = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai EWS Kelompok Invalid',
            'status_pegawai_id' => $kelompokInvalid->id,
        ]);
        DB::table('employees')->where('id', $invalid->id)->update([
            'status_pegawai_id' => $kelompokInvalid->id,
            'status_aktif' => 'Status Invalid EWS',
        ]);

        foreach ([$aktifKhusus, $relasiKosong, $invalid] as $employee) {
            EwsAlert::create([
                'employee_id' => $employee->id,
                'type' => 'KGB',
                'target_date' => now()->addDays(30)->toDateString(),
                'interval_days' => 60,
                'is_processed' => false,
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
            ]);
        }

        $this->actingAs($user)
            ->get(route('ews'))
            ->assertOk()
            ->assertSee('Pegawai EWS Aktif Khusus')
            ->assertDontSee('Pegawai EWS Relasi Kosong')
            ->assertDontSee('Pegawai EWS Kelompok Invalid');
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function alert(string $targetDate, string $type, string $name): EwsAlert
    {
        $employee = Employee::factory()->create(['nama_lengkap' => $name]);

        return EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => $type,
            'target_date' => $targetDate,
            'interval_days' => match ($type) {
                'KGB' => 60,
                'PENSIUN' => 90,
                'SATYALANCANA' => 90,
                default => 90,
            },
            'is_processed' => false,
        ]);
    }

    public function test_ews_controls_follow_effective_role_during_simulation(): void
    {
        $superAdmin = User::factory()->superAdmin()->create(['employee_id' => Employee::factory()->create()->id]);

        // Simulasi admin_kepegawaian: halaman EWS terbuka, followup tampil (effective
        // role admin_kepegawaian), tetapi tombol Konfigurasi EWS khusus Super Admin
        // harus disembunyikan mengikuti role efektif.
        $this->actingAs($superAdmin)->post(route('switch-role'), ['target_role' => 'admin_kepegawaian']);
        $superAdmin->refresh();
        $this->assertEquals('admin_kepegawaian', $superAdmin->getEffectiveRole());

        $response = $this->actingAs($superAdmin)->get(route('ews'));
        $response->assertOk();
        $response->assertSee('Daftar EWS Aktif');
        $response->assertDontSee('Konfigurasi EWS');
    }
}
