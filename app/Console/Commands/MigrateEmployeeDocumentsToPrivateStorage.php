<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MigrateEmployeeDocumentsToPrivateStorage extends Command
{
    private const ORPHAN_QUARANTINE_PREFIX = 'quarantine/orphaned-public';

    /** @var list<string> */
    private const LEGACY_DOCUMENT_PREFIXES = [
        'appointments/sk/',
        'berkas/',
        'pegawai/',
        'positions/sk/',
        'ranks/sk/',
        'salaries/sk/',
        'sk/',
    ];

    protected $signature = 'documents:migrate-to-private-storage
        {--execute : Salin file terverifikasi ke storage privat lalu hapus salinan publik}';

    protected $description = 'Migrasikan dokumen pegawai yang direferensikan database dari disk publik ke disk privat';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $this->info($execute ? 'Mode execute: migrasi dimulai.' : 'Mode dry-run: tidak ada file yang diubah.');

        $public = Storage::disk('public');
        $private = Storage::disk(Document::STORAGE_DISK);
        $counts = [
            'dipindahkan' => 0,
            'dikarantina' => 0,
            'siap' => 0,
            'sudah_privat' => 0,
            'hilang' => 0,
            'konflik' => 0,
            'yatim' => 0,
        ];
        $referencedPaths = $this->referencedPaths();

        foreach ($referencedPaths as $path) {
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

        foreach ($this->orphanedPublicDocumentPaths($public, $referencedPaths) as $path) {
            if (! $execute) {
                $counts['yatim']++;
                $this->warn("yatim: {$path}; file publik tidak memiliki referensi database.");

                continue;
            }

            if ($this->quarantineOrphan($public, $private, $path)) {
                $counts['dikarantina']++;
            } else {
                $counts['konflik']++;
            }
        }

        $this->line(sprintf(
            'Ringkasan: dipindahkan=%d, dikarantina=%d, siap=%d, sudah_privat=%d, hilang=%d, konflik=%d, yatim=%d.',
            $counts['dipindahkan'],
            $counts['dikarantina'],
            $counts['siap'],
            $counts['sudah_privat'],
            $counts['hilang'],
            $counts['konflik'],
            $counts['yatim'],
        ));

        return $counts['konflik'] > 0 || $counts['hilang'] > 0 || $counts['yatim'] > 0
            ? self::FAILURE
            : self::SUCCESS;
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
        return $this->sameContentsAt($public, $path, $private, $path);
    }

    /**
     * Menginventarisasi hanya namespace dokumen pegawai legacy; foto dan lampiran cuti publik dibiarkan.
     *
     * @param  Collection<int, string>  $referencedPaths
     * @return Collection<int, string>
     */
    private function orphanedPublicDocumentPaths(Filesystem $public, Collection $referencedPaths): Collection
    {
        return collect($public->allFiles())
            ->map(fn (string $path): string => str_replace('\\', '/', ltrim($path, '/\\')))
            ->filter(fn (string $path): bool => $this->isLegacyEmployeeDocumentPath($path))
            ->diff($referencedPaths)
            ->values();
    }

    private function isLegacyEmployeeDocumentPath(string $path): bool
    {
        foreach (self::LEGACY_DOCUMENT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        $firstSegment = explode('/', $path, 2)[0];

        // Upload dokumen lama memakai UUID pegawai sebagai folder paling atas.
        return Str::isUuid($firstSegment) && str_contains($path, '/');
    }

    private function quarantineOrphan(Filesystem $public, Filesystem $private, string $path): bool
    {
        $quarantinePath = self::ORPHAN_QUARANTINE_PREFIX.'/'.$path;
        $privateExists = $private->exists($quarantinePath);
        if ($privateExists && ! $this->sameContentsAt($public, $path, $private, $quarantinePath)) {
            $this->warn("konflik: karantina {$quarantinePath} memiliki isi berbeda; file publik dipertahankan.");

            return false;
        }

        if (! $privateExists) {
            $contents = $public->get($path);
            if (! $private->put($quarantinePath, $contents)
                || ! $this->sameContentsAt($public, $path, $private, $quarantinePath)) {
                $private->delete($quarantinePath);
                $this->warn("konflik: verifikasi karantina gagal untuk {$path}; file publik dipertahankan.");

                return false;
            }
        }

        if (! $public->delete($path) || $public->exists($path)) {
            $this->warn("konflik: file publik tanpa referensi {$path} gagal dihapus setelah karantina.");

            return false;
        }

        return true;
    }

    private function sameContentsAt(
        Filesystem $source,
        string $sourcePath,
        Filesystem $target,
        string $targetPath,
    ): bool {
        return hash_equals(
            hash('sha256', $source->get($sourcePath)),
            hash('sha256', $target->get($targetPath)),
        );
    }
}
