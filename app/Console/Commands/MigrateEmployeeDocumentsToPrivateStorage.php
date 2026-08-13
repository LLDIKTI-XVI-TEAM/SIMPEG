<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MigrateEmployeeDocumentsToPrivateStorage extends Command
{
    protected $signature = 'documents:migrate-to-private-storage
        {--execute : Salin file terverifikasi ke storage privat lalu hapus salinan publik}';

    protected $description = 'Migrasikan dokumen pegawai yang direferensikan database dari disk publik ke disk privat';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $this->info($execute ? 'Mode execute: migrasi dimulai.' : 'Mode dry-run: tidak ada file yang diubah.');

        $public = Storage::disk('public');
        $private = Storage::disk(Document::STORAGE_DISK);
        $counts = ['dipindahkan' => 0, 'siap' => 0, 'sudah_privat' => 0, 'hilang' => 0, 'konflik' => 0];

        foreach ($this->referencedPaths() as $path) {
            $publicExists = $public->exists($path);
            $privateExists = $private->exists($path);

            if (! $publicExists) {
                $counts[$privateExists ? 'sudah_privat' : 'hilang']++;

                continue;
            }

            if ($privateExists && ! $this->sameContents($public, $private, $path)) {
                $counts['konflik']++;
                $this->warn("konflik: {$path}; file publik dan privat dibiarkan utuh.");

                continue;
            }

            if (! $execute) {
                $counts['siap']++;

                continue;
            }

            if (! $privateExists) {
                $contents = $public->get($path);
                if (! $private->put($path, $contents) || ! $this->sameContents($public, $private, $path)) {
                    $private->delete($path);
                    $counts['konflik']++;
                    $this->warn("konflik: verifikasi salinan privat gagal untuk {$path}; file publik dipertahankan.");

                    continue;
                }
            }

            if (! $public->delete($path) || $public->exists($path)) {
                $counts['konflik']++;
                $this->warn("konflik: salinan publik {$path} gagal dihapus setelah verifikasi.");

                continue;
            }

            $counts['dipindahkan']++;
        }

        $this->line(sprintf(
            'Ringkasan: dipindahkan=%d, siap=%d, sudah_privat=%d, hilang=%d, konflik=%d.',
            $counts['dipindahkan'],
            $counts['siap'],
            $counts['sudah_privat'],
            $counts['hilang'],
            $counts['konflik'],
        ));

        return $counts['konflik'] > 0 || $counts['hilang'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Mengambil hanya path dokumen pegawai yang masih direferensikan database agar migrasi tidak menyapu file lain.
     *
     * @return Collection<int, string>
     */
    private function referencedPaths(): Collection
    {
        return collect([
            ['documents', 'file_path'],
            ['rank_histories', 'file_sk'],
            ['position_histories', 'file_sk'],
            ['salary_histories', 'file_sk'],
            ['appointments', 'file_sk'],
            ['discipline_records', 'file_sk'],
            ['education_histories', 'file_ijazah'],
            ['employee_status_histories', 'file_sk'],
            ['employees', 'status_berkas_path'],
        ])->flatMap(fn (array $source) => DB::table($source[0])
            ->whereNotNull($source[1])
            ->where($source[1], '<>', '')
            ->pluck($source[1]))
            ->map(fn (mixed $path): string => (string) $path)
            ->unique()
            ->values();
    }

    private function sameContents(Filesystem $public, Filesystem $private, string $path): bool
    {
        return hash_equals(
            hash('sha256', $public->get($path)),
            hash('sha256', $private->get($path)),
        );
    }
}
