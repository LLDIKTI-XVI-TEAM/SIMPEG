<?php

namespace Tests\Unit;

use App\Models\Appointment;
use App\Models\Document;
use App\Models\Employee;
use App\Models\RankHistory;
use App\Models\RefJenisPegawai;
use App\Services\EmployeeDocumentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeDocumentStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeDocumentStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::STORAGE_DISK);
        $this->service = app(EmployeeDocumentStatusService::class);
    }

    private function createPnsEmployee(): Employee
    {
        $pns = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);

        return Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);
    }

    // -------------------------------------------------------------------------
    // sk_pangkat: kasus inti bug
    // -------------------------------------------------------------------------

    /**
     * Skenario 1 — riwayat terbaru punya file valid → tersedia.
     */
    public function test_sk_pangkat_tersedia_ketika_riwayat_terbaru_punya_file_valid(): void
    {
        $employee = $this->createPnsEmployee();

        $filePath = 'rank/sk-pangkat-baru.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($filePath, 'isi SK');

        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2023-01-01',
            'no_sk' => 'SK/LAMA/2023',
            'file_sk' => 'rank/sk-pangkat-lama.pdf',  // file ini tidak diupload ke storage
            'is_latest' => false,
        ]);

        // file lama sengaja TIDAK diupload — membuktikan file lama tidak dinilai
        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2024-01-01',
            'no_sk' => 'SK/BARU/2024',
            'file_sk' => $filePath,
            'is_latest' => true,
        ]);

        $result = $this->service->summarize($employee->fresh());
        $skPangkat = collect($result['required_sks'])->firstWhere('jenis', 'sk_pangkat');

        $this->assertSame('tersedia', $skPangkat['status']);
    }

    /**
     * Skenario 2 — riwayat terbaru file_sk=null, riwayat lama punya file valid → perlu_perbaikan.
     *
     * Ini adalah bug utama yang diperbaiki: sebelumnya dilaporkan tersedia.
     */
    public function test_sk_pangkat_perlu_perbaikan_ketika_riwayat_terbaru_file_sk_null_meski_riwayat_lama_valid(): void
    {
        $employee = $this->createPnsEmployee();

        $filePathLama = 'rank/sk-pangkat-lama.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($filePathLama, 'isi SK lama');

        // Riwayat lama — file valid
        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2022-01-01',
            'no_sk' => 'SK/LAMA/2022',
            'file_sk' => $filePathLama,
            'is_latest' => false,
        ]);

        // Riwayat terbaru — RUSAK: file_sk null
        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2024-01-01',
            'no_sk' => 'SK/BARU/2024',
            'file_sk' => null,
            'is_latest' => true,
        ]);

        $result = $this->service->summarize($employee->fresh());
        $skPangkat = collect($result['required_sks'])->firstWhere('jenis', 'sk_pangkat');

        $this->assertSame('perlu_perbaikan', $skPangkat['status']);
        $this->assertStringContainsString('diunggah', $skPangkat['status_label']);
    }

    /**
     * Skenario 3 — riwayat terbaru file_sk ada tapi file fisik hilang dari storage,
     * riwayat lama masih punya file → perlu_perbaikan.
     */
    public function test_sk_pangkat_perlu_perbaikan_ketika_file_fisik_riwayat_terbaru_hilang_meski_riwayat_lama_valid(): void
    {
        $employee = $this->createPnsEmployee();

        $filePathLama = 'rank/sk-pangkat-lama.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($filePathLama, 'isi SK lama');

        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2022-01-01',
            'no_sk' => 'SK/LAMA/2022',
            'file_sk' => $filePathLama,
            'is_latest' => false,
        ]);

        // Riwayat terbaru — path ada tapi file TIDAK ada di storage
        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2024-01-01',
            'no_sk' => 'SK/BARU/2024',
            'file_sk' => 'rank/sk-pangkat-baru-hilang.pdf',
            'is_latest' => true,
        ]);

        $result = $this->service->summarize($employee->fresh());
        $skPangkat = collect($result['required_sks'])->firstWhere('jenis', 'sk_pangkat');

        $this->assertSame('perlu_perbaikan', $skPangkat['status']);
        $this->assertStringContainsString('storage', $skPangkat['status_label']);
    }

    /** Label kerusakan harus mengikuti SK kanonis, bukan file_sk kosong pada riwayat lama. */
    public function test_sk_pangkat_file_hilang_pada_riwayat_kanonis_tidak_dilabeli_belum_diunggah_dari_riwayat_lama(): void
    {
        $employee = $this->createPnsEmployee();

        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2022-01-01',
            'no_sk' => 'SK/LAMA/2022',
            'file_sk' => null,
            'is_latest' => false,
        ]);

        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2024-01-01',
            'no_sk' => 'SK/BARU/2024',
            'file_sk' => 'rank/sk-pangkat-baru-hilang.pdf',
            'is_latest' => true,
        ]);

        $result = $this->service->summarize($employee->fresh());
        $skPangkat = collect($result['required_sks'])->firstWhere('jenis', 'sk_pangkat');

        $this->assertSame('perlu_perbaikan', $skPangkat['status']);
        $this->assertSame('File tidak ditemukan di storage', $skPangkat['status_label']);
    }

    // -------------------------------------------------------------------------
    // Kasus dasar
    // -------------------------------------------------------------------------

    /**
     * Skenario 4 — tidak ada riwayat sama sekali → belum_ada.
     */
    public function test_sk_pangkat_belum_ada_ketika_tidak_ada_riwayat(): void
    {
        $employee = $this->createPnsEmployee();

        $result = $this->service->summarize($employee->fresh());
        $skPangkat = collect($result['required_sks'])->firstWhere('jenis', 'sk_pangkat');

        $this->assertSame('belum_ada', $skPangkat['status']);
    }

    /**
     * Skenario 5 — semua empat kategori tersedia → status_kelengkapan lengkap & is_lengkap true.
     */
    public function test_status_kelengkapan_lengkap_ketika_semua_empat_sk_tersedia(): void
    {
        $employee = $this->createPnsEmployee();

        foreach (['sk_pengangkatan', 'sk_pangkat', 'sk_jabatan', 'sk_kgb'] as $jenis) {
            $path = "sk/{$jenis}.pdf";
            Storage::disk(Document::STORAGE_DISK)->put($path, 'isi');

            Document::create([
                'employee_id' => $employee->id,
                'jenis_dokumen' => $jenis,
                'nama_dokumen' => strtoupper($jenis),
                'file_path' => $path,
            ]);
        }

        $result = $this->service->summarize($employee->fresh());

        $this->assertSame('lengkap', $result['status_kelengkapan']);
        $this->assertTrue($result['is_lengkap']);
    }

    /**
     * Skenario 6 — satu kategori (sk_pangkat) perlu_perbaikan akibat riwayat terbaru rusak
     * → status_kelengkapan keseluruhan adalah perlu_perbaikan, is_lengkap false.
     */
    public function test_status_kelengkapan_perlu_perbaikan_ketika_ada_satu_kategori_riwayat_terbaru_rusak(): void
    {
        $employee = $this->createPnsEmployee();

        // Tiga kategori lain: lengkap via arsip dokumen
        foreach (['sk_pengangkatan', 'sk_jabatan', 'sk_kgb'] as $jenis) {
            $path = "sk/{$jenis}.pdf";
            Storage::disk(Document::STORAGE_DISK)->put($path, 'isi');

            Document::create([
                'employee_id' => $employee->id,
                'jenis_dokumen' => $jenis,
                'nama_dokumen' => strtoupper($jenis),
                'file_path' => $path,
            ]);
        }

        // sk_pangkat: riwayat lama valid, riwayat terbaru rusak
        $filePathLama = 'rank/sk-pangkat-lama.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($filePathLama, 'isi SK lama');

        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2020-01-01',
            'file_sk' => $filePathLama,
            'is_latest' => false,
        ]);
        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2024-01-01',
            'file_sk' => null,          // RUSAK
            'is_latest' => true,
        ]);

        $result = $this->service->summarize($employee->fresh());

        $this->assertSame('perlu_perbaikan', $result['status_kelengkapan']);
        $this->assertFalse($result['is_lengkap']);
        $this->assertSame(1, $result['perlu_perbaikan_count']);
    }

    // -------------------------------------------------------------------------
    // Appointment (sk_pengangkatan) — tanpa is_latest, urut tmt_pengangkatan DESC
    // -------------------------------------------------------------------------

    /**
     * Skenario 7 — appointment terbaru (tmt_pengangkatan lebih tinggi) rusak → perlu_perbaikan.
     */
    public function test_sk_pengangkatan_perlu_perbaikan_ketika_appointment_terbaru_rusak(): void
    {
        $employee = $this->createPnsEmployee();

        $filePathLama = 'appointments/sk-pengangkatan-lama.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($filePathLama, 'isi');

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2015-01-01',
            'file_sk' => $filePathLama,
        ]);

        // Appointment terbaru — file_sk null
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'file_sk' => null,
        ]);

        $result = $this->service->summarize($employee->fresh());
        $skPengangkatan = collect($result['required_sks'])->firstWhere('jenis', 'sk_pengangkatan');

        $this->assertSame('perlu_perbaikan', $skPengangkatan['status']);
    }

    /**
     * Skenario 8 - file fisik ada tetapi path direferensikan metadata arsip
     * milik pegawai/kategori lain. Scoped validator wajib menolak path tersebut
     * sehingga status tidak boleh tersedia dan pegawai tidak boleh dinilai lengkap.
     */
    public function test_path_dengan_metadata_arsip_bertentangan_tidak_dianggap_tersedia(): void
    {
        $employee = $this->createPnsEmployee();
        $employeeLain = $this->createPnsEmployee();

        $filePath = 'rank/sk-diperebutkan.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($filePath, 'isi SK');

        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2024-01-01',
            'no_sk' => 'SK/BARU/2024',
            'file_sk' => $filePath,
            'is_latest' => true,
        ]);

        // Path yang sama diklaim arsip pegawai lain pada kategori berbeda.
        Document::create([
            'employee_id' => $employeeLain->id,
            'jenis_dokumen' => 'sk_jabatan',
            'nama_dokumen' => 'SK Jabatan Pegawai Lain',
            'file_path' => $filePath,
        ]);

        $result = $this->service->summarize($employee->fresh());
        $skPangkat = collect($result['required_sks'])->firstWhere('jenis', 'sk_pangkat');

        $this->assertSame('perlu_perbaikan', $skPangkat['status']);
        $this->assertNull($skPangkat['file_url']);
        $this->assertNotSame('lengkap', $result['status_kelengkapan']);
        $this->assertFalse($result['is_lengkap']);
    }

    /**
     * Skenario 9 - N+1 optimization check.
     */
    public function test_summarize_does_not_execute_n_plus_1_queries(): void
    {
        $employee = $this->createPnsEmployee();
        // pre-load relations
        $employee->load(['appointments', 'rankHistories', 'positionHistories', 'salaryHistories', 'documents', 'jenisPegawai']);

        DB::enableQueryLog();

        $this->service->summarize($employee);

        $queries = DB::getQueryLog();
        $this->assertCount(0, $queries, 'Expected no database queries to be executed by summarize() when relations are pre-loaded.');

        DB::disableQueryLog();
    }

    /**
     * Matriks kelengkapan 4 SK hanya berlaku untuk PNS; CPNS/PPPK/jenis lain
     * berstatus tidak_wajib dengan total_wajib = 0 dan is_lengkap = true.
     */
    public function test_non_pns_employee_is_tidak_wajib(): void
    {
        $pppk = RefJenisPegawai::firstOrCreate(['nama' => 'PPPK']);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pppk->id]);

        $result = $this->service->summarize($employee->fresh());

        $this->assertSame('tidak_wajib', $result['status_kelengkapan']);
        $this->assertTrue($result['is_lengkap']);
        $this->assertSame(0, $result['total_wajib']);
        $this->assertSame(0, $result['tersedia_count']);
        $this->assertCount(4, $result['required_sks']);
        foreach ($result['required_sks'] as $sk) {
            $this->assertSame('tidak_wajib', $sk['status']);
        }
    }

    /**
     * Tie-breaker appointment kanonis — ketika ada multiple appointments dengan
     * tmt_pengangkatan yang sama, created_at yang lebih baru harus dipilih sebagai kanonis.
     * Konsisten dengan ReplaceAppointmentSkAction yang memakai orderByDesc('tmt_pengangkatan')->orderByDesc('created_at').
     */
    public function test_appointment_kanonis_dipilih_berdasarkan_tmt_lalu_created_at(): void
    {
        $employee = $this->createPnsEmployee();

        $filePathLama = 'appointments/sk-pengangkatan-lama.pdf';
        $filePathBaru = 'appointments/sk-pengangkatan-baru.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($filePathLama, 'isi lama');
        Storage::disk(Document::STORAGE_DISK)->put($filePathBaru, 'isi baru');

        // Appointment lama dengan TMT yang sama tapi created_at lebih awal
        $appointmentLama = Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK/LAMA/2020',
            'file_sk' => $filePathLama,
        ]);
        DB::table('appointments')->where('id', $appointmentLama->id)->update(['created_at' => now()->subDays(2)]);

        // Appointment baru dengan TMT yang sama tapi created_at lebih baru
        $appointmentBaru = Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK/BARU/2020',
            'file_sk' => $filePathBaru,
        ]);
        DB::table('appointments')->where('id', $appointmentBaru->id)->update(['created_at' => now()]);

        $result = $this->service->summarize($employee->fresh());
        $skPengangkatan = collect($result['required_sks'])->firstWhere('jenis', 'sk_pengangkatan');

        // Harus memilih appointment yang created_at lebih baru sebagai kanonis
        $this->assertSame('tersedia', $skPengangkatan['status']);
        $this->assertSame($filePathBaru, $skPengangkatan['file_path']);
    }
}
