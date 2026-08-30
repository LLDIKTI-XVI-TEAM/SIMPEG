<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\LeaveApproval;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefNotificationChannel;
use App\Models\RefStatusPegawai;
use App\Services\Notifications\WhatsApp\WhatsAppPrivacyGuard;
use App\Services\Notifications\WhatsApp\WhatsAppRuntimeConfig;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateContract;
use App\Services\Notifications\WhatsApp\WhatsAppTemplatePayloadMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppTemplatePayloadMapperTest extends TestCase
{
    use RefreshDatabase;

    private WhatsAppTemplatePayloadMapper $mapper;

    private WhatsAppRuntimeConfig $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.event_templates' => WhatsAppTemplateContract::eventTemplateArchetypes(),
            'services.whatsapp.templates' => [],
        ]);
        config([
            'services.whatsapp.canonical_url' => 'https://simpeg.lldikti16.kemdikbud.go.id',
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan' => [
                'id' => 'tmpl_cuti_perlu_tindakan_123',
                'language' => 'id',
                // Tautan detail disalurkan lewat tombol URL, bukan parameter body.
                'variables_map' => [
                    'nama_pegawai' => '1',
                    'jenis_cuti' => '2',
                    'tanggal_mulai' => '3',
                    'tanggal_selesai' => '4',
                    'jumlah_hari' => '5',
                    'alasan' => '6',
                ],
                'button' => [
                    'type' => 'url',
                    'parameter' => 'button_target_url',
                ],
            ],
            'services.whatsapp.templates.simpeg_cuti_status' => [
                'id' => 'tmpl_cuti_status_123',
                'language' => 'id',
                'variables_map' => [
                    'nama_pegawai' => '1',
                    'jenis_cuti' => '2',
                    'status' => '3',
                    'keterangan' => '4',
                ],
                'button' => [
                    'type' => 'url',
                    'parameter' => 'button_target_url',
                ],
            ],
            'services.whatsapp.templates.simpeg_ews_pengingat' => [
                'id' => 'tmpl_ews_pengingat_123',
                'language' => 'id',
                'variables_map' => [
                    'nama_pegawai' => '1',
                    'jenis_peringatan' => '2',
                    'tanggal_target' => '3',
                    'sisa_waktu' => '4',
                ],
                'button' => [
                    'type' => 'url',
                    'parameter' => 'button_target_url',
                ],
            ],
        ]);
        $this->runtime = new WhatsAppRuntimeConfig;
        $this->persistRuntimeTemplateConfig();
        $this->mapper = new WhatsAppTemplatePayloadMapper(new WhatsAppPrivacyGuard, $this->runtime);
    }

    /** Menyalin fixture kontrak mapper ke setting DB sumber runtime. */
    private function persistRuntimeTemplateConfig(): void
    {
        $channel = RefNotificationChannel::query()->where('code', 'whatsapp_business')->first()
            ?? RefNotificationChannel::create([
                'code' => 'whatsapp_business',
                'name' => 'WhatsApp Business',
                'is_enabled' => false,
            ]);
        $contract = json_encode([
            'event_templates' => config('services.whatsapp.event_templates', []),
            'templates' => config('services.whatsapp.templates', []),
        ], JSON_THROW_ON_ERROR);

        $channel->forceFill(['config' => [
            'provider' => 'qontak',
            'base_url' => 'https://service-chat.qontak.com/api/open/v1',
            'canonical_url' => config('services.whatsapp.canonical_url'),
            'template_configuration' => $contract,
        ]])->save();

        $this->runtime->invalidate();
    }

    private function formatDate(\DateTimeInterface $date): string
    {
        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        $day = (int) $date->format('j');
        $month = (int) $date->format('n');
        $year = (int) $date->format('Y');

        return sprintf('%02d %s %d', $day, $months[$month], $year);
    }

    private function createLeaveRequest(Employee $employee, array $attributes = []): LeaveRequest
    {
        $jenisCuti = RefJenisCuti::firstOrCreate(
            ['code' => 'tahunan'],
            [
                'nama' => 'Cuti Tahunan',
                'mengurangi_saldo_tahunan' => true,
                'khusus_pns' => false,
            ],
        );

        return LeaveRequest::create(array_merge([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-09-01',
            'tanggal_selesai' => '2026-09-03',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga',
            'status' => 'menunggu_approval',
        ], $attributes));
    }

    public function test_memetakan_cuti_perlu_tindakan_dengan_variabel_lengkap(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Ahmad Fauzi, S.Kom.']);
        $leaveRequest = $this->createLeaveRequest($employee);

        $payload = $this->mapper->map('cuti.pengajuan_baru', $employee, [
            'leave_request_id' => $leaveRequest->id,
            'url' => '/dashboard/cuti/'.$leaveRequest->id,
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('simpeg_cuti_perlu_tindakan', $payload->templateKey);
        $this->assertSame('tmpl_cuti_perlu_tindakan_123', $payload->templateId);
        $this->assertSame('id', $payload->language);
        $this->assertSame('cuti.pengajuan_baru', $payload->eventKey);
        $this->assertSame('Ahmad Fauzi, S.Kom.', $payload->variables['nama_pegawai'] ?? null);
        $this->assertSame('Cuti Tahunan', $payload->variables['jenis_cuti'] ?? null);
        $this->assertSame('01 September 2026', $payload->variables['tanggal_mulai'] ?? null);
        $this->assertSame('03 September 2026', $payload->variables['tanggal_selesai'] ?? null);
        $this->assertSame('3 hari kerja', $payload->variables['jumlah_hari'] ?? null);
        $this->assertSame('Keperluan keluarga', $payload->variables['alasan'] ?? null);
        $this->assertSame('https://simpeg.lldikti16.kemdikbud.go.id/dashboard/cuti/'.$leaveRequest->id, $payload->variables['tautan_detail'] ?? null);
        $this->assertSame('https://simpeg.lldikti16.kemdikbud.go.id/dashboard/cuti/'.$leaveRequest->id, $payload->buttonVariables['button_target_url'] ?? null);
    }

    public function test_memetakan_cuti_perlu_tindakan_memuat_pemohon_nonaktif_dari_riwayat_dan_tidak_memakai_nama_approver(): void
    {
        $applicant = Employee::factory()->create(['nama_lengkap' => 'Pemohon Asli']);
        $approver = Employee::factory()->create(['nama_lengkap' => 'Approver Atasan']);
        $leaveRequest = $this->createLeaveRequest($applicant);

        // Penonaktifan status tidak menghapus Data Pegawai. Mapper harus tetap dapat
        // membaca pemohon historis dari relasi biasa tanpa ketergantungan SoftDeletes.
        $applicant->statusPegawai()->associate(
            RefStatusPegawai::query()->firstOrCreate(
                ['kode' => 'NONAKTIF'],
                ['nama' => 'Nonaktif', 'kelompok' => 'Nonaktif', 'is_default' => false],
            ),
        );
        $applicant->save();

        $payload = $this->mapper->map('cuti.pengajuan_baru', $approver, [
            'leave_request_id' => $leaveRequest->id,
            'url' => '/dashboard/cuti/'.$leaveRequest->id,
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('Pemohon Asli', $payload->variables['nama_pegawai']);
        $this->assertNotSame('Approver Atasan', $payload->variables['nama_pegawai']);
    }

    public function test_mapper_fail_closed_bila_event_tidak_memiliki_mapping_template_eksplisit(): void
    {
        $employee = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($employee);

        config(['services.whatsapp.event_templates' => []]);
        $this->persistRuntimeTemplateConfig();

        $this->assertNull($this->mapper->map('cuti.pengajuan_baru', $employee, [
            'leave_request_id' => $leaveRequest->id,
        ]));
    }

    public function test_exact_variables_map_dan_button_parameter_provider(): void
    {
        config([
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan.variables_map' => [
                'nama_pegawai' => '1',
                'jenis_cuti' => '2',
                'tanggal_mulai' => '3',
                'tanggal_selesai' => '4',
                'jumlah_hari' => '5',
                'alasan' => '6',
            ],
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan.button' => [
                'type' => 'url',
                'parameter' => 'button_target_url',
            ],
        ]);
        $this->persistRuntimeTemplateConfig();

        $employee = Employee::factory()->create(['nama_lengkap' => 'Ahmad Fauzi']);
        $leaveRequest = $this->createLeaveRequest($employee);

        $payload = $this->mapper->map('cuti.pengajuan_baru', $employee, [
            'leave_request_id' => $leaveRequest->id,
            'url' => '/dashboard/cuti/'.$leaveRequest->id,
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('Ahmad Fauzi', $payload->bodyVariables['1'] ?? null);
        $this->assertSame('Cuti Tahunan', $payload->bodyVariables['2'] ?? null);
        $this->assertSame('https://simpeg.lldikti16.kemdikbud.go.id/dashboard/cuti/'.$leaveRequest->id, $payload->buttonVariables['button_target_url'] ?? null);
    }

    public function test_cuti_tindakan_fail_closed_bila_format_uuid_invalid(): void
    {
        $employee = Employee::factory()->create();

        $payload = $this->mapper->map('cuti.pengajuan_baru', $employee, [
            'leave_request_id' => 'req-1-invalid-uuid',
        ]);

        $this->assertNull($payload);
    }

    public function test_ews_fail_closed_bila_format_uuid_invalid(): void
    {
        $employee = Employee::factory()->create();

        $payload = $this->mapper->map('ews.kenaikan_pangkat', $employee, [
            'ews_alert_id' => 'alert-1-invalid-uuid',
        ]);

        $this->assertNull($payload);
    }

    public function test_template_id_atau_bahasa_kosong_fail_closed(): void
    {
        // Template ID kosong -> fail closed
        config(['services.whatsapp.templates.simpeg_cuti_perlu_tindakan.id' => null]);
        $this->persistRuntimeTemplateConfig();
        $employee = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($employee);
        $this->assertNull($this->mapper->map('cuti.pengajuan_baru', $employee, ['leave_request_id' => $leaveRequest->id]));

        // Bahasa kosong -> fail closed
        config([
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan.id' => 'tmpl_123',
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan.language' => null,
        ]);
        $this->persistRuntimeTemplateConfig();
        $this->assertNull($this->mapper->map('cuti.pengajuan_baru', $employee, ['leave_request_id' => $leaveRequest->id]));
    }

    public function test_kontrak_template_tanpa_variables_map_dan_tombol_provider_fail_closed(): void
    {
        $employee = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($employee);

        config([
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan.variables_map' => [],
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan.button' => null,
        ]);
        $this->persistRuntimeTemplateConfig();

        $this->assertNull($this->mapper->map('cuti.pengajuan_baru', $employee, [
            'leave_request_id' => $leaveRequest->id,
        ]));
    }

    public function test_canonical_url_kosong_atau_tidak_valid_fail_closed(): void
    {
        $employee = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($employee);

        // Canonical URL belum ditetapkan -> fail closed
        config(['services.whatsapp.canonical_url' => null]);
        $this->persistRuntimeTemplateConfig();
        $this->assertNull($this->mapper->map('cuti.pengajuan_baru', $employee, ['leave_request_id' => $leaveRequest->id]));

        // Canonical URL skema http -> fail closed
        config(['services.whatsapp.canonical_url' => 'http://simpeg.lldikti16.kemdikbud.go.id']);
        $this->persistRuntimeTemplateConfig();
        $this->assertNull($this->mapper->map('cuti.pengajuan_baru', $employee, ['leave_request_id' => $leaveRequest->id]));

        // Canonical URL localhost -> fail closed
        config(['services.whatsapp.canonical_url' => 'https://localhost']);
        $this->persistRuntimeTemplateConfig();
        $this->assertNull($this->mapper->map('cuti.pengajuan_baru', $employee, ['leave_request_id' => $leaveRequest->id]));

        // Canonical URL IPv6 loopback -> fail closed
        config(['services.whatsapp.canonical_url' => 'https://[::1]']);
        $this->persistRuntimeTemplateConfig();
        $this->assertNull($this->mapper->map('cuti.pengajuan_baru', $employee, ['leave_request_id' => $leaveRequest->id]));
    }

    public function test_memetakan_cuti_disetujui_dengan_leave_approval_id_cocok(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Budi Santoso']);
        $leaveRequest = $this->createLeaveRequest($employee, ['status' => 'disetujui']);
        $approver = Employee::factory()->create();
        $approval = LeaveApproval::create([
            'leave_request_id' => $leaveRequest->id,
            'approver_id' => $approver->id,
            'stage' => 1,
            'action' => 'APPROVE',
            'komentar' => 'Disetujui untuk dinas dan cuti tahunan.',
            'acted_at' => now(),
        ]);

        $payload = $this->mapper->map('cuti.disetujui', $employee, [
            'leave_request_id' => $leaveRequest->id,
            'leave_approval_id' => $approval->id,
            'url' => '/cuti/'.$leaveRequest->id,
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('simpeg_cuti_status', $payload->templateKey);
        $this->assertSame('tmpl_cuti_status_123', $payload->templateId);
        $this->assertSame('Disetujui', $payload->variables['status']);
        $this->assertSame('Disetujui untuk dinas dan cuti tahunan.', $payload->variables['keterangan']);
        $this->assertSame('https://simpeg.lldikti16.kemdikbud.go.id/cuti/'.$leaveRequest->id, $payload->variables['tautan_detail']);
    }

    public function test_url_http_localhost_atau_domain_arbitrer_fail_closed(): void
    {
        $employee = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($employee);

        // Skema http ditolak
        $payloadHttp = $this->mapper->map('cuti.pengajuan_baru', $employee, [
            'leave_request_id' => $leaveRequest->id,
            'url' => 'http://simpeg.lldikti16.kemdikbud.go.id/cuti/'.$leaveRequest->id,
        ]);
        $this->assertNull($payloadHttp);

        // Localhost ditolak
        $payloadLocalhost = $this->mapper->map('cuti.pengajuan_baru', $employee, [
            'leave_request_id' => $leaveRequest->id,
            'url' => 'https://localhost/cuti/'.$leaveRequest->id,
        ]);
        $this->assertNull($payloadLocalhost);

        // 127.0.0.1 ditolak
        $payloadIp = $this->mapper->map('cuti.pengajuan_baru', $employee, [
            'leave_request_id' => $leaveRequest->id,
            'url' => 'https://127.0.0.1/cuti/'.$leaveRequest->id,
        ]);
        $this->assertNull($payloadIp);

        // Domain arbitrer luar ditolak
        $payloadArbitrary = $this->mapper->map('cuti.pengajuan_baru', $employee, [
            'leave_request_id' => $leaveRequest->id,
            'url' => 'https://evil-phishing.com/cuti/'.$leaveRequest->id,
        ]);
        $this->assertNull($payloadArbitrary);

        // Domain resmi SIMPEG dengan HTTPS diterima
        $payloadOfficial = $this->mapper->map('cuti.pengajuan_baru', $employee, [
            'leave_request_id' => $leaveRequest->id,
            'url' => 'https://simpeg.lldikti16.kemdikbud.go.id/cuti/'.$leaveRequest->id,
        ]);
        $this->assertNotNull($payloadOfficial);
    }

    public function test_cuti_disetujui_fail_closed_jika_leave_approval_id_mismatch_atau_action_berbeda(): void
    {
        $employee = Employee::factory()->create();
        $leaveRequest1 = $this->createLeaveRequest($employee);
        $leaveRequest2 = $this->createLeaveRequest($employee);

        $approver = Employee::factory()->create();
        $approval1 = LeaveApproval::create([
            'leave_request_id' => $leaveRequest1->id,
            'approver_id' => $approver->id,
            'stage' => 1,
            'action' => 'APPROVE',
            'komentar' => 'OK 1',
            'acted_at' => now(),
        ]);

        // Mismatch request_id dan approval_id
        $payloadMismatchRequest = $this->mapper->map('cuti.disetujui', $employee, [
            'leave_request_id' => $leaveRequest2->id,
            'leave_approval_id' => $approval1->id,
        ]);
        $this->assertNull($payloadMismatchRequest);

        // Action mismatch (misal action POSTPONE tetapi event cuti.disetujui)
        $approvalPostpone = LeaveApproval::create([
            'leave_request_id' => $leaveRequest1->id,
            'approver_id' => $approver->id,
            'stage' => 1,
            'action' => 'POSTPONE',
            'komentar' => 'Tunda',
            'acted_at' => now(),
        ]);
        $payloadMismatchAction = $this->mapper->map('cuti.disetujui', $employee, [
            'leave_request_id' => $leaveRequest1->id,
            'leave_approval_id' => $approvalPostpone->id,
        ]);
        $this->assertNull($payloadMismatchAction);
    }

    public function test_cuti_ditangguhkan_tugas_dinas_memetakan_keterangan_khusus(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Citra Lestari']);
        $leaveRequest = $this->createLeaveRequest($employee, ['status' => LeaveRequest::STATUS_DUTY_POSTPONED]);
        $approver = Employee::factory()->create();
        $approval = LeaveApproval::create([
            'leave_request_id' => $leaveRequest->id,
            'approver_id' => $approver->id,
            'stage' => 1,
            'action' => 'DUTY_POSTPONEMENT',
            'komentar' => 'Penugasan akreditasi mendesak',
            'acted_at' => now(),
        ]);

        $payload = $this->mapper->map('cuti.ditangguhkan_tugas_dinas', $employee, [
            'leave_request_id' => $leaveRequest->id,
            'leave_approval_id' => $approval->id,
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('Ditangguhkan', $payload->variables['status']);
        $this->assertStringContainsString('Penugasan akreditasi mendesak', $payload->variables['keterangan']);
        $this->assertStringContainsString('Hak cuti dilindungi', $payload->variables['keterangan']);
    }

    public function test_cuti_dikembalikan_karena_rollover_memetakan_keterangan_khusus(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Dewi Sartika']);

        $payload = $this->mapper->map('cuti.dikembalikan_karena_rollover', $employee, [
            'leave_request_id' => '9f2a2432-8df7-4a0b-8d14-3d9a3f2b6e1b',
            'url' => '/cuti/req-1',
            'source_year' => 2026,
            'target_year' => 2027,
            'jumlah_pengajuan' => 2,
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('simpeg_cuti_status', $payload->templateKey);
        $this->assertSame('Perubahan', $payload->variables['status']);
        $this->assertStringContainsString('tahun 2026 (2 berkas)', $payload->variables['keterangan']);
        $this->assertStringContainsString('tahun 2027', $payload->variables['keterangan']);
        $this->assertSame('https://simpeg.lldikti16.kemdikbud.go.id/dashboard/cuti', $payload->variables['tautan_detail']);
        $this->assertSame('https://simpeg.lldikti16.kemdikbud.go.id/dashboard/cuti', $payload->buttonVariables['button_target_url']);
    }

    public function test_cuti_dikembalikan_karena_rollover_menghitung_semua_request_dari_payload(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Dewi Sartika']);

        $payload = $this->mapper->map('cuti.dikembalikan_karena_rollover', $employee, [
            'source_year' => 2026,
            'target_year' => 2027,
            'leave_request_ids' => ['request-1', 'request-2', 'request-3'],
        ]);

        $this->assertNotNull($payload);
        $this->assertStringContainsString('tahun 2026 (3 berkas)', $payload->variables['keterangan']);
    }

    public function test_cuti_status_fail_closed_bila_komentar_mengandung_nik(): void
    {
        $employee = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($employee);
        $approver = Employee::factory()->create();
        $approval = LeaveApproval::create([
            'leave_request_id' => $leaveRequest->id,
            'approver_id' => $approver->id,
            'stage' => 1,
            'action' => 'APPROVE',
            'komentar' => 'Disetujui untuk pegawai dengan NIK 7171012345678901',
            'acted_at' => now(),
        ]);

        $payload = $this->mapper->map('cuti.disetujui', $employee, [
            'leave_request_id' => $leaveRequest->id,
            'leave_approval_id' => $approval->id,
        ]);

        $this->assertNull($payload);
    }

    public function test_memetakan_ews_pengingat_kenaikan_pangkat_dan_kgb(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Eko Prasetyo']);
        $targetDate = now()->addDays(90)->startOfDay();
        $alert = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => $targetDate->toDateString(),
            'interval_days' => 90,
            'is_eligible' => true,
        ]);

        $payload = $this->mapper->map('ews.kenaikan_pangkat', $employee, [
            'ews_alert_id' => $alert->id,
            'is_eligible' => true,
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('simpeg_ews_pengingat', $payload->templateKey);
        $this->assertSame('tmpl_ews_pengingat_123', $payload->templateId);
        $this->assertSame('Kenaikan Pangkat', $payload->variables['jenis_peringatan']);
        $this->assertSame($this->formatDate($targetDate), $payload->variables['tanggal_target']);
        $this->assertSame('H-90 hari', $payload->variables['sisa_waktu']);
        $this->assertSame('https://simpeg.lldikti16.kemdikbud.go.id/dashboard/ews-saya', $payload->variables['tautan_detail']);
    }

    public function test_ews_admin_mendapat_tautan_pengelolaan_bukan_ews_miliknya_sendiri(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Target']);
        $admin = Employee::factory()->create(['nama_lengkap' => 'Admin Kepegawaian']);
        $alert = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => '2026-10-01',
            'interval_days' => 90,
            'is_eligible' => true,
        ]);

        $payload = $this->mapper->map('ews.kenaikan_pangkat', $admin, [
            'ews_alert_id' => $alert->id,
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('https://simpeg.lldikti16.kemdikbud.go.id/ews', $payload->variables['tautan_detail']);
    }

    public function test_ews_tipe_alert_mismatch_dengan_event_fail_closed(): void
    {
        $employee = Employee::factory()->create();
        // Alert KGB dicoba dipetakan ke event ews.pensiun
        $alertKgb = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KGB',
            'target_date' => '2026-10-01',
            'interval_days' => 60,
            'is_eligible' => true,
        ]);

        $payload = $this->mapper->map('ews.pensiun', $employee, [
            'ews_alert_id' => $alertKgb->id,
        ]);

        $this->assertNull($payload);
    }

    public function test_ews_kenaikan_pangkat_ineligible_di_database_tetap_fail_closed_meski_payload_true(): void
    {
        $employee = Employee::factory()->create();
        $alert = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => '2026-10-01',
            'interval_days' => 90,
            'is_eligible' => false,
        ]);

        // Payload mencoba menimpa is_eligible => true
        $payload = $this->mapper->map('ews.kenaikan_pangkat', $employee, [
            'ews_alert_id' => $alert->id,
            'is_eligible' => true,
        ]);

        // Harus tetap fail closed karena data di DB tidak eligible
        $this->assertNull($payload);
    }

    public function test_memetakan_ews_satyalancana_wajib_milestone_10_20_30(): void
    {
        foreach ([10, 20, 30] as $years) {
            $employee = Employee::factory()->create(['nama_lengkap' => 'Farhan '.$years]);
            $alert = EwsAlert::create([
                'employee_id' => $employee->id,
                'type' => 'SATYALANCANA',
                'target_date' => '2026-11-10',
                'interval_days' => 60,
                'is_eligible' => true,
                'satyalancana_years' => $years,
            ]);

            $payload = $this->mapper->map('ews.satyalancana', $employee, [
                'ews_alert_id' => $alert->id,
                'is_eligible' => true,
            ]);

            $this->assertNotNull($payload);
            $this->assertSame("Satyalancana Karya Satya {$years} Tahun", $payload->variables['jenis_peringatan']);
        }

        // Milestone tidak valid (misal 15 tahun) -> fail closed
        $invalidEmployee = Employee::factory()->create(['nama_lengkap' => 'Farhan Invalid']);
        $invalidAlert = EwsAlert::create([
            'employee_id' => $invalidEmployee->id,
            'type' => 'SATYALANCANA',
            'target_date' => '2026-11-10',
            'interval_days' => 60,
            'is_eligible' => true,
            'satyalancana_years' => 15,
        ]);

        $invalidPayload = $this->mapper->map('ews.satyalancana', $invalidEmployee, [
            'ews_alert_id' => $invalidAlert->id,
            'is_eligible' => true,
        ]);

        $this->assertNull($invalidPayload);
    }

    public function test_event_tidak_dikenal_atau_diluar_allowlist_fail_closed(): void
    {
        $employee = Employee::factory()->create();

        $this->assertNull($this->mapper->map('ews.scheduler_failed', $employee, []));
        $this->assertNull($this->mapper->map('import_pegawai', $employee, []));
        $this->assertNull($this->mapper->map('auth.login', $employee, []));
    }

    public function test_konfigurasi_dapat_memecah_template_per_event_tanpa_mengubah_domain_code(): void
    {
        // Provider Meta mewajibkan split template per event (K-MTG-05A):
        // cuti.disetujui -> cuti_disetujui_v1
        // cuti.ditunda -> cuti_ditangguhkan_v1
        config([
            'services.whatsapp.event_templates' => [
                'cuti.disetujui' => 'cuti_disetujui_v1',
                'cuti.ditunda' => 'cuti_ditangguhkan_v1',
            ],
            'services.whatsapp.templates.cuti_disetujui_v1' => [
                'archetype' => 'simpeg_cuti_status',
                'id' => 'tmpl_provider_cuti_approved_999',
                'language' => 'id',
                'variables_map' => [
                    'nama_pegawai' => '1',
                    'jenis_cuti' => '2',
                    'status' => '3',
                    'keterangan' => '4',
                ],
                'button' => [
                    'type' => 'url',
                    'parameter' => 'button_target_url',
                ],
            ],
            'services.whatsapp.templates.cuti_ditangguhkan_v1' => [
                'archetype' => 'simpeg_cuti_status',
                'id' => 'tmpl_provider_cuti_postponed_888',
                'language' => 'id',
                'variables_map' => [
                    'nama_pegawai' => '1',
                    'jenis_cuti' => '2',
                    'status' => '3',
                    'keterangan' => '4',
                ],
                'button' => [
                    'type' => 'url',
                    'parameter' => 'button_target_url',
                ],
            ],
        ]);
        $this->persistRuntimeTemplateConfig();

        $employee = Employee::factory()->create(['nama_lengkap' => 'Budi Santoso']);
        $leaveRequest = $this->createLeaveRequest($employee);
        $approval = LeaveApproval::create([
            'leave_request_id' => $leaveRequest->id,
            'approver_id' => $employee->id,
            'stage' => 1,
            'action' => 'APPROVE',
            'komentar' => 'Disetujui penuh.',
            'acted_at' => now(),
        ]);

        $payload = $this->mapper->map('cuti.disetujui', $employee, [
            'leave_request_id' => $leaveRequest->id,
            'leave_approval_id' => $approval->id,
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('cuti_disetujui_v1', $payload->templateKey);
        $this->assertSame('tmpl_provider_cuti_approved_999', $payload->templateId);
        $this->assertSame('Disetujui', $payload->variables['status']);
    }

    public function test_template_split_hanya_mengirim_subset_variabel_yang_disetujui_provider(): void
    {
        config([
            'services.whatsapp.event_templates' => array_merge(
                config('services.whatsapp.event_templates', []),
                ['cuti.disetujui' => 'cuti_disetujui_ringkas_v1'],
            ),
            'services.whatsapp.templates.cuti_disetujui_ringkas_v1' => [
                'archetype' => 'simpeg_cuti_status',
                'id' => 'tmpl_provider_cuti_ringkas_001',
                'language' => 'id',
                'variables_map' => [
                    'nama_pegawai' => '1',
                    'status' => '2',
                ],
                'button' => ['type' => 'url', 'parameter' => 'cta_url'],
            ],
        ]);
        $this->persistRuntimeTemplateConfig();

        $employee = Employee::factory()->create(['nama_lengkap' => 'Nadia Pegawai']);
        $leaveRequest = $this->createLeaveRequest($employee, ['status' => 'disetujui']);
        $approval = LeaveApproval::create([
            'leave_request_id' => $leaveRequest->id,
            'approver_id' => $employee->id,
            'stage' => 1,
            'action' => 'APPROVE',
            'komentar' => 'Disetujui.',
            'acted_at' => now(),
        ]);

        $payload = $this->mapper->map('cuti.disetujui', $employee, [
            'leave_request_id' => $leaveRequest->id,
            'leave_approval_id' => $approval->id,
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('cuti_disetujui_ringkas_v1', $payload->templateKey);
        $this->assertSame(['1' => 'Nadia Pegawai', '2' => 'Disetujui'], $payload->bodyVariables);
        $this->assertSame(['cta_url' => 'https://simpeg.lldikti16.kemdikbud.go.id/cuti/'.$leaveRequest->id], $payload->buttonVariables);
    }

    public function test_ews_fail_closed_bila_pemilik_alert_tidak_tersedia(): void
    {
        $owner = Employee::factory()->create(['nama_lengkap' => 'Pegawai Target']);
        $adminRecipient = Employee::factory()->create(['nama_lengkap' => 'Admin Penerima']);
        $alert = EwsAlert::create([
            'employee_id' => $owner->id,
            'type' => 'KGB',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'is_eligible' => true,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        $owner->delete();

        $payload = $this->mapper->map('ews.kgb', $adminRecipient, [
            'ews_alert_id' => $alert->id,
        ]);

        $this->assertNull($payload);
    }
}
