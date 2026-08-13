<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $canonicalIds = [];
        $canonicalNames = [];

        foreach (DB::table('ref_program_studi')->orderBy('created_at')->orderBy('id')->get() as $programStudi) {
            $nama = preg_replace('/\s+/u', ' ', trim((string) $programStudi->nama)) ?? trim((string) $programStudi->nama);
            $key = mb_strtolower($nama);

            if (isset($canonicalIds[$key])) {
                DB::table('employees')->where('program_studi_id', $programStudi->id)
                    ->update(['program_studi_id' => $canonicalIds[$key]]);
                DB::table('education_histories')->where('program_studi_id', $programStudi->id)
                    ->update(['program_studi_id' => $canonicalIds[$key]]);
                DB::table('ref_program_studi')->where('id', $programStudi->id)->delete();

                continue;
            }

            $canonicalIds[$key] = $programStudi->id;
            $canonicalNames[$programStudi->id] = $nama;
            DB::table('ref_program_studi')->where('id', $programStudi->id)->update(['nama' => $nama]);
        }

        foreach ($canonicalNames as $id => $nama) {
            DB::table('employees')->where('program_studi_id', $id)
                ->update(['prodi_pendidikan_terakhir' => $nama]);
            DB::table('education_histories')->where('program_studi_id', $id)
                ->update(['jurusan' => $nama]);
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX ref_program_studi_normalized_name_unique ON ref_program_studi (LOWER(REGEXP_REPLACE(TRIM(nama), '\\s+', ' ', 'g')))");
        } else {
            DB::statement('CREATE UNIQUE INDEX ref_program_studi_normalized_name_unique ON ref_program_studi (LOWER(nama))');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ref_program_studi_normalized_name_unique');
    }
};
