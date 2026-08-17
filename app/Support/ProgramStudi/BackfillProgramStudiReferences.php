<?php

namespace App\Support\ProgramStudi;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillProgramStudiReferences
{
    /**
     * Membuat referensi dari snapshot legacy dan menghubungkannya kembali ke
     * pegawai serta riwayat pendidikan yang sudah ada.
     */
    public function execute(): void
    {
        $now = now();
        $names = DB::table('employees')->whereNotNull('prodi_pendidikan_terakhir')->pluck('prodi_pendidikan_terakhir')
            ->merge(DB::table('education_histories')->whereNotNull('jurusan')->pluck('jurusan'))
            ->map(function ($name): array {
                $normalizedName = $this->normalizeName($name);

                return ['nama' => $normalizedName, 'key' => $this->lookupKey($normalizedName)];
            })
            ->filter(fn (array $programStudi): bool => $programStudi['nama'] !== '')
            ->unique('key')
            ->values();
        $idsByName = [];

        foreach ($names as $programStudi) {
            $id = (string) Str::uuid();
            DB::table('ref_program_studi')->insert([
                'id' => $id,
                'nama' => $programStudi['nama'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $idsByName[$programStudi['key']] = $id;
        }

        DB::table('employees')->select(['id', 'prodi_pendidikan_terakhir'])->orderBy('id')->each(function (object $employee) use ($idsByName): void {
            $key = $this->lookupKey($employee->prodi_pendidikan_terakhir);
            if ($key !== '' && isset($idsByName[$key])) {
                DB::table('employees')->where('id', $employee->id)->update(['program_studi_id' => $idsByName[$key]]);
            }
        });

        DB::table('education_histories')->select(['id', 'jurusan'])->orderBy('id')->each(function (object $history) use ($idsByName): void {
            $key = $this->lookupKey($history->jurusan);
            if ($key !== '' && isset($idsByName[$key])) {
                DB::table('education_histories')->where('id', $history->id)->update(['program_studi_id' => $idsByName[$key]]);
            }
        });
    }

    /** Samakan spasi snapshot legacy dengan bentuk nama kanonis referensi. */
    private function normalizeName(mixed $name): string
    {
        $rawName = (string) $name;
        $collapsedName = preg_replace('/\s+/u', ' ', $rawName) ?? $rawName;

        return trim($collapsedName);
    }

    private function lookupKey(mixed $name): string
    {
        return mb_strtolower($this->normalizeName($name));
    }
}
