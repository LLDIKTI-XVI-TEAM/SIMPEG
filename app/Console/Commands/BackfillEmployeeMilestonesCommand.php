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
                            {--only-active : Only backfill for active employees}';

    /** @var string Menjelaskan bahwa perintah merekonsiliasi milestone pegawai yang sudah ada. */
    protected $description = 'Rekonsiliasi milestone pegawai yang sudah ada';

    /** Menjalankan rekonsiliasi milestone per batch dan melaporkan kegagalan setiap pegawai. */
    public function handle(TmtCalculatorService $tmtCalculator): int
    {
        $chunkSize = (int) $this->option('chunk');
        $onlyActive = $this->option('only-active');

        $this->info('Starting employee milestone backfill...');
        $this->info('Chunk size: '.$chunkSize);
        $this->info('Only active: '.($onlyActive ? 'YES' : 'NO'));
        $this->newLine();

        $query = Employee::query();

        if ($onlyActive) {
            $query->where('status_aktif', 'Aktif');
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
        $query->chunkById($chunkSize, function ($employees) use ($tmtCalculator, &$processedCount, &$errorCount, $progressBar): void {
            foreach ($employees as $employee) {
                try {
                    $tmtCalculator->syncForEmployee($employee);
                    $processedCount++;
                } catch (\Throwable $e) {
                    $errorCount++;
                    $this->error("\nError processing employee {$employee->id} ({$employee->nama}): {$e->getMessage()}");
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
