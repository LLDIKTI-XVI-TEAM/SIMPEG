<?php

namespace Tests\Feature;

use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\RefJenjangPendidikan;
use App\Models\RefProgramStudi;
use App\Support\ProgramStudi\BackfillProgramStudiReferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_name_normalization_relinks_and_deletes_duplicates_before_renaming_the_canonical_reference(): void
    {
        DB::statement('DROP INDEX IF EXISTS ref_program_studi_normalized_name_unique');

        $now = now();
        $canonicalId = '00000000-0000-4000-8000-000000000001';
        $duplicateId = '00000000-0000-4000-8000-000000000002';
        DB::table('ref_program_studi')->insert([
            [
                'id' => $canonicalId,
                'nama' => 'Teknik   Informatika',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $duplicateId,
                'nama' => 'Teknik Informatika',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $employee = Employee::factory()->create(['program_studi_id' => $duplicateId]);
        $history = EducationHistory::create([
            'employee_id' => $employee->id,
            'jenjang_id' => RefJenjangPendidikan::create(['nama' => 'S2', 'urutan' => 8])->id,
            'program_studi_id' => $duplicateId,
            'nama_institusi' => 'Universitas Contoh',
            'jurusan' => 'Teknik Informatika',
            'tahun_lulus' => 2020,
            'no_ijazah' => 'IJZ-NORMALISASI-001',
        ]);

        $migration = require database_path('migrations/2026_08_13_000001_normalize_program_studi_names.php');
        $migration->up();

        $this->assertDatabaseCount('ref_program_studi', 1);
        $this->assertDatabaseHas('ref_program_studi', [
            'id' => $canonicalId,
            'nama' => 'Teknik Informatika',
        ]);
        $this->assertSame($canonicalId, $employee->fresh()->program_studi_id);
        $this->assertSame('Teknik Informatika', $employee->fresh()->prodi_pendidikan_terakhir);
        $this->assertSame($canonicalId, $history->fresh()->program_studi_id);
        $this->assertSame('Teknik Informatika', $history->fresh()->jurusan);
    }
}
