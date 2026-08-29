<?php

namespace Tests\Feature;

use App\Actions\Employees\ChangeEmployeeStatusAction;
use App\Http\Requests\Employee\DeactivateEmployeeRequest;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\Permission;
use App\Models\RefStatusPegawai;
use App\Models\Role;
use App\Models\SimpegNotification;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery\Expectation;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

class ChangeEmployeeStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);

    }

    public function test_super_admin_can_change_employee_status_without_attachment(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $supervisor = Employee::factory()->create();
        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisor->id,
            'tanggal_mulai' => '2026-01-10',
            'tanggal_berakhir' => null,
        ]);
        $mutasi = RefStatusPegawai::where('nama', 'Mutasi')->firstOrFail();

        $response = $this->actingAs($admin)->postWithCsrf(route('pegawai.status.update'), [
            'pegawai_id' => $employee->id,
            'status_pegawai_id' => $mutasi->id,
            'tanggal' => '2026-08-01',
            'keterangan' => 'Pindah unit kerja',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionHas('employee_data_changed', true);

        $employee->refresh();
        $this->assertSame($mutasi->id, $employee->status_pegawai_id);
        $this->assertSame('Mutasi', $employee->status_aktif);
        $this->assertSame('Pindah unit kerja', $employee->status_keterangan);
        $this->assertSame('2026-08-01', $employee->status_tanggal?->toDateString());
        $this->assertNull($employee->status_berkas_path);

        $this->assertDatabaseHas('employee_status_histories', [
            'employee_id' => $employee->id,
            'status_pegawai_id' => $mutasi->id,
            'tanggal_efektif' => '2026-08-01 00:00:00',
            'is_latest' => true,
        ]);
        $this->assertSame(1, EmployeeStatusHistory::query()->where('employee_id', $employee->id)->count());

        // Perubahan status tidak boleh menggeser timeline Kepala Bagian.
        $this->assertDatabaseHas('supervisor_assignments', [
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisor->id,
            'tanggal_mulai' => '2026-01-10 00:00:00',
            'tanggal_berakhir' => null,
        ]);

        // Tanpa berkas, tidak ada dokumen yang tercipta.
        $this->assertDatabaseCount('documents', 0);

        // Pegawai bersangkutan menerima notifikasi in-app.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'status_pegawai.dinonaktifkan',
        ]);
        $this->assertSame(DeactivateEmployeeRequest::DEFAULT_NOTE, $employee->status_note);
    }

    public function test_change_status_notification_failure_tidak_membocorkan_payload_ke_log(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();
        $status = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $sentinel = 'ALASAN-RAHASIA-SQL bindings [change@example.test]';

        $this->mock(NotificationService::class, function (MockInterface $mock) use ($sentinel): void {
            /** @var Expectation $expectation */
            $expectation = $mock->shouldReceive('createForEmployee');
            $expectation->once()->andThrow(new RuntimeException($sentinel));
        });
        /** @var MockInterface&LoggerInterface $logSpy */
        $logSpy = Log::spy();

        $this->actingAs($admin)->postWithCsrf(route('pegawai.status.update'), [
            'pegawai_id' => $employee->id,
            'status_pegawai_id' => $status->id,
            'tanggal' => now()->toDateString(),
            'keterangan' => 'Penugasan belajar aktif.',
        ])->assertRedirect();

        $this->assertSame($status->id, $employee->refresh()->status_pegawai_id);
        /** @var Expectation $logExpectation */
        $logExpectation = $logSpy->shouldHaveReceived('error');
        $logExpectation->once()
            ->with(
                'Notification failed after employee status lifecycle',
                \Mockery::on(function (array $context) use ($employee, $sentinel): bool {
                    $keys = array_keys($context);
                    sort($keys);

                    return ($context['employee_id'] ?? null) === $employee->id
                        && ($context['event'] ?? null) === 'status_pegawai.diubah'
                        && ($context['error_type'] ?? null) === RuntimeException::class
                        && $keys === ['employee_id', 'error_type', 'event']
                        && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $sentinel);
                }),
            );
    }

    public function test_change_status_with_attachment_creates_single_document(): void
    {
        Storage::fake('employee_documents');
        Storage::fake('public');
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $pensiun = RefStatusPegawai::where('nama', 'Pensiun')->firstOrFail();

        $response = $this->actingAs($admin)->postWithCsrf(route('pegawai.status.update'), [
            'pegawai_id' => $employee->id,
            'status_pegawai_id' => $pensiun->id,
            'tanggal' => '2026-08-01',
            'keterangan' => 'Batas usia pensiun',
            'berkas' => UploadedFile::fake()->create('sk-pensiun.pdf', 200, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $employee->refresh();
        $this->assertSame('Pensiun', $employee->status_aktif);
        $this->assertNotNull($employee->status_berkas_path);
        $this->assertNotNull($employee->status_nomor_berkas);

        $document = Document::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('sk_status_pegawai', $document->jenis_dokumen);
        $this->assertSame($employee->status_berkas_path, $document->file_path);
        Storage::disk(Document::STORAGE_DISK)->assertExists($document->file_path);
        Storage::disk('public')->assertMissing($document->file_path);
    }

    public function test_change_status_membersihkan_file_privat_ketika_transaksi_gagal(): void
    {
        Storage::fake('employee_documents');
        Storage::fake('public');
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $pensiun = RefStatusPegawai::where('nama', 'Pensiun')->firstOrFail();

        Document::creating(function (): void {
            throw new RuntimeException('Paksa rollback setelah file tersimpan.');
        });

        try {
            $this->actingAs($admin);
            app(ChangeEmployeeStatusAction::class)->execute(
                $employee,
                [
                    'status_pegawai_id' => $pensiun->id,
                    'tanggal' => '2026-08-01',
                    'keterangan' => 'Rollback marker',
                ],
                request(),
                UploadedFile::fake()->create('sk-pensiun.pdf', 200, 'application/pdf'),
            );
            $this->fail('Transaksi seharusnya gagal.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Paksa rollback setelah file tersimpan.', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('employee_documents')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('employee_status_histories', 0);
    }

    public function test_second_change_with_new_attachment_creates_second_document_and_preserves_old_file(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $mutasi = RefStatusPegawai::where('nama', 'Mutasi')->firstOrFail();
        $pensiun = RefStatusPegawai::where('nama', 'Pensiun')->firstOrFail();

        $this->actingAs($admin)->postWithCsrf(route('pegawai.status.update'), [
            'pegawai_id' => $employee->id,
            'status_pegawai_id' => $mutasi->id,
            'tanggal' => '2026-08-01',
            'keterangan' => 'Keterangan pertama',
            'berkas' => UploadedFile::fake()->create('sk-mutasi.pdf', 200, 'application/pdf'),
        ])->assertRedirect();

        $employee->refresh();
        $oldFilePath = $employee->status_berkas_path;
        $this->assertNotNull($oldFilePath);
        Storage::disk(Document::STORAGE_DISK)->assertExists($oldFilePath);
        $this->assertSame(1, Document::where('employee_id', $employee->id)->count());

        $this->actingAs($admin)->postWithCsrf(route('pegawai.status.update'), [
            'pegawai_id' => $employee->id,
            'status_pegawai_id' => $pensiun->id,
            'tanggal' => '2026-08-10',
            'keterangan' => 'Keterangan kedua',
            'berkas' => UploadedFile::fake()->create('sk-pensiun.pdf', 200, 'application/pdf'),
        ])->assertRedirect();

        $employee->refresh();
        $this->assertSame('Pensiun', $employee->status_aktif);
        $this->assertSame('Keterangan kedua', $employee->status_keterangan);

        // Sistem history: kedua dokumen tetap ada (append-only)
        $this->assertSame(2, Document::where('employee_id', $employee->id)->count());
        $newFilePath = $employee->status_berkas_path;
        $this->assertNotSame($oldFilePath, $newFilePath);

        // Kedua file tetap ada di storage
        Storage::disk(Document::STORAGE_DISK)->assertExists($oldFilePath);
        Storage::disk(Document::STORAGE_DISK)->assertExists($newFilePath);
    }

    public function test_change_without_new_attachment_preserves_previous_document_and_file(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $mutasi = RefStatusPegawai::where('nama', 'Mutasi')->firstOrFail();
        $pensiun = RefStatusPegawai::where('nama', 'Pensiun')->firstOrFail();

        $this->actingAs($admin)->postWithCsrf(route('pegawai.status.update'), [
            'pegawai_id' => $employee->id,
            'status_pegawai_id' => $mutasi->id,
            'tanggal' => '2026-08-01',
            'keterangan' => 'Keterangan pertama',
            'berkas' => UploadedFile::fake()->create('sk-mutasi.pdf', 200, 'application/pdf'),
        ])->assertRedirect();

        $employee->refresh();
        $oldFilePath = $employee->status_berkas_path;
        $this->assertNotNull($oldFilePath);

        $this->actingAs($admin)->postWithCsrf(route('pegawai.status.update'), [
            'pegawai_id' => $employee->id,
            'status_pegawai_id' => $pensiun->id,
            'tanggal' => '2026-08-10',
            'keterangan' => 'Keterangan kedua tanpa berkas',
        ])->assertRedirect();

        $employee->refresh();

        // Employee snapshot tidak punya berkas (karena status change kedua tanpa file)
        $this->assertNull($employee->status_berkas_path);
        $this->assertNull($employee->status_nomor_berkas);

        // Tapi dokumen pertama tetap ada di history (append-only)
        $this->assertSame(1, Document::where('employee_id', $employee->id)->count());
        Storage::disk(Document::STORAGE_DISK)->assertExists($oldFilePath);
    }

    public function test_can_change_status_to_values_beyond_original_enum(): void
    {
        // Kolom employees.status_aktif awalnya dibuat sebagai enum Postgres terbatas
        // (Aktif, Non-Aktif, Pensiun, Mutasi). Status di luar keempat nilai itu
        // (mis. "Pemberhentian Sementara") harus tetap bisa disimpan sejak kolom
        // diubah menjadi string bebas.
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $pemberhentian = RefStatusPegawai::where('nama', 'Pemberhentian Sementara')->firstOrFail();

        $response = $this->actingAs($admin)->postWithCsrf(route('pegawai.status.update'), [
            'pegawai_id' => $employee->id,
            'status_pegawai_id' => $pemberhentian->id,
            'tanggal' => '2026-08-01',
            'keterangan' => 'Diberhentikan sementara dari tugas',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');

        $this->assertSame('Pemberhentian Sementara', $employee->refresh()->status_aktif);
    }

    public function test_admin_kepegawaian_can_change_employee_status(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $status = RefStatusPegawai::query()->where('kode', 'MUTASI')->firstOrFail();

        $this->actingAs($user)
            ->get(route('data-pegawai'))
            ->assertOk()
            ->assertSee('data-testid="change-status-trigger"', false);

        $this->actingAs($user)
            ->postWithCsrf(route('pegawai.status.update'), [
                'pegawai_id' => $employee->id,
                'status_pegawai_id' => $status->id,
                'tanggal' => '2026-08-01',
                'keterangan' => 'Penyesuaian status administratif.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_pimpinan_cannot_change_employee_status(): void
    {
        $user = User::factory()->pimpinan()->create();
        $employee = Employee::factory()->create();
        $status = RefStatusPegawai::query()->where('is_active', true)->firstOrFail();

        $this->actingAs($user)
            ->postWithCsrf(route('pegawai.status.update'), [
                'pegawai_id' => $employee->id,
                'status_pegawai_id' => $status->id,
                'tanggal' => '2026-08-01',
            ])
            ->assertForbidden();
    }

    public function test_admin_kepegawaian_without_employees_update_permission_cannot_change_employee_status(): void
    {
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.update')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);

        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $status = RefStatusPegawai::query()->where('is_active', true)->firstOrFail();

        $this->actingAs($user)
            ->get(route('data-pegawai'))
            ->assertOk()
            ->assertDontSee('data-testid="change-status-trigger"', false);

        $this->actingAs($user)
            ->postWithCsrf(route('pegawai.status.update'), [
                'pegawai_id' => $employee->id,
                'status_pegawai_id' => $status->id,
                'tanggal' => '2026-08-01',
            ])
            ->assertForbidden();
    }

    public function test_legacy_status_pegawai_post_route_still_accepts_submission(): void
    {
        $employee = Employee::factory()->create();
        $status = RefStatusPegawai::query()->where('kode', 'MUTASI')->firstOrFail();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postWithCsrf(route('super-admin.status-pegawai.store'), [
                'pegawai_id' => $employee->id,
                'status_pegawai_id' => $status->id,
                'tanggal' => '2026-08-01',
                'keterangan' => 'Pemeliharaan data status pegawai.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_admin_kepegawaian_dapat_mengaktifkan_kembali_lewat_jalur_generik_ber_permission(): void
    {
        // K-STATUS-04: transisi Nonaktif → Aktif boleh oleh role EFEKTIF yang memegang
        // employees.restore — Admin Kepegawaian sekarang menerima permission tsb.
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'status_aktif' => 'Non-Aktif',
            'status_note' => 'Pesan nonaktif lama yang wajib dibersihkan.',
        ]);
        $aktif = RefStatusPegawai::where('kode', 'AKTIF')->firstOrFail();

        $this->assertFalse($employee->refresh()->isActive());
        $this->assertTrue($user->hasPermission('employees.restore'));

        $response = $this->actingAs($user)
            ->postWithCsrf(route('pegawai.status.update'), [
                'pegawai_id' => $employee->id,
                'status_pegawai_id' => $aktif->id,
                'tanggal' => '2026-08-10',
                'keterangan' => 'Percobaan mengaktifkan kembali.',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertTrue($employee->refresh()->isActive());
        $this->assertNull($employee->status_note);
        $this->assertDatabaseHas('employee_status_histories', [
            'employee_id' => $employee->id,
            'status_pegawai_id' => $aktif->id,
            'is_latest' => true,
        ]);
        $this->assertSame(1, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'status_pegawai.diubah')
            ->count());
        $this->assertSame(0, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'status_pegawai.dinonaktifkan')
            ->count());
    }

    public function test_super_admin_dapat_mengaktifkan_kembali_lewat_jalur_generik(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Non-Aktif']);
        $aktif = RefStatusPegawai::where('kode', 'AKTIF')->firstOrFail();

        $response = $this->actingAs($admin)
            ->postWithCsrf(route('pegawai.status.update'), [
                'pegawai_id' => $employee->id,
                'status_pegawai_id' => $aktif->id,
                'tanggal' => '2026-08-10',
                'keterangan' => 'Aktif kembali setelah sanksi selesai.',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertTrue($employee->refresh()->isActive());
        $this->assertDatabaseHas('employee_status_histories', [
            'employee_id' => $employee->id,
            'status_pegawai_id' => $aktif->id,
            'is_latest' => true,
        ]);
    }

    public function test_tanggal_efektif_masa_depan_dijadwalkan_pada_jalur_generik(): void
    {
        // K-STATUS-06: tanggal perubahan lifecycle masa depan diizinkan dan disimpan
        // sebagai transisi terjadwal; snapshot tetap aktif sampai jatuh tempo.
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $mutasi = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();
        $effectiveDate = now('Asia/Makassar')->addDay()->toDateString();

        $response = $this->actingAs($admin)
            ->postWithCsrf(route('pegawai.status.update'), [
                'pegawai_id' => $employee->id,
                'status_pegawai_id' => $mutasi->id,
                'tanggal' => $effectiveDate,
                'keterangan' => 'Mutasi ke instansi lain.',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas(
            'success',
            'Perubahan status pegawai '.$employee->nama_lengkap.' berhasil dijadwalkan untuk tanggal '.
                $effectiveDate.'.',
        );
        $this->assertTrue($employee->refresh()->isActive());
        $this->assertDatabaseHas('employee_status_transitions', [
            'employee_id' => $employee->id,
            'status_pegawai_id' => $mutasi->id,
            'kind' => 'status',
            'is_applied' => false,
        ]);
        $this->assertDatabaseMissing('employee_status_histories', [
            'employee_id' => $employee->id,
            'status_pegawai_id' => $mutasi->id,
        ]);
    }

    public function test_super_admin_dalam_simulasi_admin_kepegawaian_bisa_reaktivasi(): void
    {
        // K-MTG-03: seluruh endpoint wajib mengevaluasi role EFEKTIF. Super Admin yang
        // simulasi sebagai Admin Kepegawaian dievaluasi sebagai Admin Kepegawaian;
        // K-STATUS-04 memberikan employees.restore ke role tersebut, jadi reaktivasi
        // SAH selama role efektif memegang permission — bukan dibypass oleh role asli
        // maupun ditolak karena evaluasi salah lapis.
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Non-Aktif']);
        $aktif = RefStatusPegawai::where('kode', 'AKTIF')->firstOrFail();

        $admin->temporary_role = 'admin_kepegawaian';
        $admin->save();

        $this->assertSame('admin_kepegawaian', $admin->getEffectiveRole());
        $this->assertTrue($admin->hasPermission('employees.restore'));

        $response = $this->actingAs($admin)
            ->postWithCsrf(route('pegawai.status.update'), [
                'pegawai_id' => $employee->id,
                'status_pegawai_id' => $aktif->id,
                'tanggal' => '2026-08-10',
                'keterangan' => 'Reaktivasi saat simulasi (role efektif ber-permission).',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertTrue($employee->refresh()->isActive());
        $this->assertDatabaseHas('employee_status_histories', [
            'employee_id' => $employee->id,
            'status_pegawai_id' => $aktif->id,
            'is_latest' => true,
        ]);
    }

    public function test_permintaan_status_identik_tidak_membuat_duplikat_history(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $mutasi = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();

        $payload = [
            'pegawai_id' => $employee->id,
            'status_pegawai_id' => $mutasi->id,
            'tanggal' => '2026-08-10',
            'keterangan' => 'Mutasi ke instansi lain.',
        ];

        $this->actingAs($admin)->postWithCsrf(route('pegawai.status.update'), $payload)->assertRedirect();

        $this->assertSame(1, EmployeeStatusHistory::query()
            ->where('employee_id', $employee->id)
            ->where('status_pegawai_id', $mutasi->id)
            ->count());

        $payload['tanggal'] = '2026-08-11';
        $payload['keterangan'] = 'Retry dengan tanggal dan alasan berbeda.';

        // Target status yang sama tetap no-op walaupun tanggal/alasan berbeda.
        $this->actingAs($admin)
            ->postWithCsrf(route('pegawai.status.update'), $payload)
            ->assertSessionHas('error', 'Gagal memperbarui status pegawai: Pegawai sudah berstatus Mutasi pada tanggal 2026-08-11.');

        $this->assertSame(1, EmployeeStatusHistory::query()
            ->where('employee_id', $employee->id)
            ->where('status_pegawai_id', $mutasi->id)
            ->count());
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_type', 'Employee')
            ->where('auditable_id', $employee->id)
            ->count());
        $this->assertSame(1, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'status_pegawai.dinonaktifkan')
            ->count());
        $this->assertSame('2026-08-10', $employee->refresh()->status_tanggal?->toDateString());
    }

    public function test_kegagalan_menulis_audit_membatalkan_perubahan_status(): void
    {
        // US-2.9/2.10 + Issue #22: audit perubahan status bersifat fail-closed.
        // Kegagalan menulis jejak (mis. DB tidak sehat) membatalkan transaksi —
        // snapshot, history, dan berkas tidak boleh disimpan tanpa jejak audit.
        Storage::fake('employee_documents');
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $mutasi = RefStatusPegawai::where('kode', 'MUTASI')->firstOrFail();

        AuditLog::creating(function (): void {
            throw new RuntimeException('Paksa gagal menulis audit.');
        });

        $response = $this->actingAs($admin)
            ->postWithCsrf(route('pegawai.status.update'), [
                'pegawai_id' => $employee->id,
                'status_pegawai_id' => $mutasi->id,
                'tanggal' => '2026-08-10',
                'keterangan' => 'Mutasi dengan audit gagal.',
                'berkas' => UploadedFile::fake()->create('sk-mutasi.pdf', 200, 'application/pdf'),
            ]);

        $response->assertSessionHas('error');
        $this->assertTrue($employee->refresh()->isActive());
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame([], Storage::disk('employee_documents')->allFiles());
    }

    public function test_status_form_is_rendered_in_employee_table_and_old_page_redirects(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('data-pegawai'))
            ->assertOk()
            ->assertSee('data-testid="change-status-trigger"', false)
            ->assertSee('Ubah Status Pegawai', false)
            ->assertSee('Tanggal Efektif Status Kepegawaian', false)
            ->assertSee(route('pegawai.status.update'), false)
            ->assertDontSee('super-admin.status-pegawai.index', false);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/super-admin/status-pegawai')
            ->assertRedirect(route('data-pegawai'));
    }

    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
