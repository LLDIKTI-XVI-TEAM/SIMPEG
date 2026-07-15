<?php

namespace App\Console\Commands;

use App\Actions\Employees\PurgeDeletedEmployeesAction;
use App\Models\Employee;
use Illuminate\Console\Command;

class PurgeDeletedEmployees extends Command
{
    protected $signature = 'employees:purge-deleted
                            {--dry-run : Tampilkan daftar yang akan dihapus tanpa benar-benar menghapus}';

    protected $description = 'Hapus permanen data pegawai yang sudah lebih dari 30 hari di trash, beserta semua relasi dan file SK/dokumen dari storage.';

    public function handle(PurgeDeletedEmployeesAction $action): int
    {
        $retentionDays = PurgeDeletedEmployeesAction::RETENTION_DAYS;
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->warn("🔍 [DRY RUN] Menampilkan pegawai yang akan dihapus (sudah ≥ {$retentionDays} hari di trash)...");

            $employees = Employee::onlyTrashed()
                ->where('deleted_at', '<=', now()->subDays($retentionDays))
                ->get(['id', 'nama_lengkap', 'nip', 'deleted_at']);

            if ($employees->isEmpty()) {
                $this->info('Tidak ada pegawai yang perlu dihapus.');

                return self::SUCCESS;
            }

            $this->table(
                ['Nama', 'NIP', 'Tanggal Dihapus', 'Sisa Hari'],
                $employees->map(fn ($e) => [
                    $e->nama_lengkap,
                    $e->nip,
                    $e->deleted_at->format('d/m/Y H:i'),
                    $e->deleted_at->diffForHumans(),
                ])->toArray()
            );

            $this->warn("Total: {$employees->count()} pegawai akan dihapus permanen.");

            return self::SUCCESS;
        }

        $this->info("🗑️  Menjalankan purge pegawai (retensi {$retentionDays} hari)...");

        $result = $action->execute();

        if ($result['purged'] === 0 && $result['errors'] === 0) {
            $this->info('Tidak ada pegawai yang perlu dihapus.');

            return self::SUCCESS;
        }

        if ($result['purged'] > 0) {
            $this->info("✅ {$result['purged']} pegawai berhasil dihapus permanen:");
            foreach ($result['names'] as $name) {
                $this->line("   • {$name}");
            }
        }

        if ($result['errors'] > 0) {
            $this->error("❌ {$result['errors']} pegawai gagal dihapus. Periksa log aplikasi untuk detail.");
        }

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
