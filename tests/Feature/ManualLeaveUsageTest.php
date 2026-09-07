<?php

namespace Tests\Feature;

use App\Actions\Cuti\CancelManualLeaveUsageAction;
use App\Actions\Cuti\CorrectManualLeaveUsageAction;
use App\Actions\Cuti\ResubmitLeaveRequestAction;
use App\Actions\Cuti\StoreManualLeaveUsageAction;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestCase;
use App\Models\LeaveUsageDocument;
use App\Models\LeaveUsageExternalApprovalStep;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\LeaveUsageRecord;
use App\Models\Permission;
use App\Models\RefHariLibur;
use App\Models\RefJenisCuti;
use App\Models\Role;
use App\Models\StorageRecoveryTask;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\Cuti\LeaveEligibilityService;
use App\Services\Cuti\LeaveUsageReconciliationService;
use App\Services\Cuti\LeaveUsageRecordService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManualLeaveUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-18 09:00:00');
        $this->seed(RbacSeeder::class);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_route_manual_hanya_menerima_role_berhak_dengan_permission(): void
    {
        $employee = Employee::factory()->create();

        $this->post($this->storeUrl($employee), $this->validPayload())
            ->assertRedirect(route('login'));

        foreach (['pimpinan', 'kepala_bagian', 'pegawai'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->post($this->storeUrl($employee), $this->validPayload())
                ->assertForbidden();
        }

        $adminRole = Role::query()->where('name', 'admin_kepegawaian')->firstOrFail();
        $permission = Permission::query()->where('name', 'cuti.manual.manage')->firstOrFail();
        $adminRole->permissions()->detach($permission);
        $adminWithoutPermission = User::factory()->adminKepegawaian()->create();

        $this->actingAs($adminWithoutPermission)
            ->post($this->storeUrl($employee), $this->validPayload())
            ->assertForbidden();

        $adminRole->permissions()->attach($permission);
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($admin)
            ->post($this->storeUrl($employee), $this->validPayload())
            ->assertRedirect();

        Role::query()->where('name', 'pimpinan')->firstOrFail()->permissions()->attach($permission);
        $this->actingAs(User::factory()->pimpinan()->create())
            ->post($this->storeUrl($employee), $this->validPayload())
            ->assertRedirect();

        $this->assertDatabaseHas('leave_usage_records', [
            'employee_id' => $employee->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
        ]);
    }

    public function test_action_langsung_mempertahankan_role_dan_permission_gate(): void
    {
        $employee = Employee::factory()->create();
        $current = $this->rawManualFact($employee, User::factory()->adminKepegawaian()->create());

        foreach (['pimpinan', 'kepala_bagian', 'pegawai'] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $this->assertAllManualActionsReject($employee, $current, $actor, $role);
        }

        $role = Role::query()->where('name', 'admin_kepegawaian')->firstOrFail();
        $permission = Permission::query()->where('name', 'cuti.manual.manage')->firstOrFail();
        $role->permissions()->detach($permission);
        $actor = User::factory()->adminKepegawaian()->create();
        $this->assertAllManualActionsReject($employee, $current, $actor, 'admin tanpa permission');
        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $current->fresh()->record_status);
        $this->assertDatabaseCount('leave_usage_records', 1);
    }

    public function test_route_mutasi_mengotorisasi_sebelum_lookup_uuid_yang_tidak_ada(): void
    {
        $unknown = (string) Str::uuid();
        $existingEmployee = Employee::factory()->create();
        $fixtureActor = User::factory()->adminKepegawaian()->create();
        $existingUsage = $this->rawManualFact($existingEmployee, $fixtureActor);
        $requests = [
            [
                "/cuti/pemakaian-manual/{$unknown}",
                "/cuti/pemakaian-manual/{$existingEmployee->id}",
                fn (): array => $this->validPayload(),
            ],
            [
                "/cuti/pemakaian-manual/{$unknown}/koreksi",
                "/cuti/pemakaian-manual/{$existingUsage->id}/koreksi",
                fn (): array => array_merge($this->validPayload(), [
                    'correction_reason' => 'Koreksi record untuk uji urutan otorisasi.',
                ]),
            ],
            [
                "/cuti/pemakaian-manual/{$unknown}/batalkan",
                "/cuti/pemakaian-manual/{$existingUsage->id}/batalkan",
                fn (): array => [
                    'correction_reason' => 'Pembatalan record untuk uji urutan otorisasi.',
                    'dokumen' => $this->validDocument('auth-order-cancel.pdf'),
                ],
            ],
        ];

        foreach ($requests as [$unknownUrl, $existingUrl, $payload]) {
            $unknownResponse = $this->post($unknownUrl, $payload());
            $existingResponse = $this->post($existingUrl, $payload());
            $this->assertSame($existingResponse->getStatusCode(), $unknownResponse->getStatusCode());
            $unknownResponse->assertRedirect(route('login'));
            $existingResponse->assertRedirect(route('login'));
        }

        $pimpinan = User::factory()->pimpinan()->create();
        foreach ($requests as [$unknownUrl, $existingUrl, $payload]) {
            $unknownResponse = $this->actingAs($pimpinan)->post($unknownUrl, $payload());
            $existingResponse = $this->actingAs($pimpinan)->post($existingUrl, $payload());
            $this->assertSame($existingResponse->getStatusCode(), $unknownResponse->getStatusCode());
            $unknownResponse->assertForbidden();
            $existingResponse->assertForbidden();
        }

        $role = Role::query()->where('name', 'admin_kepegawaian')->firstOrFail();
        $permission = Permission::query()->where('name', 'cuti.manual.manage')->firstOrFail();
        $role->permissions()->detach($permission);
        $adminWithoutPermission = User::factory()->adminKepegawaian()->create();
        foreach ($requests as [$unknownUrl, $existingUrl, $payload]) {
            $unknownResponse = $this->actingAs($adminWithoutPermission)->post($unknownUrl, $payload());
            $existingResponse = $this->actingAs($adminWithoutPermission)->post($existingUrl, $payload());
            $this->assertSame($existingResponse->getStatusCode(), $unknownResponse->getStatusCode());
            $unknownResponse->assertForbidden();
            $existingResponse->assertForbidden();
        }

        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $existingUsage->fresh()->record_status);
        $this->assertDatabaseCount('leave_usage_records', 1);
    }

    public function test_form_mewajibkan_seluruh_field_dan_employee_uuid_valid(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($admin)
            ->post($this->storeUrl($employee), [])
            ->assertSessionHasErrors([
                'leave_type_id',
                'tanggal_mulai',
                'tanggal_selesai',
                'alasan',
                'approval_steps',
            ]);

        $this->actingAs($admin)
            ->post('/cuti/pemakaian-manual/bukan-uuid', $this->validPayload())
            ->assertNotFound();
    }

    public function test_malformed_uuid_field_route_dan_action_store_ditolak_sebagai_validasi_tanpa_file(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        foreach (['leave_type_id', 'leave_request_case_id'] as $field) {
            $this->actingAs($admin)
                ->post($this->storeUrl($employee), $this->validPayload([$field => 'bukan-uuid']))
                ->assertSessionHasErrors($field);

            try {
                app(StoreManualLeaveUsageAction::class)->execute(
                    $employee->id,
                    $this->validData([$field => 'bukan-uuid']),
                    $this->validDocument("store-{$field}.pdf"),
                    $admin,
                    $this->requestFor($admin),
                );
                $this->fail("UUID malformed {$field} seharusnya ditolak Action store.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }

        $this->assertDatabaseCount('leave_usage_records', 0);
        $this->assertSame([], Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX));
    }

    public function test_malformed_uuid_field_action_koreksi_ditolak_sebelum_file_dan_mutasi(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $current = $this->rawManualFact($employee, $admin);

        foreach (['leave_type_id', 'leave_request_case_id'] as $field) {
            try {
                app(CorrectManualLeaveUsageAction::class)->execute(
                    $current->id,
                    array_merge($this->validData([$field => 'bukan-uuid']), [
                        'correction_reason' => 'Koreksi UUID malformed.',
                    ]),
                    $this->validDocument("correct-{$field}.pdf"),
                    $admin,
                    $this->requestFor($admin),
                );
                $this->fail("UUID malformed {$field} seharusnya ditolak Action koreksi.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }

        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $current->fresh()->record_status);
        $this->assertDatabaseCount('leave_usage_records', 1);
        $this->assertDatabaseCount('leave_usage_documents', 0);
        $this->assertSame([], Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX));
    }

    public function test_schema_dokumen_memiliki_uuid_xor_fk_restrict_index_unique_dan_tanpa_url_publik(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $record = $this->rawManualFact($employee, $admin);
        $set = LeaveUsageReconciliationSet::query()->create([
            'employee_id' => $employee->id,
            'balance_year' => 2026,
            'reconciled_at' => '2026-08-18',
            'status' => 'active',
            'administrative_note' => 'Fixture target dokumen rekonsiliasi.',
            'recorded_by' => $admin->id,
        ]);
        $base = [
            'id' => (string) Str::uuid(),
            'leave_usage_record_id' => $record->id,
            'leave_usage_reconciliation_set_id' => null,
            'original_name' => 'bukti.pdf',
            'stored_name' => Str::uuid().'.pdf',
            'path' => 'cuti/pemakaian/'.$employee->id.'/'.Str::uuid().'.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 20,
            'uploaded_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('leave_usage_documents')->insert($base);
        $this->assertTrue(Str::isUuid((string) DB::table('leave_usage_documents')->value('id')));
        $this->assertFalse(Schema::hasColumn('leave_usage_documents', 'public_url'));

        foreach ([
            ['leave_usage_record_id' => null],
            ['leave_usage_reconciliation_set_id' => $set->id],
        ] as $invalidTargets) {
            try {
                DB::transaction(fn (): bool => DB::table('leave_usage_documents')->insert(array_merge(
                    $base,
                    $invalidTargets,
                    [
                        'id' => (string) Str::uuid(),
                        'stored_name' => Str::uuid().'.pdf',
                        'path' => 'cuti/pemakaian/'.$employee->id.'/'.Str::uuid().'.pdf',
                    ],
                )));
                $this->fail('Target dokumen harus memenuhi XOR tepat satu FK.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('leave_usage_document_target_check', $exception->getMessage());
            }
        }

        try {
            DB::transaction(fn (): bool => DB::table('leave_usage_documents')->insert(array_merge($base, [
                'id' => (string) Str::uuid(),
            ])));
            $this->fail('Disk dan path dokumen harus unik.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('leave_usage_documents_disk_path_unique', $exception->getMessage());
        }

        $indexes = DB::table('pg_indexes')
            ->where('tablename', 'leave_usage_documents')
            ->pluck('indexname');
        $this->assertContains('leave_usage_documents_usage_record_index', $indexes);
        $this->assertContains('leave_usage_documents_reconciliation_set_index', $indexes);

        $foreignKeys = collect(DB::select(<<<'SQL'
SELECT conname, confdeltype
FROM pg_constraint
WHERE conrelid = 'leave_usage_documents'::regclass AND contype = 'f'
SQL))->pluck('confdeltype', 'conname');
        $this->assertSame('r', $foreignKeys['leave_usage_documents_leave_usage_record_id_foreign'] ?? null);
        $this->assertSame('r', $foreignKeys['leave_usage_documents_leave_usage_reconciliation_set_id_foreign'] ?? null);
        $this->assertSame('n', $foreignKeys['leave_usage_documents_uploaded_by_foreign'] ?? null);
    }

    public function test_form_menolak_mime_extension_dan_ukuran_dokumen_invalid(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        foreach ([
            UploadedFile::fake()->create('skrip.exe', 10, 'application/x-msdownload'),
            UploadedFile::fake()->create('tipuan.php', 10, 'application/pdf'),
            UploadedFile::fake()->create('tipuan.pdf', 10, 'text/plain'),
            UploadedFile::fake()->create('terlalu-besar.pdf', 10_241, 'application/pdf'),
        ] as $document) {
            $this->actingAs($admin)
                ->post($this->storeUrl($employee), $this->validPayload(['dokumen' => $document]))
                ->assertSessionHasErrors('dokumen');
        }

        try {
            app(StoreManualLeaveUsageAction::class)->execute(
                $employee->id,
                $this->validData(),
                UploadedFile::fake()->create('bypass.php', 10, 'application/pdf'),
                $admin,
                $this->requestFor($admin),
            );
            $this->fail('Action langsung wajib menolak extension dokumen terlarang.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dokumen', $exception->errors());
        }

        $this->assertDatabaseCount('leave_usage_records', 0);
        $this->assertSame([], Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX));
    }

    public function test_doc_dan_docx_diterima_pada_route_dan_action_langsung(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $routeEmployee = Employee::factory()->create();
        $actionEmployee = Employee::factory()->create();

        $this->actingAs($admin)->post($this->storeUrl($routeEmployee), $this->validPayload([
            'dokumen' => UploadedFile::fake()->create('surat.doc', 20, 'application/msword'),
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('leave_usage_documents', [
            'original_name' => 'surat.doc',
            'mime_type' => 'application/msword',
        ]);
        $direct = app(StoreManualLeaveUsageAction::class)->execute(
            $actionEmployee->id,
            $this->validData(),
            UploadedFile::fake()->create(
                'surat.docx',
                20,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ),
            $admin,
            $this->requestFor($admin),
        );

        $this->assertDatabaseHas('leave_usage_documents', [
            'leave_usage_record_id' => $direct->id,
            'original_name' => 'surat.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
        $this->assertSame(2, LeaveUsageDocument::query()->count());
    }

    public function test_action_store_menolak_alasan_yang_hanya_whitespace_sebelum_file_ditulis(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        try {
            $this->storeDirect($employee, $admin, ['alasan' => '   ']);
            $this->fail('Alasan whitespace seharusnya ditolak Action store.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('alasan', $exception->errors());
        }

        $this->assertDatabaseCount('leave_usage_records', 0);
        $this->assertSame([], Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX));
    }

    public function test_action_koreksi_menolak_alasan_yang_hanya_whitespace_sebelum_file_ditulis(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $current = $this->storeDirect($employee, $admin);
        $originalFiles = Storage::disk(LeaveUsageDocument::STORAGE_DISK)
            ->allFiles(LeaveUsageDocument::PATH_PREFIX);

        try {
            app(CorrectManualLeaveUsageAction::class)->execute(
                $current->id,
                array_merge($this->validData(), [
                    'alasan' => '   ',
                    'correction_reason' => 'Alasan koreksi valid.',
                ]),
                $this->validDocument('koreksi-whitespace.pdf'),
                $admin,
                $this->requestFor($admin),
            );
            $this->fail('Alasan whitespace seharusnya ditolak Action koreksi.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('alasan', $exception->errors());
        }

        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $current->fresh()->record_status);
        $this->assertDatabaseCount('leave_usage_records', 1);
        $this->assertSame(
            $originalFiles,
            Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX),
        );
    }

    #[DataProvider('unicodeWhitespaceReasons')]
    public function test_action_mutasi_menolak_alasan_unicode_whitespace_sebelum_file_dan_mutasi(string $blank): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $calls = [
            'store.alasan' => fn () => app(StoreManualLeaveUsageAction::class)->execute(
                $employee->id,
                $this->validData(['alasan' => $blank]),
                $this->validDocument('unicode-store.pdf'),
                $admin,
                $this->requestFor($admin),
            ),
        ];

        foreach ($calls as $label => $call) {
            try {
                $call();
                $this->fail("{$label} unicode whitespace seharusnya ditolak.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('alasan', $exception->errors());
            }
        }

        $current = $this->rawManualFact($employee, $admin);
        $mutationCalls = [
            'correct.alasan' => [
                'alasan',
                fn () => app(CorrectManualLeaveUsageAction::class)->execute(
                    $current->id,
                    array_merge($this->validData(['alasan' => $blank]), [
                        'correction_reason' => 'Alasan koreksi valid.',
                    ]),
                    $this->validDocument('unicode-correct-note.pdf'),
                    $admin,
                    $this->requestFor($admin),
                ),
            ],
            'correct.correction_reason' => [
                'correction_reason',
                fn () => app(CorrectManualLeaveUsageAction::class)->execute(
                    $current->id,
                    array_merge($this->validData(), ['correction_reason' => $blank]),
                    $this->validDocument('unicode-correct-reason.pdf'),
                    $admin,
                    $this->requestFor($admin),
                ),
            ],
            'cancel.correction_reason' => [
                'correction_reason',
                fn () => app(CancelManualLeaveUsageAction::class)->execute(
                    $current->id,
                    $blank,
                    $admin,
                    $this->requestFor($admin),
                ),
            ],
        ];

        foreach ($mutationCalls as $label => [$field, $call]) {
            try {
                $call();
                $this->fail("{$label} unicode whitespace seharusnya ditolak.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }

        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $current->fresh()->record_status);
        $this->assertDatabaseCount('leave_usage_records', 1);
        $this->assertDatabaseCount('leave_usage_documents', 0);
        $this->assertSame([], Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX));
    }

    /** @return iterable<string, array{string}> */
    public static function unicodeWhitespaceReasons(): iterable
    {
        yield 'NBSP' => ["\u{00A0}"];
        yield 'EM SPACE' => ["\u{2003}"];
    }

    public function test_route_koreksi_dan_pembatalan_memakai_post_uuid_dan_permission_gate(): void
    {
        $employee = Employee::factory()->create();
        $fixtureActor = User::factory()->adminKepegawaian()->create();
        $current = $this->rawManualFact($employee, $fixtureActor);
        $correctUrl = "/cuti/pemakaian-manual/{$current->id}/koreksi";
        $cancelUrl = "/cuti/pemakaian-manual/{$current->id}/batalkan";

        $this->post($correctUrl, [])->assertRedirect(route('login'));
        $this->post($cancelUrl, [])->assertRedirect(route('login'));

        $role = Role::query()->where('name', 'admin_kepegawaian')->firstOrFail();
        $permission = Permission::query()->where('name', 'cuti.manual.manage')->firstOrFail();
        $role->permissions()->detach($permission);
        $adminWithoutPermission = User::factory()->adminKepegawaian()->create();
        $this->actingAs($adminWithoutPermission)->post($correctUrl, [])->assertForbidden();
        $this->actingAs($adminWithoutPermission)->post($cancelUrl, [])->assertForbidden();
        $role->permissions()->attach($permission);

        $admin = User::factory()->adminKepegawaian()->create();
        $this->actingAs($admin)->post($correctUrl, [])->assertSessionHasErrors([
            'correction_reason',
            'approval_steps',
        ]);
        $this->actingAs($admin)->post($cancelUrl, [])->assertSessionHasErrors([
            'correction_reason',
        ]);
        $this->actingAs($admin)->post($correctUrl, array_merge($this->validData(), [
            'correction_reason' => 'Koreksi surat eksternal.',
            'dokumen' => UploadedFile::fake()->create('tipuan.php', 20, 'application/pdf'),
        ]))->assertSessionHasErrors('dokumen');

        $this->actingAs($admin)->post($correctUrl, array_merge($this->validData([
            'tanggal_mulai' => '2026-01-08',
            'tanggal_selesai' => '2026-01-09',
        ]), [
            'correction_reason' => 'Koreksi surat eksternal.',
            'dokumen' => $this->validDocument('koreksi.pdf'),
        ]))->assertRedirect();
        $replacement = LeaveUsageRecord::query()->where('replaces_id', $current->id)->sole();

        $this->actingAs($admin)->post("/cuti/pemakaian-manual/{$replacement->id}/batalkan", [
            'correction_reason' => 'Persetujuan eksternal dibatalkan.',
        ])->assertRedirect();
        $this->assertSame(LeaveUsageRecord::STATUS_CANCELLED, $replacement->fresh()->record_status);

        foreach ([
            'cuti.manual.store' => ['employee', 'cuti/pemakaian-manual/{employee}'],
            'cuti.manual.correct' => ['usage', 'cuti/pemakaian-manual/{usage}/koreksi'],
            'cuti.manual.cancel' => ['usage', 'cuti/pemakaian-manual/{usage}/batalkan'],
            'cuti.manual.download' => ['document', 'cuti/pemakaian-manual/{usage}/dokumen/{document}'],
        ] as $name => [$uuidParameter, $uri]) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route {$name} wajib tersedia.");
            $this->assertSame($uri, $route->uri());
            $this->assertNotEmpty($route->wheres[$uuidParameter] ?? null);
        }

        $this->assertSame(['POST'], Route::getRoutes()->getByName('cuti.manual.correct')?->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('cuti.manual.cancel')?->methods());
    }

    public function test_hari_kerja_dihitung_server_dan_nilai_client_diabaikan(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        RefHariLibur::query()->create([
            'tanggal' => '2026-01-07',
            'nama' => 'Libur Nasional Uji',
            'tahun' => 2026,
            'is_cuti_bersama' => false,
        ]);
        RefHariLibur::query()->create([
            'tanggal' => '2026-01-08',
            'nama' => 'Cuti Bersama Uji',
            'tahun' => 2026,
            'is_cuti_bersama' => true,
        ]);

        $payload = $this->validPayload([
            'tanggal_mulai' => '2026-01-05',
            'tanggal_selesai' => '2026-01-11',
            'jumlah_hari_kerja' => 99,
        ]);

        $this->actingAs($admin)->post($this->storeUrl($employee), $payload)->assertRedirect();

        $record = LeaveUsageRecord::query()->sole();
        $this->assertSame(3, $record->workdays);
        $this->assertSame(2026, $record->usage_year);
        $this->assertSame('2026-01-05', $record->effective_date->toDateString());
        $this->assertSame('2026-01-05', $record->start_date->toDateString());
        $this->assertSame('2026-01-11', $record->end_date->toDateString());
        $this->assertSame('Cuti yang disetujui di luar SIMPEG.', $record->administrative_note);
        $this->assertSame($admin->id, $record->recorded_by);
        $this->assertArrayNotHasKey('jumlah_hari_kerja', $record->getAttributes());
    }

    public function test_lintas_tahun_dan_rentang_nol_hari_kerja_ditolak(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($admin)
            ->post($this->storeUrl($employee), $this->validPayload([
                'tanggal_mulai' => '2026-12-31',
                'tanggal_selesai' => '2027-01-01',
            ]))
            ->assertSessionHasErrors('tanggal_selesai');

        $this->actingAs($admin)
            ->post($this->storeUrl($employee), $this->validPayload([
                'tanggal_mulai' => '2026-01-10',
                'tanggal_selesai' => '2026-01-11',
            ]))
            ->assertSessionHasErrors('tanggal_selesai');

        $this->assertDatabaseCount('leave_usage_records', 0);
    }

    public function test_case_hanya_untuk_melahirkan_cltn_dan_memvalidasi_pemilik_serta_jenis(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $other = Employee::factory()->create();
        $melahirkan = $this->leaveType('melahirkan', false);
        $cltn = $this->leaveType('cltn', false);
        $annual = $this->annualType();
        $foreignCase = LeaveRequestCase::query()->create([
            'employee_id' => $other->id,
            'jenis_cuti_id' => $melahirkan->id,
            'created_by' => $admin->id,
        ]);
        $wrongTypeCase = LeaveRequestCase::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $cltn->id,
            'created_by' => $admin->id,
        ]);
        $annualCase = LeaveRequestCase::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $annual->id,
            'created_by' => $admin->id,
        ]);

        foreach ([
            [$melahirkan, $foreignCase],
            [$melahirkan, $wrongTypeCase],
            [$annual, $annualCase],
        ] as [$type, $case]) {
            try {
                $this->storeDirect($employee, $admin, [
                    'leave_type_id' => $type->id,
                    'leave_request_case_id' => $case->id,
                ]);
                $this->fail('Case yang tidak sesuai kontrak seharusnya ditolak.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('leave_request_case_id', $exception->errors());
            }
        }

        $this->assertDatabaseCount('leave_usage_records', 0);
    }

    public function test_batas_case_menggabungkan_request_dan_manual_aktif_dengan_rentang_kalender(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $melahirkan = $this->leaveType('melahirkan', false);
        $case = LeaveRequestCase::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $melahirkan->id,
            'created_by' => $admin->id,
        ]);
        $this->createLeaveRequest($employee, $melahirkan, '2026-11-02', '2026-12-31', 'disetujui', $case);

        $accepted = $this->storeDirect($employee, $admin, [
            'leave_type_id' => $melahirkan->id,
            'leave_request_case_id' => $case->id,
            'tanggal_mulai' => '2027-01-01',
            'tanggal_selesai' => '2027-01-30',
        ]);
        $this->assertSame($case->id, $accepted->leave_request_case_id);

        try {
            $this->storeDirect($employee, $admin, [
                'leave_type_id' => $melahirkan->id,
                'leave_request_case_id' => $case->id,
                'tanggal_mulai' => '2027-02-01',
                'tanggal_selesai' => '2027-02-05',
            ]);
            $this->fail('Akumulasi case di luar tiga bulan kalender seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tanggal_selesai', $exception->errors());
        }
    }

    public function test_koreksi_case_mengecualikan_versi_manual_saat_ini_dari_rentang(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $melahirkan = $this->leaveType('melahirkan', false);
        $case = LeaveRequestCase::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $melahirkan->id,
            'created_by' => $admin->id,
        ]);
        $current = $this->storeDirect($employee, $admin, [
            'leave_type_id' => $melahirkan->id,
            'leave_request_case_id' => $case->id,
            'tanggal_mulai' => '2026-01-05',
            'tanggal_selesai' => '2026-01-30',
        ]);

        $replacement = app(CorrectManualLeaveUsageAction::class)->execute(
            $current->id,
            [
                'leave_type_id' => $melahirkan->id,
                'leave_request_case_id' => $case->id,
                'tanggal_mulai' => '2026-11-02',
                'tanggal_selesai' => '2026-11-30',
                'alasan' => 'Tanggal final eksternal diperbaiki.',
                'correction_reason' => 'Surat koreksi baru diterima.',
                'approval_document_number' => $current->approval_document_number,
                'approval_steps' => $this->persistedApprovalPayload($current),
            ],
            $this->validDocument('koreksi.pdf'),
            $admin,
            $this->requestFor($admin),
        );

        $this->assertSame('2026-11-02', $replacement->start_date->toDateString());
        $this->assertSame($current->id, $replacement->replaces_id);
    }

    public function test_case_dengan_manual_aktif_tanpa_request_tetap_ditemukan_untuk_continuation(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $melahirkan = $this->leaveType('melahirkan', false);
        $case = LeaveRequestCase::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $melahirkan->id,
            'created_by' => $admin->id,
        ]);
        $this->storeDirect($employee, $admin, [
            'leave_type_id' => $melahirkan->id,
            'leave_request_case_id' => $case->id,
        ]);

        $cases = app(LeaveEligibilityService::class)->continuationCasesFor($employee);
        $caseSummary = $cases->firstWhere('id', $case->id);

        $this->assertTrue($cases->contains('id', $case->id));
        $this->assertNotNull($caseSummary);
        $this->assertFalse($caseSummary->relationLoaded('leaveUsageRecords'));
        $this->assertSame('2026-01-05', $caseSummary->getAttribute('manual_period_start'));
        $this->assertSame('2026-01-07', $caseSummary->getAttribute('manual_period_end'));
    }

    public function test_manual_case_pertama_membuat_case_dan_split_berikutnya_memakai_batas_kumulatif_yang_sama(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $melahirkan = $this->leaveType('melahirkan', false);

        $first = $this->storeDirect($employee, $admin, [
            'leave_type_id' => $melahirkan->id,
            'leave_request_case_id' => null,
            'tanggal_mulai' => '2026-11-02',
            'tanggal_selesai' => '2026-12-31',
        ]);
        $this->assertNotNull($first->leave_request_case_id);
        $this->assertDatabaseHas('leave_request_cases', [
            'id' => $first->leave_request_case_id,
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $melahirkan->id,
            'created_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CREATE',
            'auditable_type' => 'LeaveRequestCase',
            'auditable_id' => $first->leave_request_case_id,
            'user_id' => $admin->id,
        ]);

        $second = $this->storeDirect($employee, $admin, [
            'leave_type_id' => $melahirkan->id,
            'leave_request_case_id' => $first->leave_request_case_id,
            'tanggal_mulai' => '2027-01-01',
            'tanggal_selesai' => '2027-01-30',
        ]);
        $this->assertSame($first->leave_request_case_id, $second->leave_request_case_id);
        $this->assertSame(1, LeaveRequestCase::query()->where('employee_id', $employee->id)->count());

        try {
            $this->storeDirect($employee, $admin, [
                'leave_type_id' => $melahirkan->id,
                'leave_request_case_id' => $first->leave_request_case_id,
                'tanggal_mulai' => '2027-02-01',
                'tanggal_selesai' => '2027-02-05',
            ]);
            $this->fail('Split yang melampaui tiga bulan kalender seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tanggal_selesai', $exception->errors());
        }

        $this->assertSame(2, LeaveUsageRecord::query()->where('record_status', 'active')->count());
    }

    public function test_overlap_request_memakai_positive_allowlist_dan_interval_inklusif(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $requestType = $this->nonAnnualType();
        $manualType = $this->leaveType('izin-alasan-penting', false);
        $statuses = [
            'menunggu_approval',
            'ditangguhkan',
            LeaveRequest::STATUS_CANCELLATION_PENDING,
            'ditangguhkan_tugas_dinas',
            'dikembalikan_karena_rollover',
            'disetujui',
        ];

        foreach ($statuses as $status) {
            $employee = Employee::factory()->create();
            $this->createLeaveRequest($employee, $requestType, '2026-01-05', '2026-01-07', $status);

            try {
                $this->storeDirect($employee, $admin, [
                    'leave_type_id' => $manualType->id,
                    'tanggal_mulai' => '2026-01-07',
                    'tanggal_selesai' => '2026-01-09',
                ]);
                $this->fail("Status aktif {$status} seharusnya memblokir overlap inklusif.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('tanggal_mulai', $exception->errors());
            }
        }

        $terminalEmployee = Employee::factory()->create();
        $this->createLeaveRequest($terminalEmployee, $requestType, '2026-01-05', '2026-01-07', 'tidak_disetujui');
        $allowed = $this->storeDirect($terminalEmployee, $admin, [
            'leave_type_id' => $manualType->id,
            'tanggal_mulai' => '2026-01-07',
            'tanggal_selesai' => '2026-01-09',
        ]);
        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $allowed->record_status);
    }

    public function test_overlap_manual_dan_duplikat_exact_ditolak(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $this->storeDirect($employee, $admin, [
            'tanggal_mulai' => '2026-01-05',
            'tanggal_selesai' => '2026-01-07',
        ]);

        foreach ([
            ['2026-01-07', '2026-01-09', $this->leaveType('izin-alasan-penting', false)->id],
            ['2026-01-05', '2026-01-07', $this->nonAnnualType()->id],
        ] as [$start, $end, $typeId]) {
            try {
                $this->storeDirect($employee, $admin, [
                    'leave_type_id' => $typeId,
                    'tanggal_mulai' => $start,
                    'tanggal_selesai' => $end,
                ]);
                $this->fail('Overlap manual seharusnya ditolak.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('tanggal_mulai', $exception->errors());
            }
        }

        $this->assertSame(1, LeaveUsageRecord::query()->where('record_status', 'active')->count());
    }

    public function test_status_request_legacy_di_luar_allowlist_tidak_dianggap_overlap_aktif(): void
    {
        $this->requirePostgreSql();
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $type = $this->nonAnnualType();
        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT leave_requests_status_check');
        $legacy = $this->createLeaveRequest($employee, $type, '2026-01-05', '2026-01-07', 'Draft');

        try {
            $record = $this->storeDirect($employee, $admin, [
                'tanggal_mulai' => '2026-01-07',
                'tanggal_selesai' => '2026-01-09',
            ]);
            $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $record->record_status);
        } finally {
            $legacy->delete();
            DB::statement(<<<'SQL'
ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_status_check CHECK (status IN (
    'menunggu_approval', 'ditangguhkan', 'ditangguhkan_tugas_dinas',
    'perlu_perubahan', 'disetujui', 'tidak_disetujui', 'dikembalikan_karena_rollover'
))
SQL);
        }
    }

    public function test_manual_existing_memblokir_submit_request_normal(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $manualType = $this->nonAnnualType();
        $requestType = $this->leaveType('izin-alasan-penting', false);
        $this->storeDirect($employee, $admin, [
            'leave_type_id' => $manualType->id,
            'tanggal_mulai' => '2026-01-05',
            'tanggal_selesai' => '2026-01-07',
        ]);
        $user = $this->employeeWithApprovalChain($employee);

        $this->actingAs($user)
            ->post(route('cuti.store'), [
                'jenis_cuti_id' => $requestType->id,
                'tanggal_mulai' => '2026-01-07',
                'tanggal_selesai' => '2026-01-09',
                'alasan' => 'Pengajuan yang berbenturan manual.',
                'alamat_selama_cuti' => 'Alamat pengujian',
                'nomor_telepon' => '+62 811 1111',
            ])
            ->assertSessionHasErrors('tanggal_mulai');

        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_manual_existing_memblokir_resubmit_request_normal(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $type = $this->nonAnnualType();
        $this->storeDirect($employee, $admin, [
            'leave_type_id' => $type->id,
            'tanggal_mulai' => '2026-01-05',
            'tanggal_selesai' => '2026-01-07',
        ]);
        $leaveRequest = $this->createLeaveRequest(
            $employee,
            $type,
            '2026-02-02',
            '2026-02-03',
            'menunggu_approval',
        );
        $actor = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $request = $this->requestFor($actor);

        try {
            app(ResubmitLeaveRequestAction::class)->execute($leaveRequest, [
                'revision_version' => (int) $leaveRequest->fresh()->revision_version,
                'tanggal_mulai' => '2026-01-07',
                'tanggal_selesai' => '2026-01-09',
                'alasan' => 'Resubmit yang berbenturan manual.',
                'alamat_selama_cuti' => 'Alamat pengujian',
                'nomor_telepon' => '+62 811 3333',
            ], $request);
            $this->fail('Resubmit yang overlap manual seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tanggal_mulai', $exception->errors());
        }

        $this->assertSame('menunggu_approval', $leaveRequest->fresh()->status);
        $this->assertSame('2026-02-02', $leaveRequest->fresh()->tanggal_mulai->toDateString());
    }

    public function test_resubmit_non_overlap_mengecualikan_request_dirinya_sendiri(): void
    {
        $employee = Employee::factory()->create();
        $type = $this->nonAnnualType();
        $leaveRequest = $this->createLeaveRequest(
            $employee,
            $type,
            '2026-02-02',
            '2026-02-03',
            'menunggu_approval',
        );
        $actor = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $lockOrder = [];
        DB::listen(function ($query) use (&$lockOrder): void {
            $sql = strtolower((string) $query->sql);

            if (! str_contains($sql, 'for update')) {
                return;
            }

            if (str_contains($sql, 'from "leave_requests"')) {
                $lockOrder[] = 'request';
            } elseif (str_contains($sql, 'from "employees"')) {
                $lockOrder[] = 'employee';
            }
        });

        $updated = app(ResubmitLeaveRequestAction::class)->execute($leaveRequest, [
            'revision_version' => (int) $leaveRequest->fresh()->revision_version,
            'tanggal_mulai' => '2026-02-02',
            'tanggal_selesai' => '2026-02-04',
            'alasan' => 'Resubmit valid tanpa overlap lain.',
            'alamat_selama_cuti' => 'Alamat pengujian',
            'nomor_telepon' => '+62 811 4444',
        ], $this->requestFor($actor));

        $this->assertSame('menunggu_approval', $updated->status);
        $this->assertSame('2026-02-04', $updated->tanggal_selesai->toDateString());
        $requestLock = array_search('request', $lockOrder, true);
        $employeeLock = array_search('employee', $lockOrder, true);
        $this->assertIsInt($requestLock);
        $this->assertIsInt($employeeLock);
        $this->assertLessThan($employeeLock, $requestLock, 'Request existing wajib dikunci sebelum employee.');
    }

    public function test_hanya_cuti_tahunan_memicu_projection_dan_hari_client_tidak_dipercaya(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $nonAnnualEmployee = Employee::factory()->create();

        $this->storeDirect($nonAnnualEmployee, $admin, [
            'leave_type_id' => $this->nonAnnualType()->id,
            'jumlah_hari_kerja' => 99,
        ]);
        $this->assertDatabaseMissing('leave_balances', ['employee_id' => $nonAnnualEmployee->id]);

        $annualEmployee = $this->eligibleEmployee();
        $annual = $this->storeDirect($annualEmployee, $admin, [
            'leave_type_id' => $this->annualType()->id,
            'tanggal_mulai' => '2026-01-05',
            'tanggal_selesai' => '2026-01-06',
            'jumlah_hari_kerja' => 99,
        ]);
        $balance = LeaveBalance::query()
            ->where('employee_id', $annualEmployee->id)
            ->where('tahun', 2026)
            ->sole();

        $this->assertSame(2, $annual->workdays);
        $this->assertSame(2, $balance->terpakai);
    }

    public function test_action_store_menolak_fakta_tahunan_tahun_setelah_tahun_berjalan_secara_atomik(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = $this->eligibleEmployee();

        try {
            $this->storeDirect($employee, $admin, [
                'leave_type_id' => $this->annualType()->id,
                'tanggal_mulai' => '2027-01-04',
                'tanggal_selesai' => '2027-01-05',
            ]);
            $this->fail('Fakta tahunan tahun setelah tahun berjalan wajib ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('usage_year', $exception->errors());
        }

        $this->assertDatabaseCount('leave_usage_records', 0);
        $this->assertDatabaseCount('leave_usage_documents', 0);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseMissing('leave_balances', ['employee_id' => $employee->id]);
        $this->assertSame([], Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX));
    }

    public function test_service_langsung_menolak_create_fakta_tahunan_tahun_setelah_tahun_berjalan(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = $this->eligibleEmployee();

        try {
            app(LeaveUsageRecordService::class)->recordManual(
                $employee,
                $this->annualType(),
                '2027-01-04',
                '2027-01-05',
                2,
                'Fakta tahunan masa depan dari service langsung.',
                null,
                null,
                $this->validManualApprovalStepData(),
                $admin,
                [],
                $this->requestFor($admin),
            );
            $this->fail('Service fakta wajib menolak horizon tahunan masa depan.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('usage_year', $exception->errors());
        }

        $this->assertDatabaseCount('leave_usage_records', 0);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseMissing('leave_balances', ['employee_id' => $employee->id]);
    }

    public function test_action_koreksi_menolak_pengganti_tahunan_masa_depan_tanpa_mengubah_fakta_lama(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $current = $this->storeDirect($employee, $admin);
        $countsBefore = [
            'records' => LeaveUsageRecord::query()->count(),
            'documents' => LeaveUsageDocument::query()->count(),
            'ledger' => LeaveBalanceLedger::query()->count(),
            'audits' => AuditLog::query()->count(),
        ];
        $filesBefore = Storage::disk(LeaveUsageDocument::STORAGE_DISK)
            ->allFiles(LeaveUsageDocument::PATH_PREFIX);

        try {
            app(CorrectManualLeaveUsageAction::class)->execute(
                $current->id,
                [
                    'leave_type_id' => $this->annualType()->id,
                    'leave_request_case_id' => null,
                    'tanggal_mulai' => '2027-02-01',
                    'tanggal_selesai' => '2027-02-02',
                    'alasan' => 'Koreksi menjadi fakta tahunan masa depan.',
                    'correction_reason' => 'Jenis dan tahun pada surat perlu diperbaiki.',
                    'approval_document_number' => $current->approval_document_number,
                    'approval_steps' => $this->persistedApprovalPayload($current),
                ],
                $this->validDocument('koreksi-future-tahunan.pdf'),
                $admin,
                $this->requestFor($admin),
            );
            $this->fail('Koreksi menjadi fakta tahunan masa depan wajib ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('usage_year', $exception->errors());
        }

        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $current->fresh()->record_status);
        $this->assertSame($countsBefore['records'], LeaveUsageRecord::query()->count());
        $this->assertSame($countsBefore['documents'], LeaveUsageDocument::query()->count());
        $this->assertSame($countsBefore['ledger'], LeaveBalanceLedger::query()->count());
        $this->assertSame($countsBefore['audits'], AuditLog::query()->count());
        $this->assertDatabaseMissing('leave_balances', ['employee_id' => $employee->id]);
        $this->assertSame(
            $filesBefore,
            Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX),
        );
    }

    public function test_service_langsung_menolak_koreksi_ke_fakta_tahunan_masa_depan(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $current = $this->rawManualFact($employee, $admin);

        try {
            app(LeaveUsageRecordService::class)->replace(
                $current,
                [
                    'leave_type_id' => $this->annualType()->id,
                    'usage_year' => 2027,
                    'effective_date' => '2027-03-01',
                    'start_date' => '2027-03-01',
                    'end_date' => '2027-03-02',
                    'workdays' => 2,
                    'administrative_note' => 'Koreksi service langsung ke masa depan.',
                ],
                'Tahun fakta pada surat awal salah.',
                $admin,
                $this->validManualApprovalStepData(),
                $this->requestFor($admin),
            );
            $this->fail('Service koreksi wajib menolak horizon tahunan masa depan.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('usage_year', $exception->errors());
        }

        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $current->fresh()->record_status);
        $this->assertDatabaseCount('leave_usage_records', 1);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseMissing('leave_balances', ['employee_id' => $employee->id]);
    }

    public function test_fakta_tahunan_historis_dan_tanggal_masa_depan_dalam_tahun_berjalan_tetap_diterima(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $historicalEmployee = $this->eligibleEmployee();
        $currentEmployee = $this->eligibleEmployee();
        $annual = $this->annualType();

        $historical = $this->storeDirect($historicalEmployee, $admin, [
            'leave_type_id' => $annual->id,
            'tanggal_mulai' => '2025-12-01',
            'tanggal_selesai' => '2025-12-01',
        ]);
        $futureWithinCurrentYear = $this->storeDirect($currentEmployee, $admin, [
            'leave_type_id' => $annual->id,
            'tanggal_mulai' => '2026-12-01',
            'tanggal_selesai' => '2026-12-01',
        ]);

        $this->assertSame(2025, $historical->usage_year);
        $this->assertSame(2026, $futureWithinCurrentYear->usage_year);
        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $historicalEmployee->id,
            'tahun' => 2025,
            'terpakai' => 1,
        ]);
        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $currentEmployee->id,
            'tahun' => 2026,
            'terpakai' => 1,
        ]);
    }

    public function test_fakta_non_tahunan_tahun_masa_depan_tetap_mengikuti_validasi_domain_existing(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $record = $this->storeDirect($employee, $admin, [
            'leave_type_id' => $this->nonAnnualType()->id,
            'tanggal_mulai' => '2027-04-05',
            'tanggal_selesai' => '2027-04-06',
        ]);

        $this->assertSame(2027, $record->usage_year);
        $this->assertDatabaseMissing('leave_balances', ['employee_id' => $employee->id]);
    }

    public function test_service_pencatatan_pengajuan_disetujui_menolak_fakta_tahunan_masa_depan(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = $this->eligibleEmployee();
        $request = $this->createLeaveRequest(
            $employee,
            $this->annualType(),
            '2027-05-03',
            '2027-05-03',
            'disetujui',
        );

        try {
            app(LeaveUsageRecordService::class)->recordApprovedRequest(
                $request,
                $admin,
                $this->requestFor($admin),
            );
            $this->fail('Service approved request wajib menolak fakta tahunan masa depan.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('usage_year', $exception->errors());
        }

        $this->assertDatabaseCount('leave_usage_records', 0);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseMissing('leave_balances', ['employee_id' => $employee->id]);
    }

    public function test_koreksi_dan_pembatalan_non_tahunan_tidak_membuat_projection_atau_recalc_ledger(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $current = $this->storeDirect($employee, $admin);

        $replacement = app(CorrectManualLeaveUsageAction::class)->execute(
            $current->id,
            [
                'leave_type_id' => $this->leaveType('izin-alasan-penting', false)->id,
                'leave_request_case_id' => null,
                'tanggal_mulai' => '2026-01-08',
                'tanggal_selesai' => '2026-01-09',
                'alasan' => 'Koreksi non-tahunan.',
                'correction_reason' => 'Jenis dan tanggal surat diperbaiki.',
                'approval_document_number' => $current->approval_document_number,
                'approval_steps' => $this->persistedApprovalPayload($current),
            ],
            $this->validDocument('koreksi-non-tahunan.pdf'),
            $admin,
            $this->requestFor($admin),
        );
        app(CancelManualLeaveUsageAction::class)->execute(
            $replacement->id,
            'Persetujuan non-tahunan dibatalkan.',
            $admin,
            $this->requestFor($admin),
        );

        $this->assertDatabaseMissing('leave_balances', ['employee_id' => $employee->id]);
        $this->assertSame(0, LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->count());
    }

    public function test_koreksi_annual_ke_nonannual_dan_kembali_memberi_delta_projection_tepat(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = $this->eligibleEmployee();
        $annual = $this->annualType();
        $nonAnnual = $this->nonAnnualType();
        $annualRecord = $this->storeDirect($employee, $admin, [
            'leave_type_id' => $annual->id,
        ]);
        $this->assertSame(3, $this->balanceUsage($employee, 2026));

        $nonAnnualRecord = app(CorrectManualLeaveUsageAction::class)->execute(
            $annualRecord->id,
            [
                'leave_type_id' => $nonAnnual->id,
                'leave_request_case_id' => null,
                'tanggal_mulai' => '2026-01-05',
                'tanggal_selesai' => '2026-01-07',
                'alasan' => 'Jenis cuti diperbaiki non-tahunan.',
                'correction_reason' => 'Kode jenis pada surat awal salah.',
                'approval_document_number' => $annualRecord->approval_document_number,
                'approval_steps' => $this->persistedApprovalPayload($annualRecord),
            ],
            $this->validDocument('ke-non-tahunan.pdf'),
            $admin,
            $this->requestFor($admin),
        );
        $this->assertSame(0, $this->balanceUsage($employee, 2026));

        $annualAgain = app(CorrectManualLeaveUsageAction::class)->execute(
            $nonAnnualRecord->id,
            [
                'leave_type_id' => $annual->id,
                'leave_request_case_id' => null,
                'tanggal_mulai' => '2026-01-05',
                'tanggal_selesai' => '2026-01-07',
                'alasan' => 'Jenis cuti dikonfirmasi tahunan.',
                'correction_reason' => 'Konfirmasi final dari instansi penerbit.',
                'approval_document_number' => $nonAnnualRecord->approval_document_number,
                'approval_steps' => $this->persistedApprovalPayload($nonAnnualRecord),
            ],
            $this->validDocument('kembali-tahunan.pdf'),
            $admin,
            $this->requestFor($admin),
        );
        $this->assertSame(3, $this->balanceUsage($employee, 2026));

        app(CancelManualLeaveUsageAction::class)->execute(
            $annualAgain->id,
            'Persetujuan tahunan akhirnya dibatalkan.',
            $admin,
            $this->requestFor($admin),
        );
        $this->assertSame(0, $this->balanceUsage($employee, 2026));
    }

    public function test_fakta_approved_request_non_tahunan_tidak_membuat_projection_recalc(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $request = $this->createLeaveRequest(
            $employee,
            $this->nonAnnualType(),
            '2026-01-05',
            '2026-01-07',
            'disetujui',
        );

        $fact = app(LeaveUsageRecordService::class)->recordApprovedRequest($request, $actor);

        $this->assertSame(LeaveUsageRecord::SOURCE_APPROVED_REQUEST, $fact->source_type);
        $this->assertDatabaseMissing('leave_balances', ['employee_id' => $employee->id]);
        $this->assertSame(0, LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->count());
    }

    public function test_backdated_setelah_snapshot_dan_delta_membership_koreksi_pembatalan_tepat(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = $this->eligibleEmployee();
        $annual = $this->annualType();
        app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            2026,
            [2024 => 0, 2025 => 0, 2026 => 3],
            Carbon::parse('2026-08-18'),
            'Snapshot pemakaian awal.',
            $admin,
        );

        $backdated = $this->storeDirect($employee, $admin, [
            'leave_type_id' => $annual->id,
            'tanggal_mulai' => '2026-01-05',
            'tanggal_selesai' => '2026-01-06',
        ]);
        $this->assertSame(5, $this->balanceUsage($employee, 2026));

        $replacement = app(CorrectManualLeaveUsageAction::class)->execute(
            $backdated->id,
            [
                'leave_type_id' => $annual->id,
                'tanggal_mulai' => '2026-01-05',
                'tanggal_selesai' => '2026-01-07',
                'alasan' => 'Periode final diperpanjang.',
                'correction_reason' => 'Koreksi surat eksternal.',
                'approval_document_number' => $backdated->approval_document_number,
                'approval_steps' => $this->persistedApprovalPayload($backdated),
            ],
            $this->validDocument('koreksi.pdf'),
            $admin,
            $this->requestFor($admin),
        );
        $this->assertSame(6, $this->balanceUsage($employee, 2026));

        app(CancelManualLeaveUsageAction::class)->execute(
            $replacement->id,
            'Surat persetujuan eksternal dibatalkan.',
            $admin,
            $this->requestFor($admin),
        );
        $this->assertSame(3, $this->balanceUsage($employee, 2026));
    }

    public function test_manual_tidak_membuat_side_effect_approval(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->storeDirect($employee, $admin);

        $this->assertDatabaseCount('leave_requests', 0);
        $this->assertDatabaseCount('leave_request_steps', 0);
        $this->assertDatabaseCount('leave_proofs', 0);
        $this->assertDatabaseCount('leave_balance_reservation_events', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_koreksi_dan_pembatalan_mempertahankan_row_dan_file_historis(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $current = $this->storeDirect($employee, $admin);
        $originalDocument = $current->documents()->sole();

        $replacement = app(CorrectManualLeaveUsageAction::class)->execute(
            $current->id,
            [
                'leave_type_id' => $current->leave_type_id,
                'tanggal_mulai' => '2026-01-06',
                'tanggal_selesai' => '2026-01-08',
                'alasan' => 'Tanggal surat diperbaiki.',
                'correction_reason' => 'Koreksi administratif.',
                'approval_document_number' => $current->approval_document_number,
                'approval_steps' => $this->persistedApprovalPayload($current),
            ],
            $this->validDocument('koreksi.pdf'),
            $admin,
            $this->requestFor($admin),
        );
        $replacementDocument = $replacement->documents()->sole();

        app(CancelManualLeaveUsageAction::class)->execute(
            $replacement->id,
            'Cuti eksternal dibatalkan instansi penerbit.',
            $admin,
            $this->requestFor($admin),
        );

        $this->assertSame(2, LeaveUsageRecord::query()->count());
        $this->assertSame(2, LeaveUsageDocument::query()->count());
        $this->assertSame(LeaveUsageRecord::STATUS_SUPERSEDED, $current->fresh()->record_status);
        $this->assertSame(LeaveUsageRecord::STATUS_CANCELLED, $replacement->fresh()->record_status);
        $this->assertTrue(Storage::disk(LeaveUsageDocument::STORAGE_DISK)->exists($originalDocument->path));
        $this->assertTrue(Storage::disk(LeaveUsageDocument::STORAGE_DISK)->exists($replacementDocument->path));
    }

    public function test_audit_mencatat_aktor_alasan_file_fact_projection_tanpa_path_publik(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = $this->eligibleEmployee();
        $record = $this->storeDirect($employee, $admin, [
            'leave_type_id' => $this->annualType()->id,
        ]);

        $audit = AuditLog::query()
            ->where('auditable_type', 'LeaveUsageRecord')
            ->where('auditable_id', $record->id)
            ->where('new_values->operation', 'manual_usage_recorded')
            ->sole();

        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('admin_kepegawaian', $audit->new_values['actor_role']);
        $this->assertSame('Cuti yang disetujui di luar SIMPEG.', $audit->new_values['reason']);
        $this->assertSame('bukti.pdf', $audit->new_values['document']['original_name']);
        $this->assertArrayHasKey('fact_after', $audit->new_values);
        $this->assertArrayHasKey('projection_before', $audit->new_values);
        $this->assertArrayHasKey('projection_after', $audit->new_values);
        $this->assertArrayNotHasKey('path', $audit->new_values['document']);
        $this->assertArrayNotHasKey('stored_name', $audit->new_values['document']);
        $this->assertArrayNotHasKey('public_url', $audit->new_values['document']);
    }

    public function test_audit_koreksi_dan_pembatalan_masing_masing_satu_event_domain_lengkap(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $current = $this->storeDirect($employee, $admin);
        $replacement = app(CorrectManualLeaveUsageAction::class)->execute(
            $current->id,
            [
                'leave_type_id' => $current->leave_type_id,
                'leave_request_case_id' => null,
                'tanggal_mulai' => '2026-01-08',
                'tanggal_selesai' => '2026-01-09',
                'alasan' => 'Periode eksternal diperbaiki.',
                'correction_reason' => 'Surat koreksi diterima.',
                'approval_document_number' => $current->approval_document_number,
                'approval_steps' => $this->persistedApprovalPayload($current),
            ],
            $this->validDocument('koreksi-audit.pdf'),
            $admin,
            $this->requestFor($admin),
        );
        app(CancelManualLeaveUsageAction::class)->execute(
            $replacement->id,
            'Persetujuan eksternal dicabut.',
            $admin,
            $this->requestFor($admin),
        );

        foreach ([
            'manual_usage_corrected' => 'Surat koreksi diterima.',
            'manual_usage_cancelled' => 'Persetujuan eksternal dicabut.',
        ] as $operation => $reason) {
            $audits = AuditLog::query()
                ->where('auditable_type', 'LeaveUsageRecord')
                ->where('new_values->operation', $operation)
                ->get();
            $this->assertCount(1, $audits, "{$operation} harus ditulis sebagai satu audit domain.");
            $values = $audits->sole()->new_values;

            $this->assertSame($admin->id, $audits->sole()->user_id);
            $this->assertSame('admin_kepegawaian', $values['actor_role']);
            $this->assertSame($reason, $values['reason']);
            $this->assertArrayHasKey('fact_before', $values);
            $this->assertArrayHasKey('fact_after', $values);
            $this->assertArrayHasKey('projection_before', $values);
            $this->assertArrayHasKey('projection_after', $values);
            if ($operation === 'manual_usage_corrected') {
                $this->assertEqualsCanonicalizing(
                    ['original_name', 'mime_type', 'size_bytes'],
                    array_keys($values['document']),
                );
                $this->assertArrayNotHasKey('path', $values['document']);
                $this->assertArrayNotHasKey('disk', $values['document']);
                $this->assertArrayNotHasKey('stored_name', $values['document']);
                $this->assertSame('Cuti yang disetujui di luar SIMPEG.', $values['fact_before']['administrative_note']);
                $this->assertSame('Periode eksternal diperbaiki.', $values['fact_after']['administrative_note']);
                $this->assertSame($admin->id, $values['fact_after']['recorded_by']);
                $this->assertSame('Surat koreksi diterima.', $values['fact_after']['correction_reason']);
            } else {
                $this->assertSame([], $values['document']);
                $this->assertSame('Periode eksternal diperbaiki.', $values['fact_before']['administrative_note']);
                $this->assertSame('Periode eksternal diperbaiki.', $values['fact_after']['administrative_note']);
                $this->assertSame(LeaveUsageRecord::STATUS_CANCELLED, $values['fact_after']['record_status']);
                $this->assertSame('Persetujuan eksternal dicabut.', $values['fact_after']['correction_reason']);
            }
        }
    }

    public function test_kegagalan_audit_dengan_delete_false_mencatat_cleanup_durable_dan_retry_menghapus_file_baru(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $realDisk = Storage::disk(LeaveUsageDocument::STORAGE_DISK);
        $deleteAttempts = 0;
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $this->mockExpectation($disk, 'putFileAs')->once()->andReturnUsing(
            fn (string $directory, UploadedFile $file, string $storedName): string|false => $realDisk->putFileAs($directory, $file, $storedName),
        );
        $this->mockExpectation($disk, 'exists')->times(3)->andReturnUsing(
            fn (string $path): bool => $realDisk->exists($path),
        );
        $this->mockExpectation($disk, 'delete')->twice()->andReturnUsing(
            function (string $path) use (&$deleteAttempts, $realDisk): bool {
                $deleteAttempts++;

                return $deleteAttempts > 1 && $realDisk->delete($path);
            },
        );
        Storage::shouldReceive('disk')->with(LeaveUsageDocument::STORAGE_DISK)->andReturn($disk);
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::creating(function (): void {
            throw new \RuntimeException('Simulasi audit gagal.');
        });

        try {
            $this->storeDirect($employee, $admin);
            $this->fail('Kegagalan audit wajib diteruskan.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi audit gagal.', $exception->getMessage());
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertDatabaseCount('leave_usage_records', 0);
        $this->assertDatabaseCount('leave_usage_documents', 0);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
        $newFiles = $realDisk->allFiles(LeaveUsageDocument::PATH_PREFIX);
        $this->assertCount(1, $newFiles);
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'operation' => StorageRecoveryTask::OPERATION_DELETE,
            'status' => StorageRecoveryTask::STATUS_PENDING,
            'category' => 'leave_usage_document',
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'path' => $newFiles[0],
            'owner_id' => $employee->id,
            'attempts' => 1,
        ]);

        $this->artisan('storage:retry-recovery')->assertSuccessful();
        $this->assertFalse($realDisk->exists($newFiles[0]));
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'path' => $newFiles[0],
            'status' => StorageRecoveryTask::STATUS_COMPLETED,
            'attempts' => 2,
        ]);
    }

    public function test_kegagalan_koreksi_dan_pembatalan_mempertahankan_state_dan_file_lama(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $current = $this->storeDirect($employee, $admin);
        $originalDocument = $current->documents()->sole();
        $snapshot = [
            'records' => LeaveUsageRecord::query()->count(),
            'documents' => LeaveUsageDocument::query()->count(),
            'ledger' => LeaveBalanceLedger::query()->count(),
            'audits' => AuditLog::query()->count(),
            'balances' => LeaveBalance::query()->where('employee_id', $employee->id)->count(),
        ];

        foreach (['correct', 'cancel'] as $operation) {
            $dispatcher = AuditLog::getEventDispatcher();
            AuditLog::creating(function (): void {
                throw new \RuntimeException('Simulasi audit mutation gagal.');
            });

            try {
                if ($operation === 'correct') {
                    app(CorrectManualLeaveUsageAction::class)->execute(
                        $current->id,
                        [
                            'leave_type_id' => $current->leave_type_id,
                            'leave_request_case_id' => null,
                            'tanggal_mulai' => '2026-01-08',
                            'tanggal_selesai' => '2026-01-09',
                            'alasan' => 'Koreksi yang harus rollback.',
                            'correction_reason' => 'Simulasi rollback koreksi.',
                            'approval_document_number' => $current->approval_document_number,
                            'approval_steps' => $this->persistedApprovalPayload($current),
                        ],
                        $this->validDocument('koreksi-gagal.pdf'),
                        $admin,
                        $this->requestFor($admin),
                    );
                } else {
                    app(CancelManualLeaveUsageAction::class)->execute(
                        $current->id,
                        'Simulasi rollback pembatalan.',
                        $admin,
                        $this->requestFor($admin),
                    );
                }

                $this->fail("{$operation} wajib gagal ketika audit gagal.");
            } catch (\RuntimeException $exception) {
                $this->assertSame('Simulasi audit mutation gagal.', $exception->getMessage());
            } finally {
                AuditLog::setEventDispatcher($dispatcher);
            }

            $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $current->fresh()->record_status);
            $this->assertSame($snapshot['records'], LeaveUsageRecord::query()->count());
            $this->assertSame($snapshot['documents'], LeaveUsageDocument::query()->count());
            $this->assertSame($snapshot['ledger'], LeaveBalanceLedger::query()->count());
            $this->assertSame($snapshot['audits'], AuditLog::query()->count());
            $this->assertSame($snapshot['balances'], LeaveBalance::query()->where('employee_id', $employee->id)->count());
            $this->assertTrue(Storage::disk(LeaveUsageDocument::STORAGE_DISK)->exists($originalDocument->path));
            $this->assertSame(
                [$originalDocument->path],
                Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX),
            );
        }
    }

    public function test_kegagalan_replay_rollback_fact_dokumen_ledger_audit_dan_file(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        try {
            $this->storeDirect($employee, $admin, [
                'leave_type_id' => $this->annualType()->id,
                'tanggal_mulai' => '2026-01-01',
                'tanggal_selesai' => '2026-03-31',
            ]);
            $this->fail('Pemakaian di atas hak replay seharusnya ditolak.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('leave_usage_records', 0);
        }

        $this->assertDatabaseCount('leave_usage_documents', 0);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseMissing('leave_balances', ['employee_id' => $employee->id]);
        $this->assertSame([], Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX));
    }

    /**
     * Tahun baru WITA bukan masa depan saat aplikasi masih 31 Desember UTC; overuse tetap gagal atomik.
     */
    public function test_pemakaian_tahunan_berlebih_pada_tahun_baru_wita_ditolak_atomik_saat_aplikasi_utc(): void
    {
        $originalPhpTimezone = date_default_timezone_get();
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::create(2025, 12, 31, 16, 15, 0, 'UTC'));

        try {
            $admin = User::factory()->adminKepegawaian()->create();
            $employee = $this->eligibleEmployee();

            try {
                $this->storeDirect($employee, $admin, [
                    'leave_type_id' => $this->annualType()->id,
                    'tanggal_mulai' => '2026-01-05',
                    'tanggal_selesai' => '2026-01-30',
                ]);
                $this->fail('Pemakaian tahun baru WITA yang melebihi hak wajib ditolak.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('usage.2026', $exception->errors());
            }

            $this->assertDatabaseCount('leave_usage_records', 0);
            $this->assertDatabaseCount('leave_usage_documents', 0);
            $this->assertDatabaseCount('leave_balance_ledger', 0);
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertDatabaseMissing('leave_balances', ['employee_id' => $employee->id]);
            $this->assertSame([], Storage::disk(LeaveUsageDocument::STORAGE_DISK)->allFiles(LeaveUsageDocument::PATH_PREFIX));
        } finally {
            date_default_timezone_set($originalPhpTimezone);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function validPayload(array $overrides = []): array
    {
        return array_merge($this->validData(), [
            'dokumen' => $this->validDocument(),
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'leave_type_id' => $this->nonAnnualType()->id,
            'leave_request_case_id' => null,
            'tanggal_mulai' => '2026-01-05',
            'tanggal_selesai' => '2026-01-07',
            'alasan' => 'Cuti yang disetujui di luar SIMPEG.',
            'approval_document_number' => 'FIXTURE/2026/001',
            'approval_steps' => $this->validManualApprovalPayload(),
        ], $overrides);
    }

    private function validDocument(string $name = 'bukti.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 20, 'application/pdf');
    }

    /**
     * Menghidrasi payload koreksi dari snapshot versi persisted, bukan konfigurasi chain saat ini.
     *
     * @return list<array<string, string|null>>
     */
    private function persistedApprovalPayload(LeaveUsageRecord $record): array
    {
        return $record->externalApprovalSteps()
            ->orderBy('step_order')
            ->get()
            ->map(fn (LeaveUsageExternalApprovalStep $step): array => [
                'step_type' => $step->step_type,
                'approver_source' => $step->approver_source,
                'approver_employee_id' => $step->approver_employee_id,
                'approver_name' => $step->approver_source === 'external_official' ? $step->approver_name_snapshot : null,
                'approver_position' => $step->approver_source === 'external_official' ? $step->approver_position_snapshot : null,
                'approver_institution' => $step->approver_source === 'external_official' ? $step->approver_institution_snapshot : null,
                'acted_on' => $step->acted_on->toDateString(),
                'decision_note' => $step->decision_note,
            ])
            ->values()
            ->all();
    }

    private function requestFor(User $actor): Request
    {
        $request = Request::create('/cuti/pemakaian-manual', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }

    /** @param array<string, mixed> $overrides */
    private function storeDirect(Employee $employee, User $actor, array $overrides = []): LeaveUsageRecord
    {
        return app(StoreManualLeaveUsageAction::class)->execute(
            $employee->id,
            $this->validData($overrides),
            $this->validDocument(),
            $actor,
            $this->requestFor($actor),
        );
    }

    private function eligibleEmployee(): Employee
    {
        $employee = Employee::factory()->create();
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
        ]);

        return $employee;
    }

    private function storeUrl(Employee $employee): string
    {
        return "/cuti/pemakaian-manual/{$employee->id}";
    }

    private function assertAllManualActionsReject(
        Employee $employee,
        LeaveUsageRecord $current,
        User $actor,
        string $label,
    ): void {
        $calls = [
            'store' => fn () => app(StoreManualLeaveUsageAction::class)->execute(
                $employee->id,
                $this->validData(),
                $this->validDocument("store-{$actor->id}.pdf"),
                $actor,
                $this->requestFor($actor),
            ),
            'correct' => fn () => app(CorrectManualLeaveUsageAction::class)->execute(
                $current->id,
                array_merge($this->validData(), ['correction_reason' => 'Alasan koreksi uji auth.']),
                $this->validDocument("correct-{$actor->id}.pdf"),
                $actor,
                $this->requestFor($actor),
            ),
            'cancel' => fn () => app(CancelManualLeaveUsageAction::class)->execute(
                $current->id,
                'Alasan pembatalan uji auth.',
                $actor,
                $this->requestFor($actor),
            ),
        ];

        foreach ($calls as $operation => $call) {
            try {
                $call();
                $this->fail("Action {$operation} seharusnya menolak {$label}.");
            } catch (AuthorizationException) {
                $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $current->fresh()->record_status);
            }
        }
    }

    private function rawManualFact(Employee $employee, User $actor): LeaveUsageRecord
    {
        $record = LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $this->nonAnnualType()->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'reconciliation_set_id' => null,
            'leave_request_id' => null,
            'leave_request_case_id' => null,
            'usage_year' => 2026,
            'effective_date' => '2026-01-05',
            'start_date' => '2026-01-05',
            'end_date' => '2026-01-07',
            'workdays' => 3,
            'administrative_note' => 'Fixture fakta manual untuk test.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $actor->id,
        ]);

        return $this->attachValidManualApprovalSnapshot($record);
    }

    private function annualType(): RefJenisCuti
    {
        return RefJenisCuti::query()->firstOrCreate(
            ['code' => 'tahunan'],
            [
                'nama' => 'Cuti Tahunan',
                'mengurangi_saldo_tahunan' => true,
                'khusus_pns' => false,
            ],
        );
    }

    private function nonAnnualType(): RefJenisCuti
    {
        return $this->leaveType('sakit', false);
    }

    private function leaveType(string $code, bool $annual): RefJenisCuti
    {
        return RefJenisCuti::query()->firstOrCreate(
            ['code' => $code],
            [
                'nama' => Str::headline($code),
                'mengurangi_saldo_tahunan' => $annual,
                'khusus_pns' => false,
            ],
        );
    }

    private function createLeaveRequest(
        Employee $employee,
        RefJenisCuti $type,
        string $start,
        string $end,
        string $status,
        ?LeaveRequestCase $case = null,
    ): LeaveRequest {
        return LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $type->id,
            'leave_request_case_id' => $case?->id,
            'tanggal_mulai' => $start,
            'tanggal_selesai' => $end,
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Fixture overlap pengajuan.',
            'alamat_selama_cuti' => 'Alamat fixture',
            'nomor_telepon' => '+62 811 1111',
            'status' => $status,
        ]);
    }

    private function employeeWithApprovalChain(Employee $employee): User
    {
        $kepalaBagian = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        SupervisorAssignment::query()->create([
            'employee_id' => $employee->id,
            'supervisor_id' => $kepalaBagian->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);
        $chain = LeaveApprovalChain::query()->create([
            'employee_id' => $employee->id,
            'name' => 'Chain uji overlap manual',
            'effective_from' => '2026-01-01',
            'change_reason' => 'Fixture uji overlap manual.',
        ]);
        $chain->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $kepalaBagian->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $pybmc->id,
                'is_final' => true,
            ],
        ]);

        return User::factory()->pegawai()->create(['employee_id' => $employee->id]);
    }

    private function balanceUsage(Employee $employee, int $year): int
    {
        return (int) LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', $year)
            ->sole()
            ->terpakai;
    }

    private function requirePostgreSql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Constraint status legacy wajib diuji pada PostgreSQL.');
        }
    }

    private function mockExpectation(MockInterface $mock, string $method): Expectation|CompositeExpectation
    {
        $expectation = $mock->shouldReceive($method);

        if (! $expectation instanceof Expectation && ! $expectation instanceof CompositeExpectation) {
            throw new \RuntimeException("Mockery tidak membuat expectation yang valid untuk {$method}.");
        }

        return $expectation;
    }
}
