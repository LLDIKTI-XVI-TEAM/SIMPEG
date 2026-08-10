<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Employees\TmtCalculatorService;
use Illuminate\Console\Command;

class BackfillEmployeeMilestonesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'milestone:backfill
                            {--chunk=100 : Number of employees to process per chunk}
                            {--force : Force backfill even for employees with existing milestones}
                            {--only-active : Only backfill for active employees}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill employee milestones for existing employees (US-5.5 AC-5)';

    /**
     * Execute the console command.
     */
    public function handle(TmtCalculatorService $tmtCalculator): int
    {
        $chunkSize = (int) $this->option('chunk');
        $force = $this->option('force');
        $onlyActive = $this->option('only-active');

        $this->info('Starting employee milestone backfill...');
        $this->info('Chunk size: '.$chunkSize);
        $this->info('Force mode: '.($force ? 'YES' : 'NO'));
        $this->info('Only active: '.($onlyActive ? 'YES' : 'NO'));
        $this->newLine();

        $query = Employee::query();

        if ($onlyActive) {
            $query->where('status_aktif', 'Aktif');
        }

        // Count total employees to process
        $totalEmployees = $query->count();
        $this->info("Total employees to process: {$totalEmployees}");
        $this->newLine();

        if ($totalEmployees === 0) {
            $this->warn('No employees found to process.');

            return self::SUCCESS;
        }

        // Confirm before proceeding
        if (! $this->confirm('Do you want to proceed with the backfill?', true)) {
            $this->warn('Backfill cancelled.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Processing employees...');

        $processedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar($totalEmployees);
        $progressBar->start();

        $query->chunkById($chunkSize, function ($employees) use ($tmtCalculator, $force, &$processedCount, &$skippedCount, &$errorCount, $progressBar): void {
            foreach ($employees as $employee) {
                try {
                    // Skip logic: only skip if employee has a complete set of ACTIVE milestones
                    // A complete set means at least one active milestone exists and
                    // the employee doesn't need resync (unless force mode is enabled)
                    if (! $force) {
                        // Check if employee has any active milestones
                        $hasActiveMilestones = $employee->milestones()
                            ->where('is_active', true)
                            ->exists();

                        // Only skip if we have active milestones
                        // Note: Even if milestones exist, they might be:
                        // 1. Inactive (invalidated by config changes)
                        // 2. Incomplete (only some milestone types computed)
                        // For safety, we only skip if active milestones exist
                        // syncForEmployee() is idempotent and will handle updates efficiently
                        if ($hasActiveMilestones) {
                            $skippedCount++;
                            $progressBar->advance();

                            continue;
                        }
                    }

                    // Sync milestones for this employee
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

        // Summary
        $this->info('Backfill completed!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total employees', $totalEmployees],
                ['Processed', $processedCount],
                ['Skipped (already have milestones)', $skippedCount],
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
