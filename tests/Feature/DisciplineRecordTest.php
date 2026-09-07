<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeHistoryService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DisciplineRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
        Carbon::setTestNow('2026-06-23 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_list_discipline_records_ordered_newest_first(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        DisciplineRecord::create($this->recordPayload($employee, ['tanggal_mulai' => '2026-01-01', 'no_sk' => 'SK-OLD']));
        $sameStartOlder = DisciplineRecord::create($this->recordPayload($employee, ['tanggal_mulai' => '2026-05-01', 'no_sk' => 'SK-SAME-OLDER']));
        $sameStartOlder->forceFill(['created_at' => '2026-05-10 08:00:00'])->save();
        $sameStartNewer = DisciplineRecord::create($this->recordPayload($employee, ['tanggal_mulai' => '2026-05-01', 'no_sk' => 'SK-SAME-NEWER']));
        $sameStartNewer->forceFill(['created_at' => '2026-05-11 08:00:00'])->save();

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}/disiplin");

        $response->assertOk();
        $response->assertJsonPath('employee_id', $employee->id);
        $response->assertJsonCount(3, 'records');
        $response->assertJsonPath('records.0.no_sk', 'SK-SAME-NEWER');
        $response->assertJsonPath('records.0.tanggal_mulai', '2026-05-01');
        $response->assertJsonPath('records.1.no_sk', 'SK-SAME-OLDER');
        $response->assertJsonPath('records.2.no_sk', 'SK-OLD');
        $response->assertJsonMissingPath('records.0.employee');
        $response->assertJsonMissingPath('records.0.file_sk');
        $response->assertJsonMissingPath('records.0.updated_at');
    }

    public function test_admin_can_create_active_discipline_record_without_end_date_and_write_audit_log(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
            'tanggal_berakhir' => null,
            'is_active' => false,
        ]));

        $response->assertCreated();
        $response->assertJsonPath('message', 'Riwayat disiplin berhasil ditambahkan.');
        $response->assertJsonPath('record.is_active', true);
        $response->assertJsonPath('record.tanggal_mulai', '2026-06-01');
        $response->assertJsonPath('record.tanggal_berakhir', null);
        $response->assertJsonPath('record.tanggal_sk', '2026-05-25');
        $this->assertDatabaseHas('discipline_records', [
            'employee_id' => $employee->id,
            'no_sk' => 'SK-DIS-001',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CREATE',
            'auditable_type' => 'DisciplineRecord',
        ]);
        $audit = AuditLog::where('auditable_type', 'DisciplineRecord')->firstOrFail();
        $newValues = $audit->new_values;

        $this->assertSame('Ringan', Arr::get($newValues, 'jenis_hukuman'));
        $this->assertSame($employee->id, Arr::get($newValues, 'employee_id'));
        $this->assertArrayNotHasKey('deskripsi', $newValues);
        $this->assertArrayNotHasKey('no_sk', $newValues);
        $this->assertArrayNotHasKey('file_sk', $newValues);
    }

    public function test_file_sk_must_be_controlled_relative_pdf_path(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);

        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
            'file_sk' => '../secret.txt',
        ]))->assertJsonValidationErrors(['file_sk']);

        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_hukuman_disiplin',
            'nama_dokumen' => 'SK Disiplin Path Terkontrol',
            'file_path' => 'sk/disiplin-aman.pdf',
        ]);

        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
            'file_sk' => 'sk/disiplin-aman.pdf',
        ]))->assertCreated();
    }

    public function test_file_sk_string_rejects_cross_owner_wrong_category_and_unknown_path_before_mutation(): void
    {
        Storage::fake(Document::STORAGE_DISK);

        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $filesBefore = Storage::disk(Document::STORAGE_DISK)->allFiles();
        $paths = [
            'sk/cross-owner.pdf' => [$otherEmployee, 'sk_hukuman_disiplin'],
            'sk/wrong-category.pdf' => [$employee, 'lainnya'],
        ];
        foreach ($paths as $path => [$owner, $category]) {
            Document::create([
                'employee_id' => $owner->id,
                'jenis_dokumen' => $category,
                'nama_dokumen' => 'Dokumen Uji Scope Path',
                'file_path' => $path,
            ]);
        }
        $paths['sk/unknown.pdf'] = null;

        foreach (array_keys($paths) as $path) {
            $this->actingAs($user)
                ->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
                    'file_sk' => $path,
                    'no_sk' => 'SK-'.md5($path),
                ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['file_sk']);
        }

        $this->assertDatabaseCount('discipline_records', 0);
        $this->assertDatabaseCount('documents', 2);
        $this->assertSame($filesBefore, Storage::disk(Document::STORAGE_DISK)->allFiles());
    }

    public function test_dokumen_id_dan_file_sk_string_harus_merujuk_dokumen_yang_sama(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $firstDocument = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_hukuman_disiplin',
            'nama_dokumen' => 'SK Disiplin Pertama',
            'file_path' => 'sk/disiplin-pertama.pdf',
        ]);
        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_hukuman_disiplin',
            'nama_dokumen' => 'SK Disiplin Kedua',
            'file_path' => 'sk/disiplin-kedua.pdf',
        ]);

        $this->actingAs($user)
            ->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
                'dokumen_id' => $firstDocument->id,
                'file_sk' => 'sk/disiplin-kedua.pdf',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file_sk']);

        $this->assertDatabaseCount('discipline_records', 0);
        $this->assertDatabaseCount('documents', 2);
    }

    public function test_upload_baru_tidak_dapat_digabung_dengan_dokumen_arsip(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_hukuman_disiplin',
            'nama_dokumen' => 'SK Disiplin Arsip',
            'file_path' => 'sk/disiplin-arsip.pdf',
        ]);

        $this->actingAs($user)
            ->postWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
                'dokumen_id' => $document->id,
                'file_sk' => UploadedFile::fake()->create('sk-baru.pdf', 512, 'application/pdf'),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file_sk']);

        $this->assertDatabaseCount('discipline_records', 0);
        $this->assertDatabaseCount('documents', 1);
        Storage::disk(Document::STORAGE_DISK)->assertMissing('sk/disiplin-arsip.pdf');
    }

    public function test_service_menolak_file_sk_string_di_luar_dokumen_disiplin_milik_pegawai(): void
    {
        Storage::fake(Document::STORAGE_DISK);

        $employee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $filesBefore = Storage::disk(Document::STORAGE_DISK)->allFiles();
        Document::create([
            'employee_id' => $otherEmployee->id,
            'jenis_dokumen' => 'sk_hukuman_disiplin',
            'nama_dokumen' => 'SK Disiplin Pegawai Lain',
            'file_path' => 'sk/service-cross-owner.pdf',
        ]);

        try {
            app(EmployeeHistoryService::class)->createDisciplineRecord($employee, $this->validPayload([
                'file_sk' => 'sk/service-cross-owner.pdf',
            ]));
            $this->fail('Service harus menolak path dokumen di luar scope pegawai.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file_sk', $exception->errors());
        }

        $this->assertDatabaseCount('discipline_records', 0);
        $this->assertDatabaseCount('documents', 1);
        $this->assertSame($filesBefore, Storage::disk(Document::STORAGE_DISK)->allFiles());
    }

    public function test_admin_can_create_discipline_record_with_sk_upload(): void
    {

        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
            'file_sk' => UploadedFile::fake()->create('sk-valid.pdf', 512, 'application/pdf'),
        ]));

        $response->assertCreated();
        $response->assertJsonMissingPath('record.file_sk');
        $skPath = DisciplineRecord::query()->sole()->file_sk;
        $this->assertIsString($skPath);
        $this->assertStringStartsWith('sk/', $skPath);
        $this->assertStringEndsWith('.pdf', $skPath);
        Storage::disk(Document::STORAGE_DISK)->assertExists($skPath);
        $this->assertDatabaseHas('discipline_records', [
            'employee_id' => $employee->id,
            'file_sk' => $skPath,
        ]);
    }

    public function test_existing_document_discipline_must_belong_to_target_employee(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $targetEmployee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $otherDocument = Document::create([
            'employee_id' => $otherEmployee->id,
            'jenis_dokumen' => 'sk_hukuman_disiplin',
            'nama_dokumen' => 'SK milik pegawai lain',
            'file_path' => 'pegawai-lain/sk-disiplin.pdf',
        ]);

        $response = $this->actingAs($user)
            ->postJsonWithCsrf("/api/v1/pegawai/{$targetEmployee->id}/disiplin", $this->validPayload([
                'file_sk' => null,
                'dokumen_id' => $otherDocument->id,
            ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['dokumen_id']);
        $this->assertDatabaseMissing('discipline_records', [
            'employee_id' => $targetEmployee->id,
            'file_sk' => $otherDocument->file_path,
        ]);
    }

    public function test_existing_document_discipline_rejects_same_employee_document_outside_discipline_category(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $identityDocument = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'ktp_kk',
            'nama_dokumen' => 'KTP dan KK pegawai',
            'file_path' => 'identitas/ktp-kk.pdf',
        ]);

        $response = $this->actingAs($user)
            ->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
                'file_sk' => null,
                'dokumen_id' => $identityDocument->id,
            ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['dokumen_id']);
        $this->assertDatabaseCount('discipline_records', 0);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseHas('documents', [
            'id' => $identityDocument->id,
            'jenis_dokumen' => 'ktp_kk',
            'file_path' => 'identitas/ktp-kk.pdf',
        ]);
    }

    public function test_existing_discipline_document_for_target_employee_can_create_record(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $disciplineDocument = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_hukuman_disiplin',
            'nama_dokumen' => 'SK Hukuman Disiplin',
            'file_path' => 'sk/disiplin-arsip.pdf',
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($disciplineDocument->file_path, 'arsip sk disiplin');

        $response = $this->actingAs($user)
            ->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
                'file_sk' => null,
                'dokumen_id' => $disciplineDocument->id,
            ]));

        $response->assertCreated()
            ->assertJsonMissingPath('record.file_sk')
            ->assertJsonPath('record.download_url', route('pegawai.history-attachments.download', [
                'employee' => $employee,
                'type' => 'discipline',
                'history' => $response->json('record.id'),
            ]));
        $this->assertDatabaseHas('discipline_records', [
            'employee_id' => $employee->id,
            'file_sk' => 'sk/disiplin-arsip.pdf',
        ]);
    }

    public function test_existing_discipline_document_without_physical_file_returns_no_download_url(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $disciplineDocument = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_hukuman_disiplin',
            'nama_dokumen' => 'SK Hukuman Disiplin hilang',
            'file_path' => 'sk/disiplin-hilang.pdf',
        ]);

        $response = $this->actingAs($user)
            ->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
                'file_sk' => null,
                'dokumen_id' => $disciplineDocument->id,
            ]));

        $response->assertCreated()
            ->assertJsonMissingPath('record.file_sk')
            ->assertJsonPath('record.download_url', null);
    }

    #[DataProvider('conflictingDocumentReferenceProvider')]
    public function test_create_disiplin_tidak_mengiklankan_url_yang_ditolak_endpoint_unduh(string $conflictType): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $path = 'sk/disiplin-konflik-'.$conflictType.'.pdf';
        $disciplineDocument = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_hukuman_disiplin',
            'nama_dokumen' => 'SK Hukuman Disiplin valid',
            'file_path' => $path,
        ]);
        Document::create([
            'employee_id' => $conflictType === 'pegawai_lain'
                ? Employee::factory()->create()->id
                : $employee->id,
            'jenis_dokumen' => $conflictType === 'pegawai_lain'
                ? 'sk_hukuman_disiplin'
                : 'lainnya',
            'nama_dokumen' => 'Metadata dokumen konflik',
            'file_path' => $path,
        ]);
        Storage::disk(Document::STORAGE_DISK)->put($path, 'arsip sk disiplin');

        $response = $this->actingAs($user)
            ->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
                'file_sk' => null,
                'dokumen_id' => $disciplineDocument->id,
                'no_sk' => 'SK-KONFLIK-'.strtoupper($conflictType),
            ]));

        $response->assertCreated()
            ->assertJsonPath('record.download_url', null);
        $recordId = $response->json('record.id');
        $this->assertIsString($recordId);
        $this->assertDatabaseHas('discipline_records', [
            'id' => $recordId,
            'employee_id' => $employee->id,
            'file_sk' => $path,
        ]);

        $this->actingAs($user)
            ->get(route('pegawai.history-attachments.download', [
                'employee' => $employee,
                'type' => 'discipline',
                'history' => $recordId,
            ]))
            ->assertNotFound();
    }

    public function test_create_discipline_record_returns_protected_download_url_for_uploaded_file(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $response = $this->actingAs($user)
            ->postWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
                'file_sk' => UploadedFile::fake()->create('sk-download.pdf', 512, 'application/pdf'),
            ]));

        $response->assertCreated();
        $recordId = $response->json('record.id');
        $this->assertIsString($recordId);
        $response->assertJsonPath('record.download_url', route('pegawai.history-attachments.download', [
            'employee' => $employee,
            'type' => 'discipline',
            'history' => $recordId,
        ]));
    }

    public function test_sk_upload_rejects_disallowed_extension_even_when_content_is_pdf(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
            'file_sk' => UploadedFile::fake()->create('sk-invalid.txt', 512, 'application/pdf'),
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['file_sk']);
    }

    public function test_active_status_is_computed_for_future_today_and_past_end_dates(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $this->actingAs($user);

        $future = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
            'no_sk' => 'SK-FUTURE',
            'tanggal_berakhir' => '2026-06-24',
        ]));
        $today = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
            'no_sk' => 'SK-TODAY',
            'tanggal_berakhir' => '2026-06-23',
        ]));
        $past = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload([
            'no_sk' => 'SK-PAST',
            'tanggal_berakhir' => '2026-06-22',
        ]));

        $future->assertJsonPath('record.is_active', true);
        $today->assertJsonPath('record.is_active', true);
        $past->assertJsonPath('record.is_active', false);
    }

    public function test_pegawai_cannot_access_discipline_records(): void
    {
        $user = User::factory()->pegawai()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);

        $this->getJson("/api/v1/pegawai/{$employee->id}/disiplin")->assertForbidden();
        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload())->assertForbidden();
    }

    public function test_permission_removal_forbids_read_and_create(): void
    {
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $role->permissions()->detach(Permission::whereIn('name', [
            'discipline_records.read',
            'discipline_records.create',
        ])->pluck('id'));
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);

        $this->getJson("/api/v1/pegawai/{$employee->id}/disiplin")->assertForbidden();
        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", $this->validPayload())->assertForbidden();
    }

    public function test_direct_discipline_delete_route_is_absent(): void
    {
        $routeName = 'pegawai.disiplin.destroy';

        $this->assertFalse(Route::has('api.v1.'.$routeName));
    }

    public function test_old_direct_delete_uri_is_unavailable_and_preserves_discipline_record(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $record = DisciplineRecord::create($this->recordPayload($employee));

        $response = $this->actingAs($user)
            ->withSession(['_token' => 'test-token'])
            ->deleteJson(
                "/api/v1/pegawai/{$employee->id}/disiplin/{$record->id}",
                [],
                ['X-CSRF-TOKEN' => 'test-token'],
            );

        $this->assertSame([
            'status_routing' => true,
            'record_tetap_ada' => true,
        ], [
            'status_routing' => in_array($response->status(), [404, 405], true),
            'record_tetap_ada' => DisciplineRecord::whereKey($record->id)->exists(),
        ]);
    }

    public function test_validation_rejects_invalid_discipline_payload(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/disiplin", [
            'jenis_hukuman' => 'Sangat Berat',
            'deskripsi' => '',
            'tanggal_mulai' => '2026-06-23',
            'tanggal_berakhir' => '2026-06-22',
            'no_sk' => '',
            'tanggal_sk' => 'bukan-tanggal',
            'file_sk' => str_repeat('a', 256),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors([
            'jenis_hukuman',
            'deskripsi',
            'tanggal_berakhir',
            'no_sk',
            'tanggal_sk',
            'file_sk',
        ]);
    }

    private function postJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function postWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token', 'Accept' => 'application/json']);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'jenis_hukuman' => 'Ringan',
            'deskripsi' => 'Teguran tertulis karena pelanggaran kedisiplinan.',
            'tanggal_mulai' => '2026-06-01',
            'tanggal_berakhir' => '2026-06-30',
            'no_sk' => 'SK-DIS-001',
            'tanggal_sk' => '2026-05-25',
            'file_sk' => null,
        ], $overrides);
    }

    private function recordPayload(Employee $employee, array $overrides = []): array
    {
        return array_merge($this->validPayload(), [
            'employee_id' => $employee->id,
            'is_active' => true,
        ], $overrides);
    }

    /** @return array<string, array{string}> */
    public static function conflictingDocumentReferenceProvider(): array
    {
        return [
            'path juga direferensikan pegawai lain' => ['pegawai_lain'],
            'path juga direferensikan kategori lain' => ['kategori_lain'],
        ];
    }
}
