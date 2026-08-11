<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_program_studi', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nama', 255)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignUuid('program_studi_id')->nullable()->constrained('ref_program_studi')->nullOnDelete();
        });
        Schema::table('education_histories', function (Blueprint $table): void {
            $table->foreignUuid('program_studi_id')->nullable()->constrained('ref_program_studi')->nullOnDelete();
        });

        // Data lama disalin ke referensi agar pilihan prodi pada pegawai dan
        // riwayat pendidikan tetap terhubung setelah migrasi dijalankan.
        $now = now();
        $names = DB::table('employees')->whereNotNull('prodi_pendidikan_terakhir')->pluck('prodi_pendidikan_terakhir')
            ->merge(DB::table('education_histories')->whereNotNull('jurusan')->pluck('jurusan'))
            ->map(fn ($name): array => ['nama' => trim((string) $name), 'key' => mb_strtolower(trim((string) $name))])
            ->filter(fn (array $programStudi): bool => $programStudi['nama'] !== '')
            ->unique('key')
            ->values();
        $idsByName = [];
        foreach ($names as $programStudi) {
            $id = (string) Str::uuid();
            DB::table('ref_program_studi')->insert(['id' => $id, 'nama' => $programStudi['nama'], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            $idsByName[$programStudi['key']] = $id;
        }
        DB::table('employees')->select(['id', 'prodi_pendidikan_terakhir'])->orderBy('id')->each(function (object $employee) use ($idsByName): void {
            $key = mb_strtolower(trim((string) $employee->prodi_pendidikan_terakhir));
            if ($key !== '' && isset($idsByName[$key])) {
                DB::table('employees')->where('id', $employee->id)->update(['program_studi_id' => $idsByName[$key]]);
            }
        });
        DB::table('education_histories')->select(['id', 'jurusan'])->orderBy('id')->each(function (object $history) use ($idsByName): void {
            $key = mb_strtolower(trim((string) $history->jurusan));
            if ($key !== '' && isset($idsByName[$key])) {
                DB::table('education_histories')->where('id', $history->id)->update(['program_studi_id' => $idsByName[$key]]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('education_histories', function (Blueprint $table): void {
            $table->dropForeign(['program_studi_id']);
            $table->dropColumn('program_studi_id');
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropForeign(['program_studi_id']);
            $table->dropColumn('program_studi_id');
        });
        Schema::dropIfExists('ref_program_studi');
    }
};
