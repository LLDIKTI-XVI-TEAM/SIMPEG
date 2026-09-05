<?php

namespace Tests\Feature;

use App\Actions\Cuti\CancelManualLeaveUsageAction;
use App\Actions\Cuti\CorrectManualLeaveUsageAction;
use App\Actions\Cuti\StoreManualLeaveUsageAction;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveUsageDocument;
use App\Models\LeaveUsageExternalApprovalStep;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ManualExternalApprovalLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-21 09:00:00');
        $this->seed(RbacSeeder::class);
        Storage::fake(LeaveUsageDocument::STORAGE_DISK);
        Queue::fake();
        Mail::fake();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_create_correction_cancel_menjaga_snapshot_immutable_dan_tanpa_approval_ulang(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $before = $this->approvalChannelCounts($employee);

        $current = $this->store($employee, $admin, $this->validSteps('Awal'), null);
        $currentSteps = $current->externalApprovalSteps()->orderBy('step_order')->get();
        $this->assertCount(2, $currentSteps);
        $this->assertSame('SURAT/2026/001', $current->approval_document_number);
        $this->assertDatabaseCount('leave_usage_documents', 0);

        $replacement = app(CorrectManualLeaveUsageAction::class)->execute(
            $current->id,
            array_merge($this->validData($this->validSteps('Koreksi')), [
                'approval_document_number' => null,
                'correction_reason' => 'Identitas approver historis diperbaiki.',
            ]),
            null,
            $admin,
            $this->requestFor($admin),
        );
        $replacementSteps = $replacement->externalApprovalSteps()->orderBy('step_order')->get();

        $this->assertSame(LeaveUsageRecord::STATUS_SUPERSEDED, $current->fresh()->record_status);
        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $replacement->record_status);
        $this->assertNull($replacement->approval_document_number);
        $this->assertNotEqualsCanonicalizing($currentSteps->pluck('id')->all(), $replacementSteps->pluck('id')->all());
        $this->assertSame(['Kepala Bagian Awal', 'PYBMC Awal'], $currentSteps->pluck('approver_name_snapshot')->all());
        $this->assertSame(['Kepala Bagian Koreksi', 'PYBMC Koreksi'], $replacementSteps->pluck('approver_name_snapshot')->all());

        $stepIdsBeforeCancel = $replacementSteps->pluck('id')->all();
        app(CancelManualLeaveUsageAction::class)->execute(
            $replacement->id,
            'Persetujuan external dibatalkan oleh instansi asal.',
            $admin,
            $this->requestFor($admin),
        );

        $this->assertSame(LeaveUsageRecord::STATUS_CANCELLED, $replacement->fresh()->record_status);
        $this->assertSame(
            $stepIdsBeforeCancel,
            $replacement->externalApprovalSteps()->orderBy('step_order')->pluck('id')->all(),
        );
        $this->assertSame($before, $this->approvalChannelCounts($employee));
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_koreksi_fakta_tanpa_perubahan_approver_mempertahankan_snapshot_internal(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $kepalaBagian = Employee::factory()->create([
            'nama_lengkap' => 'Kepala Bagian Historis',
            'nip' => null,
            'jabatan_terakhir' => 'Kepala Bagian Lama',
        ]);
        $pybmc = Employee::factory()->create([
            'nama_lengkap' => 'PYBMC Historis',
            'nip' => '197501012000031001',
            'jabatan_terakhir' => 'Kepala Lembaga Lama',
        ]);
        $steps = $this->internalSteps($kepalaBagian, $pybmc);
        $current = $this->store($employee, $admin, $steps, null);

        $kepalaBagian->forceFill([
            'nama_lengkap' => 'Kepala Bagian Saat Ini',
            'nip' => '198001012006041999',
            'jabatan_terakhir' => 'Kepala Bagian Baru',
        ])->save();
        $pybmc->forceFill([
            'nama_lengkap' => 'PYBMC Saat Ini',
            'nip' => '197501012000031999',
            'jabatan_terakhir' => 'Kepala Lembaga Baru',
        ])->save();

        $replacement = app(CorrectManualLeaveUsageAction::class)->execute(
            $current->id,
            array_merge($this->validData($steps), [
                'alasan' => 'Keterangan fakta diperbaiki tanpa mengubah rangkaian approver.',
                'correction_reason' => 'Koreksi keterangan administratif.',
            ]),
            null,
            $admin,
            $this->requestFor($admin),
        );

        $this->assertSame(
            [
                ['Kepala Bagian Historis', null, 'Kepala Bagian Lama'],
                ['PYBMC Historis', '197501012000031001', 'Kepala Lembaga Lama'],
            ],
            $replacement->externalApprovalSteps()
                ->orderBy('step_order')
                ->get()
                ->map(fn (LeaveUsageExternalApprovalStep $step): array => [
                    $step->approver_name_snapshot,
                    $step->approver_nip_snapshot,
                    $step->approver_position_snapshot,
                ])
                ->all(),
        );
    }

    public function test_koreksi_mencocokkan_snapshot_internal_saat_tahap_dihapus_dan_uuid_diganti(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $verifier = Employee::factory()->create([
            'nama_lengkap' => 'Verifikator Lama',
            'nip' => '198101012007041001',
            'jabatan_terakhir' => 'Verifikator',
        ]);
        $kepalaBagian = Employee::factory()->create([
            'nama_lengkap' => 'Kepala Bagian Tersimpan',
            'nip' => '198201012008041001',
            'jabatan_terakhir' => 'Kepala Bagian Tersimpan',
        ]);
        $pybmcLama = Employee::factory()->create();
        $pybmcBaru = Employee::factory()->create([
            'nama_lengkap' => 'PYBMC Pengganti',
            'nip' => '197001011995031001',
            'jabatan_terakhir' => 'Kepala Lembaga Pengganti',
        ]);
        $originalSteps = [
            [
                'step_type' => 'verifier',
                'approver_source' => 'simpeg_employee',
                'approver_employee_id' => $verifier->id,
                'approver_name' => null,
                'approver_position' => null,
                'approver_institution' => null,
                'acted_on' => '2026-08-16',
                'decision_note' => null,
            ],
            ...$this->internalSteps($kepalaBagian, $pybmcLama),
        ];
        $current = $this->store($employee, $admin, $originalSteps, null);

        $kepalaBagian->forceFill([
            'nama_lengkap' => 'Kepala Bagian Profil Kini',
            'nip' => '198201012008041999',
            'jabatan_terakhir' => 'Jabatan Profil Kini',
        ])->save();

        $replacement = app(CorrectManualLeaveUsageAction::class)->execute(
            $current->id,
            array_merge($this->validData($this->internalSteps($kepalaBagian, $pybmcBaru)), [
                'correction_reason' => 'Hapus tahap verifier dan perbaiki PYBMC historis.',
            ]),
            null,
            $admin,
            $this->requestFor($admin),
        );
        $replacementSteps = $replacement->externalApprovalSteps()->orderBy('step_order')->get();

        $this->assertSame('Kepala Bagian Tersimpan', $replacementSteps[0]->approver_name_snapshot);
        $this->assertSame('198201012008041001', $replacementSteps[0]->approver_nip_snapshot);
        $this->assertSame('Kepala Bagian Tersimpan', $replacementSteps[0]->approver_position_snapshot);
        $this->assertSame($pybmcBaru->id, $replacementSteps[1]->approver_employee_id);
        $this->assertSame('PYBMC Pengganti', $replacementSteps[1]->approver_name_snapshot);
        $this->assertSame('197001011995031001', $replacementSteps[1]->approver_nip_snapshot);
        $this->assertSame('Kepala Lembaga Pengganti', $replacementSteps[1]->approver_position_snapshot);
    }

    public function test_cancel_endpoint_status_only_menolak_bukti_baru_dan_mempertahankan_dokumen_snapshot(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
        ]);
        $annualType = RefJenisCuti::query()->firstOrCreate(
            ['code' => 'tahunan'],
            ['nama' => 'Cuti Tahunan', 'mengurangi_saldo_tahunan' => true, 'khusus_pns' => false],
        );
        $record = app(StoreManualLeaveUsageAction::class)->execute(
            $employee->id,
            array_merge($this->validData($this->validSteps('Status Only')), [
                'leave_type_id' => $annualType->id,
            ]),
            UploadedFile::fake()->createWithContent('bukti-awal.pdf', 'bukti awal immutable'),
            $admin,
            $this->requestFor($admin),
        );
        $url = route('cuti.manual.cancel', $record->id);
        $documentRows = DB::table('leave_usage_documents')->orderBy('id')->get()->toJson();
        $snapshotRows = DB::table('leave_usage_external_approval_steps')->orderBy('step_order')->get()->toJson();
        $files = Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX);
        sort($files);
        $fileBytes = collect($files)->mapWithKeys(
            fn (string $path): array => [$path => Storage::disk(LeaveUsageDocument::STORAGE_DISK)->get($path)],
        )->all();
        $ledgerCount = LeaveBalanceLedger::query()->count();
        $auditCount = AuditLog::query()->count();

        $this->actingAs($admin)->post($url, [
            'correction_reason' => 'Payload bukti baru harus ditolak.',
            'dokumen' => UploadedFile::fake()->createWithContent('bukti-batal.pdf', 'tidak boleh tersimpan'),
            'approval_document_number' => 'BATAL/2026/001',
            'approval_steps' => $this->validSteps('Batal'),
        ])->assertSessionHasErrors([
            'dokumen',
            'approval_document_number',
            'approval_steps',
        ]);

        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $record->fresh()->record_status);
        $this->assertNull($record->fresh()->correction_reason);
        $this->assertSame($documentRows, DB::table('leave_usage_documents')->orderBy('id')->get()->toJson());
        $this->assertSame($snapshotRows, DB::table('leave_usage_external_approval_steps')->orderBy('step_order')->get()->toJson());
        $currentFiles = Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX);
        sort($currentFiles);
        $this->assertSame($files, $currentFiles);
        $this->assertSame($fileBytes, collect($files)->mapWithKeys(
            fn (string $path): array => [$path => Storage::disk(LeaveUsageDocument::STORAGE_DISK)->get($path)],
        )->all());
        $this->assertSame($ledgerCount, LeaveBalanceLedger::query()->count());
        $this->assertSame($auditCount, AuditLog::query()->count());

        $reason = 'Persetujuan eksternal dibatalkan tanpa bukti baru.';
        $this->actingAs($admin)->post($url, [
            'correction_reason' => $reason,
        ])->assertRedirect();

        $this->assertSame(LeaveUsageRecord::STATUS_CANCELLED, $record->fresh()->record_status);
        $this->assertSame($reason, $record->fresh()->correction_reason);
        $this->assertSame(0, LeaveBalance::query()->where('employee_id', $employee->id)->where('tahun', 2026)->sole()->terpakai);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'event_type' => LeaveBalanceLedger::EVENT_USAGE_FACT_CANCELLED,
            'reason' => $reason,
        ]);
        $cancelAudit = AuditLog::query()
            ->where('auditable_type', 'LeaveUsageRecord')
            ->where('auditable_id', $record->id)
            ->where('new_values->operation', 'manual_usage_cancelled')
            ->sole();
        $this->assertSame($reason, $cancelAudit->new_values['reason']);
        $this->assertSame(LeaveUsageRecord::STATUS_CANCELLED, $cancelAudit->new_values['fact_after']['record_status']);
        $this->assertSame($documentRows, DB::table('leave_usage_documents')->orderBy('id')->get()->toJson());
        $this->assertSame($snapshotRows, DB::table('leave_usage_external_approval_steps')->orderBy('step_order')->get()->toJson());
        $currentFiles = Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX);
        sort($currentFiles);
        $this->assertSame($files, $currentFiles);
        $this->assertSame($fileBytes, collect($files)->mapWithKeys(
            fn (string $path): array => [$path => Storage::disk(LeaveUsageDocument::STORAGE_DISK)->get($path)],
        )->all());
    }

    public function test_workspace_cancel_hanya_merender_alasan_tanpa_form_upload(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $record = $this->store(
            $employee,
            $admin,
            $this->validSteps('UI Status Only'),
            UploadedFile::fake()->create('bukti-ui.pdf', 20, 'application/pdf'),
        );

        $this->actingAs($admin)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'tab' => 'manual',
            'edit_usage' => $record->id,
            'manual_action' => 'cancel',
        ]))
            ->assertOk()
            ->assertSee(route('cuti.manual.cancel', $record->id), false)
            ->assertSee('name="correction_reason"', false)
            ->assertDontSee('id="cancellation-document"', false)
            ->assertDontSee('name="dokumen"', false)
            ->assertDontSee('enctype="multipart/form-data"', false)
            ->assertSee('Pembatalan hanya mengubah status fakta aktif', false);
    }

    public function test_upload_opsional_dilewati_sepenuhnya_dan_lifecycle_database_tetap_commit(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $approverLintasPeran = Employee::factory()->create();

        $record = $this->store(
            $employee,
            $admin,
            $this->internalSteps($approverLintasPeran, $approverLintasPeran),
            null,
        );
        $steps = $record->externalApprovalSteps()->orderBy('step_order')->get();

        $this->assertDatabaseHas('leave_usage_records', ['id' => $record->id]);
        $this->assertSame(['kepala_bagian', 'pybmc'], $steps->pluck('step_type')->all());
        $this->assertSame(
            [$approverLintasPeran->id, $approverLintasPeran->id],
            $steps->pluck('approver_employee_id')->all(),
        );
        $this->assertDatabaseCount('leave_usage_documents', 0);
        $this->assertSame([], Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles('cuti/pemakaian'));
        $this->assertDatabaseCount('storage_recovery_tasks', 0);
    }

    public function test_kegagalan_snapshot_setelah_file_disimpan_me_rollback_database_dan_membersihkan_hanya_file_baru(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $dispatcher = LeaveUsageExternalApprovalStep::getEventDispatcher();
        LeaveUsageExternalApprovalStep::creating(function (): void {
            throw new RuntimeException('Simulasi snapshot gagal.');
        });

        try {
            $this->store($employee, $admin, $this->validSteps(), UploadedFile::fake()->create('snapshot-fail.pdf', 20, 'application/pdf'));
            $this->fail('Kegagalan snapshot wajib diteruskan.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulasi snapshot gagal.', $exception->getMessage());
        } finally {
            LeaveUsageExternalApprovalStep::setEventDispatcher($dispatcher);
        }

        $this->assertLifecycleTablesEmpty();
        $this->assertSame([], Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles('cuti/pemakaian'));
    }

    public function test_kegagalan_attachment_setelah_nested_lifecycle_me_rollback_semua_database(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $dispatcher = LeaveUsageDocument::getEventDispatcher();
        LeaveUsageDocument::creating(function (): void {
            throw new RuntimeException('Simulasi attachment gagal.');
        });

        try {
            $this->store($employee, $admin, $this->validSteps(), UploadedFile::fake()->create('attachment-fail.pdf', 20, 'application/pdf'));
            $this->fail('Kegagalan attachment wajib diteruskan.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulasi attachment gagal.', $exception->getMessage());
        } finally {
            LeaveUsageDocument::setEventDispatcher($dispatcher);
        }

        $this->assertLifecycleTablesEmpty();
        $this->assertSame([], Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles('cuti/pemakaian'));
    }

    public function test_audit_snapshot_tidak_menyimpan_path_disk_atau_nama_simpan_privat(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $record = $this->store(
            $employee,
            $admin,
            $this->validSteps("O'Connor <img src=x onerror=alert(1)>"),
            UploadedFile::fake()->create('audit-safe.pdf', 20, 'application/pdf'),
        );

        $values = AuditLog::query()
            ->where('auditable_type', 'LeaveUsageRecord')
            ->where('auditable_id', $record->id)
            ->where('new_values->operation', 'manual_usage_recorded')
            ->sole()
            ->new_values;
        $encoded = json_encode($values, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString("O'Connor <img src=x onerror=alert(1)>", $encoded);
        $this->assertStringNotContainsString('cuti/pemakaian/', $encoded);
        $this->assertStringNotContainsString('stored_name', $encoded);
        $this->assertStringNotContainsString('"disk"', $encoded);
    }

    public function test_workspace_create_memulai_editor_kosong_dan_old_input_menjadi_prioritas(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $url = route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'tab' => 'manual',
        ]);

        $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertViewHas('initialApprovalSteps', [])
            ->assertSee('Riwayat Persetujuan Eksternal', false)
            ->assertSee('Kelompok Pengajuan Cuti (opsional)', false)
            ->assertSee('Contoh: nomor SK atau surat keputusan. Boleh dikosongkan bila tidak tersedia.', false)
            ->assertSee('Pratinjau Rangkaian Saat Ini', false)
            ->assertSee('class="m-auto w-[min(42rem,calc(100%-2rem))]', false)
            ->assertSee('Gunakan Rangkaian Ini', false)
            ->assertSee('<option value="kepala_bagian">Atasan Langsung</option>', false)
            ->assertSee('tepat satu Atasan Langsung', false)
            ->assertSee('Tanggal keputusan <span class="text-danger" aria-hidden="true">*</span>', false)
            ->assertSee('type="date" required', false)
            ->assertSee(':key="step.clientKey"', false)
            ->assertDontSee('x-on:click="moveStep(', false)
            ->assertDontSee('Naikkan tahap', false)
            ->assertDontSee('Turunkan tahap', false)
            ->assertDontSee('name="clientKey"', false)
            ->assertDontSee('[clientKey]', false)
            ->assertDontSee('x-html', false);

        $oldSteps = $this->validSteps('Old Input');
        $this->withSession(['_old_input' => ['approval_steps' => $oldSteps]])
            ->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertViewHas('initialApprovalSteps', $oldSteps);
    }

    public function test_workspace_koreksi_menghidrasi_snapshot_persisted_dan_merender_teks_adversarial_secara_aman(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $adversarial = "O'Connor <img src=x onerror=alert(1)> Ni Luh Śakti";
        $record = $this->store($employee, $admin, $this->validSteps($adversarial), null);

        $response = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'tab' => 'manual',
            'edit_usage' => $record->id,
            'manual_action' => 'correct',
        ]));
        $content = $response->getContent();

        $response->assertOk()
            ->assertViewHas('editableApprovalSteps', function (array $steps) use ($adversarial): bool {
                return count($steps) === 2
                    && $steps[0]['approver_name'] === "Kepala Bagian {$adversarial}"
                    && $steps[1]['approver_name'] === "PYBMC {$adversarial}";
            })
            ->assertViewHas('initialApprovalSteps', function (array $steps) use ($adversarial): bool {
                return $steps[0]['approver_name'] === "Kepala Bagian {$adversarial}";
            })
            ->assertSee('Kelompok Pengajuan Cuti (opsional)', false)
            ->assertSee('Buat baru atau pilih kelompok pengajuan cuti', false)
            ->assertSee('Hanya kelompok pengajuan cuti aktif milik pegawai ini yang dapat dipilih.', false)
            ->assertSee('Contoh: nomor SK atau surat keputusan. Boleh dikosongkan bila tidak tersedia.', false)
            // Feature test membaca source sebelum Alpine berjalan: @js wajib mengodekan karakter
            // berbahaya, sedangkan x-text membuktikan browser akan menampilkannya sebagai teks DOM.
            ->assertSee('x-text="step.approver_label"', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false)
            ->assertDontSee('x-html', false);
        $this->assertStringContainsString('approver_label', $content);
        $this->assertStringContainsString('O\\\\u0027Connor', $content);
        $this->assertStringContainsString('\\\\u003Cimg src=x onerror=alert(1)\\\\u003E', $content);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $content);
    }

    public function test_histori_memakai_snapshot_bounded_dan_memberi_label_legacy_tanpa_fallback_current(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $record = $this->store($employee, $admin, $this->validSteps('Tersimpan'), null);
        $legacyId = (string) str()->uuid();
        LeaveUsageRecord::query()->forceCreate([
            'id' => $legacyId,
            'employee_id' => $employee->id,
            'leave_type_id' => $this->leaveType()->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'usage_year' => 2026,
            'effective_date' => '2026-08-18',
            'start_date' => '2026-08-18',
            'end_date' => '2026-08-18',
            'workdays' => 1,
            'administrative_note' => 'Fixture legacy sebelum revisi.',
            'record_status' => LeaveUsageRecord::STATUS_CANCELLED,
            'correction_reason' => 'Status legacy tetap terbaca.',
            'recorded_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'tab' => 'manual',
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'record_status' => '',
        ]));

        $response->assertOk()
            ->assertSee('Atasan Langsung:', false)
            ->assertSee('Kepala Bagian Tersimpan')
            ->assertSee('PYBMC Tersimpan')
            ->assertSee('Rangkaian persetujuan belum tersedia pada data sebelum revisi.')
            ->assertViewHas('usageRows', function ($rows) use ($record, $legacyId): bool {
                $stored = collect($rows->items())->firstWhere('id', $record->id);
                $legacy = collect($rows->items())->firstWhere('id', $legacyId);

                return $stored instanceof LeaveUsageRecord
                    && $stored->relationLoaded('externalApprovalSteps')
                    && $stored->externalApprovalSteps->count() === 2
                    && $legacy instanceof LeaveUsageRecord
                    && $legacy->relationLoaded('externalApprovalSteps')
                    && $legacy->externalApprovalSteps->isEmpty();
            });
    }

    private function store(
        Employee $employee,
        User $admin,
        array $steps,
        ?UploadedFile $document,
    ): LeaveUsageRecord {
        return app(StoreManualLeaveUsageAction::class)->execute(
            $employee->id,
            $this->validData($steps),
            $document,
            $admin,
            $this->requestFor($admin),
        );
    }

    /** @return array<string, mixed> */
    private function validData(array $steps): array
    {
        return [
            'leave_type_id' => $this->leaveType()->id,
            'leave_request_case_id' => null,
            'tanggal_mulai' => '2026-08-19',
            'tanggal_selesai' => '2026-08-20',
            'alasan' => 'Fakta cuti external yang telah disetujui.',
            'approval_document_number' => 'SURAT/2026/001',
            'approval_steps' => $steps,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function validSteps(string $suffix = ''): array
    {
        $suffix = trim($suffix);

        return [
            [
                'step_type' => 'kepala_bagian',
                'approver_source' => 'external_official',
                'approver_employee_id' => null,
                'approver_name' => trim("Kepala Bagian {$suffix}"),
                'approver_position' => 'Kepala Bagian',
                'approver_institution' => 'Instansi External',
                'acted_on' => '2026-08-17',
                'decision_note' => null,
            ],
            [
                'step_type' => 'pybmc',
                'approver_source' => 'external_official',
                'approver_employee_id' => null,
                'approver_name' => trim("PYBMC {$suffix}"),
                'approver_position' => 'PYBMC',
                'approver_institution' => 'Instansi External',
                'acted_on' => '2026-08-18',
                'decision_note' => 'Disetujui di luar SIMPEG.',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function internalSteps(Employee $kepalaBagian, Employee $pybmc): array
    {
        return [
            [
                'step_type' => 'kepala_bagian',
                'approver_source' => 'simpeg_employee',
                'approver_employee_id' => $kepalaBagian->id,
                'approver_name' => null,
                'approver_position' => null,
                'approver_institution' => null,
                'acted_on' => '2026-08-17',
                'decision_note' => null,
            ],
            [
                'step_type' => 'pybmc',
                'approver_source' => 'simpeg_employee',
                'approver_employee_id' => $pybmc->id,
                'approver_name' => null,
                'approver_position' => null,
                'approver_institution' => null,
                'acted_on' => '2026-08-18',
                'decision_note' => 'Disetujui di luar SIMPEG.',
            ],
        ];
    }

    /** @return array<string, int> */
    private function approvalChannelCounts(Employee $employee): array
    {
        $requestIds = DB::table('leave_requests')->where('employee_id', $employee->id)->pluck('id');

        return [
            'requests' => $requestIds->count(),
            'steps' => DB::table('leave_request_steps')->whereIn('leave_request_id', $requestIds)->count(),
            'reservations' => DB::table('leave_balance_reservation_events')->where('employee_id', $employee->id)->count(),
            'notifications' => Schema::hasTable('notifications')
                ? DB::table('notifications')->where('user_id', $employee->id)->count()
                : 0,
            'jobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0,
            'approval_audits' => DB::table('audit_logs')
                ->whereIn('auditable_type', ['LeaveRequest', 'LeaveRequestStep'])
                ->count(),
        ];
    }

    private function assertLifecycleTablesEmpty(): void
    {
        $this->assertDatabaseCount('leave_usage_records', 0);
        $this->assertDatabaseCount('leave_usage_external_approval_steps', 0);
        $this->assertDatabaseCount('leave_usage_documents', 0);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function leaveType(): RefJenisCuti
    {
        return RefJenisCuti::query()->firstOrCreate(
            ['code' => 'snapshot-lifecycle'],
            ['nama' => 'Cuti Snapshot Lifecycle', 'mengurangi_saldo_tahunan' => false, 'khusus_pns' => false],
        );
    }

    private function requestFor(User $actor): Request
    {
        $request = Request::create('/cuti/pemakaian-manual', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }
}
