<?php

namespace Tests\Feature\Documents;

use App\Actions\Histories\UploadRankHistorySkAction;
use App\Models\Document;
use App\Models\Employee;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SkMirrorHistoryIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Document::STORAGE_DISK);
        $this->seed(RbacSeeder::class);
    }

    public function test_riwayat_dengan_nomor_sk_sama_mendapat_mirror_terpisah(): void
    {
        $employee = Employee::factory()->create();
        $golongan = RefGolongan::create(['kode' => 'III/a', 'nama' => 'Penata Muda']);
        $first = $this->rankHistory($employee->id, $golongan->id, 'SK-SAMA-001');
        $second = $this->rankHistory($employee->id, $golongan->id, 'SK-SAMA-001');

        $action = app(UploadRankHistorySkAction::class);
        $action->execute($employee, $first, UploadedFile::fake()->create('sk-pertama.pdf', 100, 'application/pdf'));
        $action->execute($employee, $second, UploadedFile::fake()->create('sk-kedua.pdf', 100, 'application/pdf'));

        $mirrors = Document::query()
            ->where('employee_id', $employee->id)
            ->where('jenis_dokumen', 'sk_pangkat')
            ->get()
            ->keyBy('history_id');

        $this->assertCount(2, $mirrors);
        $this->assertTrue($mirrors->has($first->id));
        $this->assertTrue($mirrors->has($second->id));
        $this->assertSame($first->fresh()->file_sk, $mirrors[$first->id]->file_path);
        $this->assertSame($second->fresh()->file_sk, $mirrors[$second->id]->file_path);
        $this->assertSame('SK-SAMA-001', $mirrors[$first->id]->nomor_dokumen);
        $this->assertSame('SK-SAMA-001', $mirrors[$second->id]->nomor_dokumen);
    }

    public function test_riwayat_tanpa_nomor_sk_mendapat_mirror_terpisah(): void
    {
        $employee = Employee::factory()->create();
        $golongan = RefGolongan::create(['kode' => 'III/b', 'nama' => 'Penata Muda Tingkat 1']);
        $first = $this->rankHistory($employee->id, $golongan->id, null);
        $second = $this->rankHistory($employee->id, $golongan->id, null);

        $action = app(UploadRankHistorySkAction::class);
        $action->execute($employee, $first, UploadedFile::fake()->create('sk-null-pertama.pdf', 100, 'application/pdf'));
        $action->execute($employee, $second, UploadedFile::fake()->create('sk-null-kedua.pdf', 100, 'application/pdf'));

        $this->assertSame(2, Document::query()
            ->where('employee_id', $employee->id)
            ->where('jenis_dokumen', 'sk_pangkat')
            ->count());
        $this->assertDatabaseHas('documents', [
            'history_id' => $first->id,
            'file_path' => $first->fresh()->file_sk,
        ]);
        $this->assertDatabaseHas('documents', [
            'history_id' => $second->id,
            'file_path' => $second->fresh()->file_sk,
        ]);
        // Null nomor tetap ter-sync.
        $this->assertSame(2, Document::query()->where('employee_id', $employee->id)->where('jenis_dokumen', 'sk_pangkat')->whereNull('nomor_dokumen')->count());
    }

    public function test_unggah_ulang_tidak_menyentuh_mirror_riwayat_lain(): void
    {
        $employee = Employee::factory()->create();
        $golongan = RefGolongan::create(['kode' => 'III/c', 'nama' => 'Penata']);
        $first = $this->rankHistory($employee->id, $golongan->id, 'SK-SAMA-002');
        $second = $this->rankHistory($employee->id, $golongan->id, 'SK-SAMA-002');

        $action = app(UploadRankHistorySkAction::class);
        $action->execute($employee, $first, UploadedFile::fake()->create('sk-awall.pdf', 100, 'application/pdf'));
        $action->execute($employee, $second, UploadedFile::fake()->create('sk-lain.pdf', 100, 'application/pdf'));
        $secondFile = $second->fresh()->file_sk;

        $action->execute($employee, $first->fresh(), UploadedFile::fake()->create('sk-ganti.pdf', 100, 'application/pdf'));

        $this->assertDatabaseHas('documents', [
            'history_id' => $first->id,
            'file_path' => $first->fresh()->file_sk,
        ]);
        $this->assertDatabaseHas('documents', [
            'history_id' => $second->id,
            'file_path' => $secondFile,
        ]);
        $this->assertSame(2, Document::query()
            ->where('employee_id', $employee->id)
            ->where('jenis_dokumen', 'sk_pangkat')
            ->count());
        $this->assertDatabaseHas('documents', ['history_id' => $first->id, 'nomor_dokumen' => 'SK-SAMA-002']);
        $this->assertDatabaseHas('documents', ['history_id' => $second->id, 'nomor_dokumen' => 'SK-SAMA-002']);
    }

    private function rankHistory(string $employeeId, string $golonganId, ?string $noSk): RankHistory
    {
        return RankHistory::create([
            'employee_id' => $employeeId,
            'golongan_id' => $golonganId,
            'tmt_pangkat' => '2020-01-01',
            'no_sk' => $noSk,
            'tanggal_sk' => '2019-12-20',
            'file_sk' => null,
            'is_latest' => false,
        ]);
    }
}
