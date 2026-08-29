<?php

namespace Tests\Feature;

use App\Actions\Documents\StoreDocumentAction;
use App\Actions\Employees\CreateEmployeeAction;
use App\Models\Document;
use App\Models\Employee;
use App\Models\RefStatusPegawai;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeStatusWriterBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceSeeder::class, RbacSeeder::class]);
        Storage::fake(Document::STORAGE_DISK);
    }

    #[DataProvider('httpLifecycleFields')]
    public function test_api_create_menolak_field_lifecycle(string $field, mixed $value): void
    {
        $actor = User::factory()->superAdmin()->create();

        $this->actingAs($actor)
            ->postJson(route('api.v1.pegawai.store'), [
                'nama_lengkap' => 'Pegawai API Lifecycle',
                $field => $value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseMissing('employees', ['nama_lengkap' => 'Pegawai API Lifecycle']);
    }

    #[DataProvider('httpLifecycleFields')]
    public function test_web_create_menolak_field_lifecycle(string $field, mixed $value): void
    {
        $actor = User::factory()->adminKepegawaian()->create();

        $this->actingAs($actor)
            ->post(route('pegawai.store'), [
                'nama_lengkap' => 'Pegawai Web Lifecycle',
                $field => $value,
            ])
            ->assertSessionHasErrors([$field]);

        $this->assertDatabaseMissing('employees', ['nama_lengkap' => 'Pegawai Web Lifecycle']);
    }

    public function test_form_create_tidak_menawarkan_status_lifecycle(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.create'))
            ->assertOk()
            ->assertDontSee('name="status_aktif"', false)
            ->assertDontSee('name="status_pegawai_id"', false)
            ->assertDontSee('name="status_keterangan"', false);
    }

    public function test_create_biasa_selalu_memakai_aktif_tanpa_riwayat_atau_tanggal_lifecycle(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $aktif = RefStatusPegawai::query()->where('kode', 'AKTIF')->firstOrFail();

        $response = $this->actingAs($actor)->postJson(route('api.v1.pegawai.store'), [
            'nama_lengkap' => 'Pegawai Baru Aktif',
        ]);

        $response->assertCreated();
        $employee = Employee::query()->where('nama_lengkap', 'Pegawai Baru Aktif')->firstOrFail();

        $this->assertSame($aktif->id, $employee->status_pegawai_id);
        $this->assertSame($aktif->nama, $employee->status_aktif);
        $this->assertNull($employee->status_keterangan);
        $this->assertNull($employee->status_tanggal);
        $this->assertNull($employee->status_note);
        $this->assertNull($employee->status_berkas_path);
        $this->assertNull($employee->status_nomor_berkas);
        $this->assertSame(0, $employee->statusHistories()->count());
    }

    #[DataProvider('directLifecycleFields')]
    public function test_action_create_tidak_dapat_di_override_caller_langsung(string $field, mixed $value): void
    {
        $request = Request::create('/pegawai', 'POST');

        try {
            app(CreateEmployeeAction::class)->execute([
                'nama_lengkap' => 'Pegawai Direct Lifecycle',
                $field => $value,
            ], $request);
            $this->fail('CreateEmployeeAction wajib menolak field lifecycle dari caller langsung.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }

        $this->assertDatabaseMissing('employees', ['nama_lengkap' => 'Pegawai Direct Lifecycle']);
    }

    #[DataProvider('statusDocumentCategories')]
    public function test_sk_status_pada_create_hanya_disimpan_sebagai_dokumen(string $label, string $category): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $aktif = RefStatusPegawai::query()->where('kode', 'AKTIF')->firstOrFail();

        $this->actingAs($actor)
            ->post(route('pegawai.store'), [
                'nama_lengkap' => 'Pegawai Dengan '.$label,
                'berkas_lainnya_jenis' => $label,
                'berkas_lainnya_tanggal' => '2026-08-27',
                'file_berkas_lainnya' => UploadedFile::fake()->create('status.pdf', 64, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('data-pegawai'));

        $employee = Employee::query()->where('nama_lengkap', 'Pegawai Dengan '.$label)->firstOrFail();
        $this->assertSame($aktif->id, $employee->status_pegawai_id);
        $this->assertNull($employee->status_tanggal);
        $this->assertSame(0, $employee->statusHistories()->count());
        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => $category,
        ]);
    }

    #[DataProvider('storeDocumentCategories')]
    public function test_store_document_tidak_memutasi_status_pegawai(string $category): void
    {
        $aktif = RefStatusPegawai::query()->where('kode', 'AKTIF')->firstOrFail();
        $employee = Employee::factory()->create([
            'status_pegawai_id' => $aktif->id,
            'status_aktif' => $aktif->nama,
        ]);

        app(StoreDocumentAction::class)->execute([
            'pegawai_id' => $employee->id,
            'kategori_dokumen' => $category,
            'nama_dokumen' => 'Dokumen status dormant',
            'nomor_dokumen' => 'SK-DORMANT-001',
            'tanggal_terbit' => '2026-08-27',
        ], UploadedFile::fake()->create('status.pdf', 64, 'application/pdf'));

        $employee->refresh();
        $this->assertSame($aktif->id, $employee->status_pegawai_id);
        $this->assertSame($aktif->nama, $employee->status_aktif);
        $this->assertNull($employee->status_tanggal);
        $this->assertSame(0, $employee->statusHistories()->count());
        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => $category,
        ]);
    }

    /** @return array<string, array{string, mixed}> */
    public static function httpLifecycleFields(): array
    {
        return [
            'snapshot nama status' => ['status_aktif', 'Pensiun'],
            'referensi status' => ['status_pegawai_id', '11111111-1111-4111-8111-111111111111'],
            'alasan status' => ['status_keterangan', 'Bypass lifecycle dari create.'],
            'tanggal efektif' => ['status_tanggal', '2026-08-27'],
            'pesan akun' => ['status_note', 'Pesan bypass lifecycle.'],
            'path berkas status' => ['status_berkas_path', 'private/status.pdf'],
            'nomor berkas status' => ['status_nomor_berkas', 'SK-BYPASS-001'],
        ];
    }

    /** @return array<string, array{string, mixed}> */
    public static function directLifecycleFields(): array
    {
        return [
            ...self::httpLifecycleFields(),
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function statusDocumentCategories(): array
    {
        return [
            'SK Mutasi' => ['SK Mutasi', 'sk_mutasi'],
            'SK Pensiun' => ['SK Pensiun', 'sk_pensiun'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function storeDocumentCategories(): array
    {
        return [
            'SK mutasi' => ['sk_mutasi'],
            'SK pensiun' => ['sk_pensiun'],
        ];
    }
}
