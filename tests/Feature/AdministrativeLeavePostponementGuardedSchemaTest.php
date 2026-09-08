<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\GuardsDestructiveMigrationTestEnvironment;
use Tests\Concerns\InteractsWithAdministrativeLeavePostponementSchema;
use Tests\TestCase;

/** Migrasi dan commit nyata dijalankan terpisah dari cakupan schema berisolasi transaksi. */
#[Group('guarded-destructive')]
class AdministrativeLeavePostponementGuardedSchemaTest extends TestCase
{
    use GuardsDestructiveMigrationTestEnvironment;
    use InteractsWithAdministrativeLeavePostponementSchema;

    #[DataProvider('unreversedRequests')]
    public function test_commit_status_administratif_tanpa_pembalikan_fakta_ditolak(bool $hasFact): void
    {
        $this->refreshDisposableSchema();
        [$leave, $record, $actor] = $this->fixture();
        if (! $hasFact) {
            $leave = $leave->replicate();
            $leave->save();
        }
        $this->assertSame(0, DB::transactionLevel());

        try {
            DB::transaction(fn () => $this->postpone($leave, $actor));
            $this->fail('Commit penangguhan tanpa fakta approved yang dibalik seharusnya ditolak.');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('fakta approved yang dibatalkan', $exception->getMessage());
        }

        $this->assertSame('disetujui', $leave->fresh()->status);
        $this->assertNull($leave->fresh()->administrative_postponement_reason);
        $this->assertSame('active', $record->fresh()->record_status);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function unreversedRequests(): array
    {
        return ['fakta masih aktif' => [true], 'fakta belum ada' => [false]];
    }

    public function test_insert_fakta_approved_langsung_terminal_tidak_bisa_melewati_efek_reversal(): void
    {
        $this->refreshDisposableSchema();
        try {
            DB::transaction(function (): void {
                [$leave, , $actor] = $this->fixture(factOverrides: [
                    'record_status' => 'cancelled',
                    'correction_reason' => 'Fakta terminal tanpa reversal resmi.',
                ]);
                $this->postpone($leave, $actor);
            });
            $this->fail('Fakta approved tidak boleh dibuat langsung terminal untuk melewati efek reversal.');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('approved', $exception->getMessage());
        }

        $this->assertDatabaseCount('leave_requests', 0);
        $this->assertDatabaseCount('leave_usage_records', 0);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_down_tanpa_keputusan_memulihkan_guard_lama_dan_up_mempertahankan_data(): void
    {
        $this->refreshDisposableSchema();
        [$leave, $record, $actor] = $this->fixture();
        $migration = require database_path('migrations/2026_09_06_000001_add_administrative_leave_postponement_support.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('leave_requests', 'administrative_postponement_reason'));
        $this->assertRejected(fn () => $this->cancelWithEffects($record, $actor), 'approved_request');
        $migration->up();

        $this->assertSame('disetujui', $leave->fresh()->status);
        DB::transaction(function () use ($leave, $record, $actor): void {
            $this->postpone($leave, $actor);
            $this->cancelWithEffects($record, $actor);
        });
        $this->assertSame('cancelled', $record->fresh()->record_status);
    }

    public function test_ledger_dan_audit_transaksi_sebelumnya_tidak_mengesahkan_reversal_baru(): void
    {
        $this->refreshDisposableSchema();
        [$leave, $record, $actor] = DB::transaction(fn (): array => $this->fixture());
        DB::transaction(fn () => $this->recordEffects($record, $actor));
        $this->assertSame(0, DB::transactionLevel());

        $this->assertRejected(function () use ($leave, $record, $actor): void {
            $this->postpone($leave, $actor);
            $this->cancelWithEffects($record, $actor, false, false);
        }, 'dalam transaksi yang sama');

        $this->assertSame('disetujui', $leave->fresh()->status);
        $this->assertSame('active', $record->fresh()->record_status);
        $this->assertDatabaseCount('leave_balance_ledger', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /** Guard wajib lolos sebelum reset pertama maupun pendaftaran cleanup schema disposable. */
    private function refreshDisposableSchema(): void
    {
        $this->guardDestructiveMigrationTestEnvironment();
        $this->beforeApplicationDestroyed(function (): void {
            try {
                $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
            } finally {
                RefreshDatabaseState::$migrated = false;
            }
        });
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }
}
