<?php

namespace Tests\Unit\Documents;

use App\Actions\Documents\StoreBerkasSkAction;
use App\Models\Appointment;
use App\Models\Document;
use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefJenjangPendidikan;
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

    public function test_replace_pengangkatan_tidak_menghapus_file_baru_jika_transaksi_berhasil(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = Employee::factory()->create();

        $action = app(StoreBerkasSkAction::class);
        $document = $action->execute($employee, [
            'kategori_dokumen' => 'sk_pengangkatan',
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2024-01-01',
            'no_sk' => 'SK/ANGKAT/SUCCESS',
            'tanggal_sk' => '2023-12-01',
            'file_sk' => UploadedFile::fake()->create('sk-sukses.pdf', 100, 'application/pdf'),
        ], request());

        // File baru harus tetap ada di storage setelah transaksi sukses
        $this->assertNotNull($document->file_path);
        Storage::disk(Document::STORAGE_DISK)->assertExists((string) $document->file_path);
    }

    public function test_replace_pengangkatan_mempertahankan_file_lama_yang_dipakai_riwayat_pendidikan(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = Employee::factory()->create();
        $oldPath = 'shared/ijazah-pengangkatan.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($oldPath, 'SK lama');

        $employee->appointments()->create([
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK/ANGKAT/LAMA',
            'tanggal_sk' => '2019-12-01',
            'file_sk' => $oldPath,
        ]);

        $jenjang = RefJenjangPendidikan::firstOrCreate(['nama' => 'S1'], ['urutan' => 1, 'is_active' => true]);

        // Path yang sama dipakai oleh riwayat pendidikan (data legacy)
        EducationHistory::create([
            'employee_id' => $employee->id,
            'jenjang_id' => $jenjang->id,
            'nama_institusi' => 'Universitas Test',
            'jurusan' => 'Teknik Informatika',
            'tahun_lulus' => 2019,
            'file_ijazah' => $oldPath,
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

        // File lama harus tetap ada karena masih dipakai riwayat pendidikan
        Storage::disk(Document::STORAGE_DISK)->assertExists($oldPath);
    }

    public function test_replace_pengangkatan_cleanup_old_document_path_yang_berbeda_dari_appointment(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = Employee::factory()->create();

        // appointment.file_sk = A
        $appointmentPath = 'sk/appointment-lama.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($appointmentPath, 'appointment lama');
        $employee->appointments()->create([
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK/ANGKAT/A',
            'tanggal_sk' => '2019-12-01',
            'file_sk' => $appointmentPath,
        ]);

        // Document sk_pengangkatan terbaru memakai path B yang TIDAK cocok dengan A
        // (fallback ke latest dokumen, bukan path appointment).
        $documentPath = 'sk/dokumen-lama.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($documentPath, 'dokumen lama');
        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pengangkatan',
            'nama_dokumen' => 'SK Pengangkatan Lama',
            'file_path' => $documentPath,
        ]);

        $action = app(StoreBerkasSkAction::class);
        $document = $action->execute($employee, [
            'kategori_dokumen' => 'sk_pengangkatan',
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2022-01-01',
            'no_sk' => 'SK/ANGKAT/BARU',
            'tanggal_sk' => '2021-12-01',
            'file_sk' => UploadedFile::fake()->create('sk-baru.pdf', 80, 'application/pdf'),
        ], request());

        // Appointment & Document menunjuk ke file baru.
        $this->assertSame($document->file_path, $employee->appointments()->first()?->file_sk);
        $this->assertSame($document->file_path, $document->fresh()->file_path);

        // Kedua path lama (appointment A dan dokumen B) tidak lagi direferensikan → dihapus.
        Storage::disk(Document::STORAGE_DISK)->assertMissing($appointmentPath);
        Storage::disk(Document::STORAGE_DISK)->assertMissing($documentPath);
        Storage::disk(Document::STORAGE_DISK)->assertExists((string) $document->file_path);
    }

    /**
     * Setelah koreksi TMT mengubah urutan kanonis appointment, dokumen arsip dan
     * jenis_pegawai harus mengikuti appointment kanonis FINAL (bukan appointment yang
     * diunggah) agar snapshot, dokumen aktif, dan status kelengkapan berasal dari
     * satu sumber. Berkas SK tetap terkait record yang metadata-nya berasal dari unggahan.
     */
    public function test_replace_pengangkatan_re_resolves_canonical_appointment_after_tmt_change(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = Employee::factory()->create();
        $pns = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $pppk = RefJenisPegawai::firstOrCreate(['nama' => 'PPPK']);
        $employee->update(['jenis_pegawai_id' => $pns->id]);
        $lama = $employee->appointments()->create([
            'jenis_pengangkatan' => 'PPPK',
            'tmt_pengangkatan' => '2023-01-01',
            'no_sk' => 'SK/LAMA/2023',
            'tanggal_sk' => '2022-12-15',
            'file_sk' => 'appointments/lama.pdf',
        ]);
        $baru = $employee->appointments()->create([
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2024-06-01',
            'no_sk' => 'SK/BARU/2024',
            'tanggal_sk' => '2024-05-20',
            'file_sk' => null,
        ]);
        Storage::disk(Document::STORAGE_DISK)->put('appointments/lama.pdf', 'lama');

        // Replace appointment BARU (2024) dengan TMT dikoreksi ke 2022 → appointment LAMA (2023)
        // menjadi kanonis menurut aturan TMT (terbaru = 2023).
        $response = $this->action->execute($employee, [
            'kategori_dokumen' => 'sk_pengangkatan',
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2022-07-01',
            'no_sk' => 'SK/KOREKSI/2022',
            'tanggal_sk' => '2022-06-20',
            'file_sk' => UploadedFile::fake()->create('sk-koreksi.pdf', 100, 'application/pdf'),
        ], new Request);

        $this->assertInstanceOf(Document::class, $response);
        $this->assertSame(2, $employee->appointments()->count());

        // Berkas SK baru tetap di appointment BARU (yang metadata-nya diunggah).
        $baruFresh = $baru->fresh();
        $this->assertSame('SK/KOREKSI/2022', $baruFresh->no_sk);
        $this->assertSame('2022-07-01', $baruFresh->tmt_pengangkatan?->toDateString());
        $this->assertNotNull($baruFresh->file_sk);

        // Appointment LAMA (2023) kini kanonis; arsip mengikuti kanonis final.
        $kanonis = $employee->appointments()
            ->orderByDesc('tmt_pengangkatan')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
        $this->assertSame($lama->id, $kanonis->id);

        $arsip = Document::query()
            ->where('employee_id', $employee->id)
            ->where('jenis_dokumen', 'sk_pengangkatan')
            ->orderByDesc('tanggal_dokumen')
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($arsip);

        // jenis_pegawai mengikuti appointment kanonis FINAL (lama = PPPK).
        $this->assertSame('PPPK', strtoupper((string) $employee->refresh()->jenisPegawai?->nama));
    }
}
