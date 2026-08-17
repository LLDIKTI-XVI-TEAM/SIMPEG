<?php

use App\Support\ProgramStudi\ProgramStudiNameNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            /** @var array<string, array{canonical: object, nama: string, duplicates: array<int, object>}> $groups */
            $groups = [];

            foreach (DB::table('ref_program_studi')->orderBy('created_at')->orderBy('id')->get() as $programStudi) {
                $nama = ProgramStudiNameNormalizer::normalize($programStudi->nama);
                $key = ProgramStudiNameNormalizer::key($nama);

                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'canonical' => $programStudi,
                        'nama' => $nama,
                        'duplicates' => [],
                    ];

                    continue;
                }

                $groups[$key]['duplicates'][] = $programStudi;
            }

            // Relasikan dan hapus seluruh duplikat lebih dahulu. Dengan begitu,
            // penggantian nama kanonis tidak dapat bertabrakan dengan nama duplikat
            // yang masih dilindungi constraint unik lama.
            foreach ($groups as $group) {
                foreach ($group['duplicates'] as $duplicate) {
                    DB::table('employees')->where('program_studi_id', $duplicate->id)
                        ->update(['program_studi_id' => $group['canonical']->id]);
                    DB::table('education_histories')->where('program_studi_id', $duplicate->id)
                        ->update(['program_studi_id' => $group['canonical']->id]);
                    DB::table('ref_program_studi')->where('id', $duplicate->id)->delete();
                }
            }

            foreach ($groups as $group) {
                $id = $group['canonical']->id;
                $nama = $group['nama'];

                DB::table('ref_program_studi')->where('id', $id)->update(['nama' => $nama]);
                DB::table('employees')->where('program_studi_id', $id)
                    ->update(['prodi_pendidikan_terakhir' => $nama]);
                DB::table('education_histories')->where('program_studi_id', $id)
                    ->update(['jurusan' => $nama]);
            }
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX ref_program_studi_normalized_name_unique ON ref_program_studi (LOWER(TRIM(REGEXP_REPLACE(nama, '\\s+', ' ', 'g'))))");
        } else {
            DB::statement('CREATE UNIQUE INDEX ref_program_studi_normalized_name_unique ON ref_program_studi (LOWER(nama))');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ref_program_studi_normalized_name_unique');
    }
};
