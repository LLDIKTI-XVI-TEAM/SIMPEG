<?php

namespace Tests\Feature;

use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\RefJenjangPendidikan;
use App\Models\RefProgramStudi;
use App\Support\ProgramStudi\BackfillProgramStudiReferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramStudiMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_snapshots_are_backfilled_to_one_normalized_program_studi_reference(): void
    {
        $employee = Employee::factory()->create([
            'prodi_pendidikan_terakhir' => ' Teknik   Informatika ',
        ]);
        $history = EducationHistory::create([
            'employee_id' => $employee->id,
            'jenjang_id' => RefJenjangPendidikan::create(['nama' => 'D4 / S1', 'urutan' => 6])->id,
            'nama_institusi' => 'Universitas Contoh',
            'jurusan' => 'teknik informatika',
            'tahun_lulus' => 2010,
            'no_ijazah' => 'IJZ-MIGRATION-001',
        ]);
        $employee->updateQuietly(['prodi_pendidikan_terakhir' => ' Teknik   Informatika ']);

        app(BackfillProgramStudiReferences::class)->execute();

        $this->assertDatabaseCount('ref_program_studi', 1);
        $reference = RefProgramStudi::firstOrFail();
        $this->assertSame('teknik informatika', mb_strtolower($reference->nama));
        $this->assertSame($reference->id, $employee->fresh()->program_studi_id);
        $this->assertSame($reference->id, $history->fresh()->program_studi_id);
    }
}
