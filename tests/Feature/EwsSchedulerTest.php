<?php

namespace Tests\Feature;

use App\Jobs\SendSimpegNotificationEmailJob;
use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\EwsConfig;
use App\Models\EwsSchedulerRun;
use App\Models\PositionHistory;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\EwsEngineService;
use App\Services\Notifications\NotificationRecipientResolver;
use App\Services\NotificationService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\Expectation;
use Mockery\MockInterface;
use Tests\TestCase;

class EwsSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_scheduler_uses_configured_event_periods_for_all_ews_types(): void
    {
        EwsConfig::setVal('pangkat_required_years', '3');
        EwsConfig::setVal('kgb_required_years', '4');
        EwsConfig::setVal('pensiun_required_age_years', '55');
        EwsConfig::setVal('pppk_contract_years', '3');
        EwsConfig::setVal('satyalancana_years_1', '7');
        EwsConfig::setVal('satyalancana_years_2', '14');
        EwsConfig::setVal('satyalancana_years_3', '21');
        EwsConfig::setVal('pangkat_h90', '1');
        EwsConfig::setVal('pangkat_h60', '2');
        EwsConfig::setVal('pangkat_h30', '3');
        EwsConfig::setVal('kgb_h60', '1');
        EwsConfig::setVal('kgb_h30', '2');
        EwsConfig::setVal('kgb_h14', '3');
        EwsConfig::setVal('pensiun_y1', '1');
        EwsConfig::setVal('pensiun_m6', '2');
        EwsConfig::setVal('pensiun_m3', '3');
        EwsConfig::setVal('pppk_m6', '1');
        EwsConfig::setVal('pppk_m3', '2');
        EwsConfig::setVal('pppk_m1', '3');
        EwsConfig::setVal('satyalancana_h180', '1');
        EwsConfig::setVal('satyalancana_h90', '2');
        EwsConfig::setVal('satyalancana_h30', '3');

        $rankGolongan = RefGolongan::where('kode', 'III/a')->firstOrFail();
        $rankEmployee = Employee::factory()->create(['is_kinerja_baik' => true]);
        $rankEmployee->rankHistories()->create([
            'golongan_id' => $rankGolongan->id,
            'tmt_pangkat' => now()->subYears(3)->addDays(3)->toDateString(),
            'no_sk' => 'SK-PANGKAT-KONFIG',
            'tanggal_sk' => now()->subYears(3)->toDateString(),
            'is_latest' => true,
        ]);

        $kgbEmployee = Employee::factory()->create();
        $kgbEmployee->salaryHistories()->create([
            'tmt_kgb' => now()->subYears(4)->addDays(3)->toDateString(),
            'gaji_pokok' => 5000000,
            'no_sk' => 'SK-KGB-KONFIG',
            'tanggal_sk' => now()->subYears(4)->toDateString(),
            'is_latest' => true,
        ]);

        $pensiunEmployee = Employee::factory()->create([
            'tanggal_lahir' => now()->subYears(55)->addDays(3)->toDateString(),
            'tanggal_pensiun' => null,
        ]);

        $pppkJenis = RefJenisPegawai::where('nama', 'PPPK')->firstOrFail();
        $pppkEmployee = Employee::factory()->create([
            'jenis_pegawai_id' => $pppkJenis->id,
            'tanggal_akhir_kontrak' => null,
        ]);
        Appointment::create([
            'employee_id' => $pppkEmployee->id,
            'jenis_pengangkatan' => 'PPPK',
            'tmt_pengangkatan' => now()->subYears(3)->addDays(3)->toDateString(),
            'no_sk' => 'SK-PPPK-KONFIG',
            'tanggal_sk' => now()->subYears(3)->toDateString(),
        ]);

        $satyalancanaEmployee = Employee::factory()->create(['is_satyalancana_eligible' => true]);
        Appointment::create([
            'employee_id' => $satyalancanaEmployee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => now()->subYears(7)->addDays(3)->toDateString(),
            'no_sk' => 'SK-SATYA-KONFIG',
            'tanggal_sk' => now()->subYears(7)->toDateString(),
        ]);

        app(EwsEngineService::class)->run();

        foreach ([
            [$rankEmployee->id, 'KENAIKAN_PANGKAT'],
            [$kgbEmployee->id, 'KGB'],
            [$pensiunEmployee->id, 'PENSIUN'],
            [$pppkEmployee->id, 'KONTRAK_PPPK'],
            [$satyalancanaEmployee->id, 'SATYALANCANA'],
        ] as [$employeeId, $type]) {
            $this->assertDatabaseHas('ews_alerts', [
                'employee_id' => $employeeId,
                'type' => $type,
                'interval_days' => 3,
            ]);
        }
        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $satyalancanaEmployee->id,
            'type' => 'SATYALANCANA',
            'satyalancana_years' => 7,
        ]);
    }

    public function test_scheduler_creates_runs_log_successfully(): void
    {
        $this->assertSame(0, EwsSchedulerRun::count());

        // Run scheduler scan
        app(EwsEngineService::class)->run();

        $this->assertSame(1, EwsSchedulerRun::count());
        $run = EwsSchedulerRun::first();
        $this->assertSame('berhasil', $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, $run->alerts_created);
    }

    public function test_scheduler_checks_promotion_and_kgb_triggers(): void
    {
        // 1. Kenaikan Pangkat H-90 (Pangkat Tahap 1)
        $employee1 = Employee::factory()->create([
            'tanggal_kenaikan_pangkat_berikutnya' => now()->addDays(90)->toDateString(),
            'is_kinerja_baik' => true,
        ]);

        // 2. KGB H-60 (KGB Tahap 1)
        $employee2 = Employee::factory()->create([
            'tanggal_kgb_berikutnya' => now()->addDays(60)->toDateString(),
        ]);

        app(EwsEngineService::class)->run();

        // Should create 2 alerts
        $this->assertSame(2, EwsAlert::count());
        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee1->id,
            'type' => 'KENAIKAN_PANGKAT',
            'interval_days' => 90,
        ]);
        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee2->id,
            'type' => 'KGB',
            'interval_days' => 60,
        ]);

        // Verify notifications
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee1->id,
            'type' => 'ews.kenaikan_pangkat',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee2->id,
            'type' => 'ews.kgb',
        ]);
    }

    public function test_promotion_reminder_is_withheld_when_performance_flag_is_negative(): void
    {
        // Alert tetap dibuat agar status kelayakan terbaca pada daftar EWS, namun pengingat
        // tidak diterbitkan ke penerima mana pun selama pegawai belum memenuhi syarat.
        $employee = Employee::factory()->create([
            'tanggal_kenaikan_pangkat_berikutnya' => now()->addDays(90)->toDateString(),
            'is_kinerja_baik' => false,
        ]);

        app(EwsEngineService::class)->run();

        $this->assertSame(1, EwsAlert::count());
        $alert = EwsAlert::first();
        $this->assertFalse($alert->is_eligible);
        // Tanpa penjaga ini alert tampak sudah memberi tahu pegawai padahal notifikasinya ditahan.
        $this->assertNull($alert->notified_at);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.kenaikan_pangkat',
        ]);
    }

    public function test_promotion_reminder_is_withheld_when_disciplinary_record_is_active(): void
    {
        // Hukuman disiplin aktif dan flag kinerja negatif adalah syarat kelayakan sederajat,
        // sehingga keduanya harus menahan pengingat dengan cara yang sama.
        $employee = Employee::factory()->create([
            'tanggal_kenaikan_pangkat_berikutnya' => now()->addDays(90)->toDateString(),
            'is_kinerja_baik' => true,
        ]);

        DisciplineRecord::create([
            'employee_id' => $employee->id,
            'jenis_hukuman' => 'Sedang',
            'deskripsi' => 'Melanggar disiplin jam kerja',
            'tanggal_mulai' => now()->subDay()->toDateString(),
            'no_sk' => 'SK-DISC-001',
            'tanggal_sk' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        app(EwsEngineService::class)->run();

        $this->assertSame(1, EwsAlert::count());
        $alert = EwsAlert::first();
        $this->assertFalse($alert->is_eligible);
        $this->assertNull($alert->notified_at);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.kenaikan_pangkat',
        ]);
    }

    public function test_withheld_promotion_reminder_is_sent_after_flag_turns_positive(): void
    {
        // Penahanan tidak boleh permanen. Begitu pegawai memenuhi syarat, pengingat pada alert
        // yang sudah tersimpan harus tetap dapat diterbitkan pada penjadwalan berikutnya.
        $employee = Employee::factory()->create([
            'tanggal_kenaikan_pangkat_berikutnya' => now()->addDays(90)->toDateString(),
            'is_kinerja_baik' => false,
        ]);

        app(EwsEngineService::class)->run();

        $this->assertNull(EwsAlert::firstOrFail()->notified_at);

        $employee->update(['is_kinerja_baik' => true]);

        app(EwsEngineService::class)->run();

        $this->assertSame(1, EwsAlert::count());
        $alert = EwsAlert::firstOrFail();
        $this->assertNotNull($alert->notified_at);
        // Status kelayakan yang tersimpan harus mengikuti keadaan terbaru, bukan keadaan saat
        // alert pertama kali dibuat, agar pembaca kolom ini tidak tertinggal dari kenyataan.
        $this->assertTrue($alert->is_eligible);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.kenaikan_pangkat',
        ]);
    }

    public function test_stored_eligibility_follows_latest_state_when_flag_turns_negative(): void
    {
        // Alert yang pengingatnya sudah terbit tetap harus melaporkan kelayakan terbaru supaya
        // admin dapat melihat alasan pengingat berhenti diterbitkan. Notifikasi yang sudah
        // sampai ke pegawai tidak ditarik oleh penjadwalan ini.
        $employee = Employee::factory()->create([
            'tanggal_kenaikan_pangkat_berikutnya' => now()->addDays(90)->toDateString(),
            'is_kinerja_baik' => true,
        ]);

        app(EwsEngineService::class)->run();

        $alert = EwsAlert::firstOrFail();
        $this->assertTrue($alert->is_eligible);
        $this->assertNotNull($alert->notified_at);

        $employee->update(['is_kinerja_baik' => false]);

        app(EwsEngineService::class)->run();

        $this->assertSame(1, EwsAlert::count());
        $this->assertFalse(EwsAlert::firstOrFail()->is_eligible);
    }

    public function test_withheld_promotion_reminder_does_not_queue_email_for_admin(): void
    {
        // Penahanan berlaku untuk seluruh penerima, termasuk Admin Kepegawaian yang biasanya
        // menerima salinan pengingat lewat email.
        Queue::fake();

        $adminEmployee = Employee::factory()->create();
        User::factory()->adminKepegawaian()->create(['employee_id' => $adminEmployee->id]);

        $employee = Employee::factory()->create([
            'tanggal_kenaikan_pangkat_berikutnya' => now()->addDays(90)->toDateString(),
            'is_kinerja_baik' => false,
        ]);

        app(EwsEngineService::class)->run();

        Queue::assertNotPushed(SendSimpegNotificationEmailJob::class);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.kenaikan_pangkat',
        ]);
    }

    public function test_scheduler_checks_pensiun_trigger(): void
    {
        // Pensiun H-365 (Tahap 1) - menggunakan BUP calculation
        $jenisJabatan = RefJenisJabatan::create(['nama' => 'Test', 'maks_usia_pensiun' => 58]);

        $employee = Employee::factory()->create([
            'tanggal_lahir' => now()->subYears(58)->addDays(365)->toDateString(),
            'tanggal_pensiun' => null,
            'status_aktif' => 'Aktif',
        ]);

        PositionHistory::create([
            'id' => Str::uuid(),
            'employee_id' => $employee->id,
            'jenis_jabatan_id' => $jenisJabatan->id,
            'tmt_jabatan' => now()->subYear()->toDateString(),
            'is_latest' => true,
        ]);

        EwsConfig::setVal('pensiun_required_age_years', '0'); // Use position BUP
        EwsConfig::setVal('pensiun_y1', '365');

        app(EwsEngineService::class)->run();

        $this->assertSame(1, EwsAlert::count());
        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'PENSIUN',
            'interval_days' => 365,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.pensiun',
        ]);
    }

    public function test_ews_prioritizes_manual_pension_date_over_bup(): void
    {
        $jenisJabatan = RefJenisJabatan::create(['nama' => 'Test', 'maks_usia_pensiun' => 58]);

        // Employee with manual pension date = 365 days from now (1 year)
        // But BUP calculation = 180 days from now (6 months)
        $employee = Employee::factory()->create([
            'tanggal_lahir' => now()->subYears(58)->addDays(180)->toDateString(),
            'tanggal_pensiun' => now()->addDays(365)->toDateString(), // Manual: 1 year away
            'status_aktif' => 'Aktif',
        ]);

        PositionHistory::create([
            'id' => Str::uuid(),
            'employee_id' => $employee->id,
            'jenis_jabatan_id' => $jenisJabatan->id,
            'tmt_jabatan' => now()->subYear()->toDateString(),
            'is_latest' => true,
        ]);

        EwsConfig::setVal('pensiun_required_age_years', '0'); // Use position BUP
        EwsConfig::setVal('pensiun_y1', '365'); // H-1 year threshold

        app(EwsEngineService::class)->run();

        // Should create alert at H-365 based on manual date
        $alert = EwsAlert::where('employee_id', $employee->id)
            ->where('type', 'PENSIUN')
            ->first();

        $this->assertNotNull($alert);
        $this->assertEquals(365, $alert->interval_days); // H-1 year, not H-6 months
        $this->assertEquals(
            now()->addDays(365)->startOfDay()->toDateString(),
            Carbon::parse($alert->target_date)->toDateString()
        );
    }

    /** BUP jabatan harus mengalahkan konfigurasi global ketika milestone belum tersedia. */
    public function test_scheduler_uses_position_bup_before_global_fallback_without_milestone(): void
    {
        EwsConfig::setVal('pensiun_required_age_years', '60');
        EwsConfig::setVal('pensiun_y1', '365');
        EwsConfig::setVal('pensiun_m6', '180');
        EwsConfig::setVal('pensiun_m3', '90');

        $jabatan = RefJabatan::create([
            'nama' => 'Analis BUP Prioritas',
            'default_bup' => 58,
        ]);
        $employee = Employee::factory()->create([
            'tanggal_lahir' => now()->subYears(58)->addDays(90)->toDateString(),
            'tanggal_pensiun' => null,
            'status_aktif' => 'Aktif',
        ]);

        PositionHistory::create([
            'id' => Str::uuid(),
            'employee_id' => $employee->id,
            'jabatan_id' => $jabatan->id,
            'nama_jabatan' => $jabatan->nama,
            'tmt_jabatan' => now()->subYear()->toDateString(),
            'is_latest' => true,
        ]);

        app(EwsEngineService::class)->run();

        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'PENSIUN',
            'target_date' => now()->addDays(90)->toDateString(),
            'interval_days' => 90,
        ]);
    }

    public function test_scheduler_checks_pppk_contract_using_tanggal_akhir_kontrak(): void
    {
        $pppkJenis = RefJenisPegawai::where('nama', 'PPPK')->firstOrFail();

        // PPPK Employee with tanggal_akhir_kontrak H-180 (Tahap 1)
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $pppkJenis->id,
            'tanggal_akhir_kontrak' => now()->addDays(180)->toDateString(),
        ]);

        app(EwsEngineService::class)->run();

        $this->assertSame(1, EwsAlert::count());
        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'KONTRAK_PPPK',
            'interval_days' => 180,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.kontrak_pppk',
        ]);
    }

    public function test_scheduler_checks_pppk_contract_fallback_to_appointments(): void
    {
        $pppkJenis = RefJenisPegawai::where('nama', 'PPPK')->firstOrFail();

        // PPPK Employee with null tanggal_akhir_kontrak, but PPPK appointment 4 years minus 180 days ago.
        // So target date (TMT + default 4 years) is exactly H-180.
        $tmt = now()->subYears(4)->addDays(180)->toDateString();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $pppkJenis->id,
            'tanggal_akhir_kontrak' => null,
        ]);

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PPPK',
            'tmt_pengangkatan' => $tmt,
            'no_sk' => 'SK-PPPK-FALLBACK',
            'tanggal_sk' => $tmt,
        ]);

        app(EwsEngineService::class)->run();

        $this->assertSame(1, EwsAlert::count());
        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'KONTRAK_PPPK',
            'interval_days' => 180,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.kontrak_pppk',
        ]);
    }

    public function test_scheduler_skips_contract_ews_for_pns_and_cpns(): void
    {
        foreach (['PNS', 'CPNS'] as $jenis) {
            $jenisPegawai = RefJenisPegawai::where('nama', $jenis)->firstOrFail();
            $employee = Employee::factory()->create([
                'jenis_pegawai_id' => $jenisPegawai->id,
                'tanggal_akhir_kontrak' => now()->addDays(180)->toDateString(),
            ]);
            Appointment::create([
                'employee_id' => $employee->id,
                'jenis_pengangkatan' => $jenis,
                'tmt_pengangkatan' => now()->subYears(4)->addDays(180)->toDateString(),
                'no_sk' => 'SK-'.$jenis.'-TANPA-EWS',
                'tanggal_sk' => now()->subYears(4)->toDateString(),
            ]);
        }

        app(EwsEngineService::class)->run();

        $this->assertSame(0, EwsAlert::where('type', 'KONTRAK_PPPK')->count());
    }

    public function test_scheduler_creates_satyalancana_alerts_for_h180_h90_h30(): void
    {
        foreach ([180, 90, 30] as $days) {
            $employee = Employee::factory()->create([
                'is_satyalancana_eligible' => true,
            ]);

            $tmt = now()->subYears(10)->addDays($days)->toDateString();
            Appointment::create([
                'employee_id' => $employee->id,
                'jenis_pengangkatan' => 'PNS',
                'tmt_pengangkatan' => $tmt,
                'no_sk' => 'SK-SATYA-'.$days,
                'tanggal_sk' => $tmt,
            ]);
        }

        app(EwsEngineService::class)->run();

        $this->assertSame(3, EwsAlert::where('type', 'SATYALANCANA')->count());
        foreach ([180, 90, 30] as $days) {
            $this->assertDatabaseHas('ews_alerts', [
                'type' => 'SATYALANCANA',
                'interval_days' => $days,
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
            ]);
        }
        $this->assertSame(3, SimpegNotification::where('type', 'ews.satyalancana')->count());
    }

    public function test_scheduler_prevents_duplicate_satyalancana_alerts(): void
    {
        $employee = Employee::factory()->create([
            'is_satyalancana_eligible' => true,
        ]);
        $tmt = now()->subYears(10)->addDays(90)->toDateString();

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => $tmt,
            'no_sk' => 'SK-SATYA-DUP',
            'tanggal_sk' => $tmt,
        ]);

        app(EwsEngineService::class)->run();
        app(EwsEngineService::class)->run();

        $this->assertSame(1, EwsAlert::where('type', 'SATYALANCANA')->count());
        $this->assertSame(1, SimpegNotification::where('type', 'ews.satyalancana')->count());
    }

    public function test_satyalancana_manual_flag_stores_false_and_still_notifies(): void
    {
        // is_satyalancana_eligible=false: notifikasi TETAP terkirim, is_eligible=false disimpan.
        $employee = Employee::factory()->create([
            'is_satyalancana_eligible' => false,
            'satyalancana_note' => 'Belum memenuhi syarat administrasi.',
        ]);
        $tmt = now()->subYears(10)->addDays(90)->toDateString();

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => $tmt,
            'no_sk' => 'SK-SATYA-FLAG',
            'tanggal_sk' => $tmt,
        ]);

        app(EwsEngineService::class)->run();

        $this->assertSame(1, EwsAlert::where('type', 'SATYALANCANA')->count());
        $alert = EwsAlert::where('type', 'SATYALANCANA')->firstOrFail();
        $this->assertFalse($alert->is_eligible);   // is_eligible tersimpan false
        $this->assertNotNull($alert->notified_at); // notifikasi tetap terkirim
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.satyalancana',
        ]);
    }

    public function test_alert_stores_is_eligible_true_for_eligible_promotion(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_kenaikan_pangkat_berikutnya' => now()->addDays(90)->toDateString(),
            'is_kinerja_baik' => true,
        ]);

        app(EwsEngineService::class)->run();

        $alert = EwsAlert::where('type', 'KENAIKAN_PANGKAT')->firstOrFail();
        $this->assertTrue($alert->is_eligible);
        $this->assertNotNull($alert->notified_at);
    }

    public function test_alert_stores_is_eligible_null_for_kgb(): void
    {
        // KGB tidak memiliki eligibility check — is_eligible harus null
        Employee::factory()->create([
            'tanggal_kgb_berikutnya' => now()->addDays(60)->toDateString(),
        ]);

        app(EwsEngineService::class)->run();

        $alert = EwsAlert::where('type', 'KGB')->firstOrFail();
        $this->assertNull($alert->is_eligible);
        $this->assertNotNull($alert->notified_at);
    }

    public function test_satyalancana_alert_stores_years_milestone(): void
    {
        // Satyalancana 10 tahun — satyalancana_years harus tersimpan 10
        $employee = Employee::factory()->create([
            'is_satyalancana_eligible' => true,
        ]);
        $tmt = now()->subYears(10)->addDays(90)->toDateString();

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => $tmt,
            'no_sk' => 'SK-SATYA-YEARS',
            'tanggal_sk' => $tmt,
        ]);

        app(EwsEngineService::class)->run();

        $alert = EwsAlert::where('type', 'SATYALANCANA')->firstOrFail();
        $this->assertSame(10, $alert->satyalancana_years);
    }

    public function test_satyalancana_email_fan_out_to_admin_when_eligible(): void
    {
        // Fan-out admin untuk EWS terjadi lewat email (via queue),
        // bukan lewat in-app notification. Verifikasi bahwa:
        // (1) pegawai mendapat in-app notification
        // (2) ews.satyalancana aktif di email whitelist (via resolver)
        $adminEmployee = Employee::factory()->create();
        User::factory()->create([
            'role' => 'admin_kepegawaian',
            'employee_id' => $adminEmployee->id,
        ]);

        $employee = Employee::factory()->create([
            'is_satyalancana_eligible' => true,
        ]);
        $tmt = now()->subYears(10)->addDays(90)->toDateString();

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => $tmt,
            'no_sk' => 'SK-SATYA-FANOUT',
            'tanggal_sk' => $tmt,
        ]);

        app(EwsEngineService::class)->run();

        // Pegawai dapat notif in-app
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.satyalancana',
        ]);

        // Admin TIDAK mendapat in-app (fan-out admin hanya lewat email queue)
        // Verifikasi bahwa ews.satyalancana ada di whitelist email resolver
        $resolver = app(NotificationRecipientResolver::class);
        $this->assertTrue($resolver->emailEnabled('ews.satyalancana'));
    }

    public function test_satyalancana_notifies_even_when_not_eligible(): void
    {
        // is_satyalancana_eligible=false: pegawai dan admin tetap dapat notif in-app.
        // Email terkirim jika credential SMTP sudah dikonfigurasi di RefNotificationChannel.
        $adminEmployee = Employee::factory()->create();
        User::factory()->create([
            'role' => 'admin_kepegawaian',
            'employee_id' => $adminEmployee->id,
        ]);

        $employee = Employee::factory()->create([
            'is_satyalancana_eligible' => false,
        ]);
        $tmt = now()->subYears(10)->addDays(90)->toDateString();

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => $tmt,
            'no_sk' => 'SK-SATYA-NO-FANOUT',
            'tanggal_sk' => $tmt,
        ]);

        app(EwsEngineService::class)->run();

        // Pegawai tetap dapat notif in-app meski not eligible
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.satyalancana',
        ]);
    }

    public function test_scheduler_prevents_duplicate_alerts(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_kgb_berikutnya' => now()->addDays(60)->toDateString(),
        ]);

        // Run once
        app(EwsEngineService::class)->run();
        $this->assertSame(1, EwsAlert::count());

        // Run second time
        app(EwsEngineService::class)->run();
        $this->assertSame(1, EwsAlert::count()); // Still 1 due to duplicate prevention
    }

    public function test_scheduler_refreshes_one_unread_ews_reminder_after_target_date_without_duplication(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_kgb_berikutnya' => now()->subDay()->toDateString(),
        ]);

        app(EwsEngineService::class)->run();
        $alert = EwsAlert::where('employee_id', $employee->id)->where('type', 'KGB')->firstOrFail();
        $notification = SimpegNotification::where('ews_alert_id', $alert->id)->firstOrFail();
        $firstNotifiedAt = $alert->notified_at;

        $this->travel(5)->minutes();
        app(EwsEngineService::class)->run();

        $this->assertSame(1, EwsAlert::where('employee_id', $employee->id)->where('type', 'KGB')->count());
        $this->assertSame(1, SimpegNotification::where('ews_alert_id', $alert->id)->count());
        $this->assertSame($notification->id, SimpegNotification::where('ews_alert_id', $alert->id)->firstOrFail()->id);
        $this->assertTrue($alert->refresh()->notified_at->greaterThan($firstNotifiedAt));
    }

    public function test_scheduler_revives_expired_alert_with_an_unread_reminder(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_kgb_berikutnya' => now()->subDay()->toDateString(),
        ]);

        app(EwsEngineService::class)->run();
        $alert = EwsAlert::where('employee_id', $employee->id)->where('type', 'KGB')->firstOrFail();
        $notification = SimpegNotification::where('ews_alert_id', $alert->id)->firstOrFail();
        $firstNotifiedAt = $alert->notified_at;
        $alert->update([
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_EXPIRED,
            'is_processed' => true,
        ]);

        $this->travel(5)->minutes();
        app(EwsEngineService::class)->run();

        $this->assertSame(1, EwsAlert::where('employee_id', $employee->id)->where('type', 'KGB')->count());
        $this->assertSame(1, SimpegNotification::where('ews_alert_id', $alert->id)->count());
        $this->assertSame($notification->id, SimpegNotification::where('ews_alert_id', $alert->id)->firstOrFail()->id);
        $this->assertSame(EwsAlert::FOLLOWUP_STATUS_ACTIVE, $alert->refresh()->followup_status);
        $this->assertFalse($alert->is_processed);
        $this->assertTrue($alert->notified_at->greaterThan($firstNotifiedAt));
    }

    public function test_scheduler_refreshes_unread_reminder_with_stale_acknowledgement(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_kgb_berikutnya' => now()->subDay()->toDateString(),
        ]);

        app(EwsEngineService::class)->run();
        $alert = EwsAlert::where('employee_id', $employee->id)->where('type', 'KGB')->firstOrFail();
        $firstNotifiedAt = $alert->notified_at;
        $alert->update(['notification_acknowledged_at' => now()]);

        $this->travel(5)->minutes();
        app(EwsEngineService::class)->run();

        $this->assertSame(1, SimpegNotification::where('ews_alert_id', $alert->id)->count());
        $this->assertNull($alert->refresh()->notification_acknowledged_at);
        $this->assertTrue($alert->notified_at->greaterThan($firstNotifiedAt));
    }

    public function test_scheduler_stops_refreshing_ews_reminder_after_employee_reads_it(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_kgb_berikutnya' => now()->subDay()->toDateString(),
        ]);

        app(EwsEngineService::class)->run();
        $alert = EwsAlert::where('employee_id', $employee->id)->where('type', 'KGB')->firstOrFail();
        $notification = SimpegNotification::where('ews_alert_id', $alert->id)->firstOrFail();
        $notification->update(['is_read' => true, 'read_at' => now()]);
        $firstNotifiedAt = $alert->notified_at;

        $this->travel(5)->minutes();
        app(EwsEngineService::class)->run();

        $this->assertSame(1, SimpegNotification::where('ews_alert_id', $alert->id)->count());
        $this->assertSame($firstNotifiedAt->toDateTimeString(), $alert->refresh()->notified_at->toDateTimeString());
        $this->assertNotNull($alert->notification_acknowledged_at);
    }

    public function test_scheduler_records_failure_and_notifies_super_admin(): void
    {
        $superAdminEmployee = Employee::factory()->create();
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'employee_id' => $superAdminEmployee->id,
        ]);

        // Mock failure by binding a service that throws exception
        $this->mock(EwsEngineService::class, function ($mock) {
            $mock->shouldReceive('run')->andThrow(new \RuntimeException('Engine error simulation'));
        });

        try {
            app(EwsEngineService::class)->run();
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('Engine error simulation', $e->getMessage());
        }

        // Resolve a real EwsEngineService but pass it a notification service mock that throws exception.
        // This will trigger the catch block in EwsEngineService, update the run status to 'gagal', and notify the Super Admin.
        /** @var MockInterface&NotificationService $notificationMock */
        $notificationMock = $this->mock(NotificationService::class);

        /** @var Expectation $kgbNotificationExpectation */
        $kgbNotificationExpectation = $notificationMock->shouldReceive('upsertEwsReminder');
        $kgbNotificationExpectation
            ->with(\Mockery::any(), \Mockery::any(), 'ews.kgb', \Mockery::any(), \Mockery::any(), \Mockery::any())
            ->once()
            ->andThrow(new \RuntimeException('Service failure simulation'));

        /** @var Expectation $failureNotificationExpectation */
        $failureNotificationExpectation = $notificationMock->shouldReceive('createForEmployee');
        $failureNotificationExpectation
            ->with(
                \Mockery::any(),
                'ews.scheduler_failed',
                \Mockery::any(),
                // Isi notifikasi harus berupa pesan umum yang aman; pesan exception mentah
                // tidak boleh bocor ke UI karena bisa memuat detail sensitif server.
                \Mockery::on(fn (string $body): bool => ! str_contains($body, 'Service failure simulation'))
            )
            ->once()
            ->andReturn(new SimpegNotification);

        try {
            // Seed an employee to trigger notification code path
            Employee::factory()->create([
                'tanggal_kgb_berikutnya' => now()->addDays(60)->toDateString(),
            ]);

            $engine = new EwsEngineService($notificationMock);
            $engine->run();
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            // Verify run status was updated to gagal
            $this->assertSame(1, EwsSchedulerRun::count());
            $run = EwsSchedulerRun::first();
            $this->assertSame('gagal', $run->status);
            $this->assertStringContainsString('Service failure simulation', $run->error_message);
        }
    }
}
