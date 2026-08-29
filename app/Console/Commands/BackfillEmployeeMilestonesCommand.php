<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Employees\TmtCalculatorService;
use Illuminate\Console\Command;

class BackfillEmployeeMilestonesCommand extends Command
{
    /** @var string Menetapkan ukuran batch agar rekonsiliasi tidak memuat semua pegawai ke memori. */
    protected $signature = 'milestone:backfill
                            {--chunk=100 : Number of employees to process per chunk}
                            {--only-active : Only backfill for active employees}
                            {--recalculate-legacy-pension : Recalculate only unverified legacy pension dates from current BUP sources}';

    /** @var string Menjelaskan bahwa perintah merekonsiliasi milestone pegawai yang sudah ada. */
    protected $description = 'Rekonsiliasi milestone pegawai yang sudah ada';

    /** Menjalankan rekonsiliasi milestone per batch dan melaporkan kegagalan setiap pegawai. */
    public function handle(TmtCalculatorService $tmtCalculator): int
    {
        $chunkSize = (int) $this->option('chunk');
        $onlyActive = $this->option('only-active');
        $recalculateLegacyPension = (bool) $this->option('recalculate-legacy-pension');

        if ($chunkSize < 1) {
            $this->error('Chunk size must be at least 1.');

            return self::INVALID;
        }

        $this->info('Starting employee milestone backfill...');
        $this->info('Chunk size: '.$chunkSize);
        $this->info('Only active: '.($onlyActive ? 'YES' : 'NO'));
        if ($recalculateLegacyPension) {
            $this->warn('WARNING: Legacy pension dates will be recalculated from current BUP sources.');
            $this->warn('Verified manual/import pension dates with explicit milestone provenance will be preserved.');
        }
        $this->newLine();

        $query = Employee::query();

        if ($onlyActive) {
            $query->whereActiveStatus();
        }

        // Jumlah ini menjadi batas progress bar tanpa memuat seluruh pegawai ke memori.
        $totalEmployees = $query->count();
        $this->info("Total employees to process: {$totalEmployees}");
        $this->newLine();

        if ($totalEmployees === 0) {
            $this->warn('No employees found to process.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Do you want to proceed with the backfill?', true)) {
            $this->warn('Backfill cancelled.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Processing employees...');

        $processedCount = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar($totalEmployees);
        $progressBar->start();

        // Rekonsiliasi wajib dijalankan untuk setiap pegawai karena satu milestone aktif
        // tidak membuktikan bahwa seluruh tipe milestone sudah lengkap atau masih berlaku.
        $query->chunkById($chunkSize, function ($employees) use ($tmtCalculator, $recalculateLegacyPension, &$processedCount, &$errorCount, $progressBar): void {
            foreach ($employees as $employee) {
                try {
                    $tmtCalculator->syncForEmployee(
                        $employee,
                        recalculateLegacyPension: $recalculateLegacyPension,
                    );
                    $processedCount++;
                } catch (\Throwable $e) {
                    $errorCount++;
                    $this->error("\nError processing employee {$employee->id} ({$employee->nama_lengkap}): {$e->getMessage()}");
                }

                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->newLine(2);

        $this->info('Backfill completed!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total employees', $totalEmployees],
                ['Processed', $processedCount],
                ['Errors', $errorCount],
            ]
        );

        if ($errorCount > 0) {
            $this->warn("Backfill completed with {$errorCount} error(s). Check logs for details.");

            return self::FAILURE;
        }

        $this->info('All employees processed successfully!');

        return self::SUCCESS;
    }
}
