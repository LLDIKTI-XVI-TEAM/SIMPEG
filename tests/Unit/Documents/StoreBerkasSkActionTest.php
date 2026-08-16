<?php

namespace Tests\Unit\Documents;

use App\Actions\Documents\StoreBerkasSkAction;
use App\Models\Appointment;
use App\Models\Document;
use App\Models\Employee;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefUnitKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreBerkasSkActionTest extends TestCase
{
    use RefreshDatabase;

    private StoreBerkasSkAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Document::STORAGE_DISK);
        $this->action = app(StoreBerkasSkAction::class);
    }

    public function test_sk_pangkat_appends_history_and_keeps_latest_by_tmt(): void
    {
        $employee = Employee::factory()->create();
        $golonganLama = RefGolongan::create(['kode' => 'III/a', 'nama' => 'Penata Muda', 'urutan' => 1, 'is_active' => true]);
        $golonganBaru = RefGolongan::create(['kode' => 'III/b', 'nama' => 'Penata Muda Tk.I', 'urutan' => 2, 'is_active' => true]);

        $this->action->execute($employee, $this->pangkatPayload($golonganLama->id, '2020-01-01', 'SK/PANGKAT/2020'), new Request);
        $this->action->execute($employee, $this->pangkatPayload($golonganBaru->id, '2024-01-01', 'SK/PANGKAT/2024'), new Request);

        $this->assertSame(2, $employee->rankHistories()->count());
        $this->assertSame(2, $employee->documents()->where('jenis_dokumen', 'sk_pangkat')->count());

        $latest = $employee->rankHistories()->where('is_latest', true)->sole();
        $this->assertSame($golonganBaru->id, $latest->golongan_id);
        $this->assertSame('2024-01-01', $latest->tmt_pangkat?->toDateString());
        $this->assertSame(1, $employee->rankHistories()->where('is_latest', false)->count());
    }

    public function test_sk_kgb_appends_without_replacing_old_history(): void
    {
        $employee = Employee::factory()->create();

        $this->action->execute($employee, $this->kgbPayload('2021-02-01', 4000000, 'SK/KGB/2021'), new Request);
        $this->action->execute($employee, $this->kgbPayload('2023-02-01', 4500000, 'SK/KGB/2023'), new Request);

        $this->assertSame(2, $employee->salaryHistories()->count());
        $latest = $employee->salaryHistories()->where('is_latest', true)->sole();
        $this->assertSame('4500000.00', (string) $latest->gaji_pokok);
        $this->assertSame('2023-02-01', $latest->tmt_kgb?->toDateString());
    }

    public function test_sk_jabatan_appends_new_position_history(): void
    {
        $employee = Employee::factory()->create();
        $unit = RefUnitKerja::create(['nama' => 'Bagian Umum', 'jenis_unit' => 'bagian', 'level' => 2, 'is_active' => true]);
        $jabatanLama = RefJabatan::create(['nama' => 'Analis', 'is_active' => true]);
        $jabatanBaru = RefJabatan::create(['nama' => 'Kepala Subbagian', 'is_active' => true]);

        $this->action->execute($employee, $this->jabatanPayload($jabatanLama->id, $unit->id, '2019-03-01', 'SK/JAB/2019'), new Request);
        $this->action->execute($employee, $this->jabatanPayload($jabatanBaru->id, $unit->id, '2022-03-01', 'SK/JAB/2022'), new Request);

        $this->assertSame(2, $employee->positionHistories()->count());
        $latest = $employee->positionHistories()->where('is_latest', true)->sole();
        $this->assertSame($jabatanBaru->id, $latest->jabatan_id);
        $this->assertSame('Kepala Subbagian', $latest->nama_jabatan);
    }

    public function test_sk_pengangkatan_replaces_single_appointment_and_document(): void
    {
        $employee = Employee::factory()->create();
        RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        RefJenisPegawai::firstOrCreate(['nama' => 'PPPK']);

        $this->action->execute($employee, $this->pengangkatanPayload('PNS', '2018-01-01', 'SK/ANGKAT/2018'), new Request);
        $firstPath = Appointment::query()->where('employee_id', $employee->id)->value('file_sk');
        $this->assertNotNull($firstPath);
        Storage::disk(Document::STORAGE_DISK)->assertExists($firstPath);

        $this->action->execute($employee, $this->pengangkatanPayload('PPPK', '2024-06-01', 'SK/ANGKAT/2024'), new Request);

        $this->assertSame(1, $employee->appointments()->count());
        $this->assertSame(1, $employee->documents()->where('jenis_dokumen', 'sk_pengangkatan')->count());

        $appointment = $employee->appointments()->first();
        $document = $employee->documents()->where('jenis_dokumen', 'sk_pengangkatan')->first();

        $this->assertSame('PPPK', $appointment?->jenis_pengangkatan);
        $this->assertSame('SK/ANGKAT/2024', $appointment?->no_sk);
        $this->assertSame('2024-06-01', $appointment?->tmt_pengangkatan?->toDateString());
        $this->assertSame($appointment?->file_sk, $document?->file_path);
        $this->assertSame('SK/ANGKAT/2024', $document?->nomor_dokumen);
        Storage::disk(Document::STORAGE_DISK)->assertMissing($firstPath);
        Storage::disk(Document::STORAGE_DISK)->assertExists((string) $appointment?->file_sk);
    }

    public function test_unknown_category_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->action->execute(Employee::factory()->create(), [
            'kategori_dokumen' => 'ktp_kk',
            'no_sk' => 'X',
            'tanggal_sk' => '2024-01-01',
            'file_sk' => $this->fakeSk(),
        ], new Request);
    }

    /**
     * @return array<string, mixed>
     */
    private function pangkatPayload(string $golonganId, string $tmt, string $noSk): array
    {
        return [
            'kategori_dokumen' => 'sk_pangkat',
            'golongan_id' => $golonganId,
            'tmt_pangkat' => $tmt,
            'no_sk' => $noSk,
            'tanggal_sk' => $tmt,
            'file_sk' => $this->fakeSk(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kgbPayload(string $tmt, int $gaji, string $noSk): array
    {
        return [
            'kategori_dokumen' => 'sk_kgb',
            'gaji_pokok' => $gaji,
            'tmt_kgb' => $tmt,
            'no_sk' => $noSk,
            'tanggal_sk' => $tmt,
            'file_sk' => $this->fakeSk(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jabatanPayload(string $jabatanId, string $unitId, string $tmt, string $noSk): array
    {
        return [
            'kategori_dokumen' => 'sk_jabatan',
            'jabatan_id' => $jabatanId,
            'unit_kerja_id' => $unitId,
            'tmt_jabatan' => $tmt,
            'no_sk' => $noSk,
            'tanggal_sk' => $tmt,
            'file_sk' => $this->fakeSk(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pengangkatanPayload(string $jenis, string $tmt, string $noSk): array
    {
        return [
            'kategori_dokumen' => 'sk_pengangkatan',
            'jenis_pengangkatan' => $jenis,
            'tmt_pengangkatan' => $tmt,
            'no_sk' => $noSk,
            'tanggal_sk' => $tmt,
            'file_sk' => $this->fakeSk(),
        ];
    }

    private function fakeSk(): UploadedFile
    {
        return UploadedFile::fake()->create('sk.pdf', 120, 'application/pdf');
    }

    public function test_replace_pengangkatan_mempertahankan_file_lama_yang_direferensikan_riwayat_lain(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = Employee::factory()->create();
        $oldPath = 'sk/pengangkatan-lama.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($oldPath, 'SK lama');

        $employee->appointments()->create([
            'jenis_pengangkatan' => 'CPNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK/ANGKAT/LAMA',
            'tanggal_sk' => '2019-12-01',
            'file_sk' => $oldPath,
        ]);
        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pengangkatan',
            'nama_dokumen' => 'SK Pengangkatan Lama',
            'file_path' => $oldPath,
        ]);
        // Path lama ternyata juga dipakai riwayat pangkat (data legacy).
        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => RefGolongan::firstOrCreate(['kode' => 'III/a'], ['nama' => 'Penata Muda', 'urutan' => 1, 'is_active' => true])->id,
            'tmt_pangkat' => '2021-01-01',
            'no_sk' => 'SK/PANGKAT/2021',
            'tanggal_sk' => '2020-12-01',
            'file_sk' => $oldPath,
            'is_latest' => true,
        ]);

        $action = app(StoreBerkasSkAction::class);
        $action->execute($employee, [
            'kategori_dokumen' => 'sk_pengangkatan',
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2022-01-01',
            'no_sk' => 'SK/ANGKAT/BARU',
            'tanggal_sk' => '2021-12-01',
            'file_sk' => UploadedFile::fake()->create('sk-baru.pdf', 80, 'application/pdf'),
        ], request());

        Storage::disk(Document::STORAGE_DISK)->assertExists($oldPath);
    }
}
