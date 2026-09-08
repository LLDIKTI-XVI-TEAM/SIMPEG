<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithAdministrativeLeavePostponementSchema;
use Tests\TestCase;

/** Membuktikan keputusan administratif set-once dan reversal fakta tanpa membuka jalur koreksi umum. */
class AdministrativeLeavePostponementSchemaTest extends TestCase
{
    use InteractsWithAdministrativeLeavePostponementSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Kontrak penangguhan administratif memerlukan PostgreSQL.');
        }
    }

    public function test_metadata_keputusan_tersedia_tetapi_tidak_masuk_mass_assignment_atau_serialisasi(): void
    {
        $this->assertTrue(Schema::hasColumns('leave_requests', [
            'administratively_postponed_at',
            'administratively_postponed_by',
            'administrative_postponement_reason',
        ]));
        [$leave, $record, $actor] = $this->fixture();
        foreach (array_keys($this->decision($actor)) as $field) {
            if ($field !== 'status') {
                $this->assertFalse($leave->isFillable($field));
            }
        }

        $this->postpone($leave, $actor);
        $this->cancelWithEffects($record, $actor);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $leave->refresh()->load('administrativelyPostponedBy');
        $this->assertSame('ditangguhkan_administratif', $leave->status);
        $this->assertInstanceOf(Carbon::class, $leave->administratively_postponed_at);
        $this->assertSame($actor->id, $leave->administrativelyPostponedBy->id);
        $this->assertSame('Periode tidak dapat dilaksanakan.', $leave->administrative_postponement_reason);
        foreach (array_keys($this->decision($actor)) as $field) {
            if ($field !== 'status') {
                $this->assertArrayNotHasKey($field, $leave->toArray());
            }
        }
    }

    public function test_reversal_mempertahankan_payload_pengajuan_dan_fakta_asli(): void
    {
        [$leave, $record, $actor] = $this->fixture();
        $requestBefore = (array) DB::table('leave_requests')->find($leave->id);
        $factBefore = (array) DB::table('leave_usage_records')->find($record->id);

        $this->postpone($leave, $actor);
        $this->cancelWithEffects($record, $actor);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $requestAfter = (array) DB::table('leave_requests')->find($leave->id);
        foreach (array_keys($this->decision($actor)) as $field) {
            unset($requestBefore[$field], $requestAfter[$field]);
        }
        $this->assertSame($requestBefore, $requestAfter);
        $factAfter = (array) DB::table('leave_usage_records')->find($record->id);
        $this->assertSame('cancelled', $factAfter['record_status']);
        $this->assertSame('Pemakaian dibatalkan karena penangguhan administratif.', $factAfter['correction_reason']);
        unset($factBefore['record_status'], $factAfter['record_status'], $factBefore['correction_reason'], $factAfter['correction_reason']);
        $this->assertSame($factBefore, $factAfter);
        $this->assertDatabaseCount('leave_usage_records', 1);
        $this->assertDatabaseCount('leave_balance_ledger', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    #[DataProvider('nonApprovedStatuses')]
    public function test_status_selain_approved_tidak_bisa_menjadi_administratif(string $status): void
    {
        [$leave, , $actor] = $this->fixture($status);

        $this->assertRejected(fn () => $this->postpone($leave, $actor), 'administratif');
        $this->assertSame($status, $leave->fresh()->status);
    }

    public static function nonApprovedStatuses(): array
    {
        return array_map(fn (string $status): array => [$status], [
            'menunggu_approval', 'ditangguhkan', 'ditangguhkan_tugas_dinas', 'perlu_perubahan',
            'tidak_disetujui', 'dikembalikan_karena_rollover', 'menunggu_pembatalan', 'dibatalkan',
        ]);
    }

    #[DataProvider('invalidDecisions')]
    public function test_metadata_tidak_lengkap_atau_alasan_tidak_sah_ditolak(array $override, string $message): void
    {
        [$leave, , $actor] = $this->fixture();

        $this->assertRejected(fn () => DB::table('leave_requests')->where('id', $leave->id)
            ->update(array_replace($this->decision($actor), $override)), $message);
        $this->assertSame('disetujui', $leave->fresh()->status);
    }

    public static function invalidDecisions(): array
    {
        return [
            'alasan kosong' => [['administrative_postponement_reason' => ''], 'leave_requests_administrative_metadata_check'],
            'alasan whitespace' => [['administrative_postponement_reason' => '   '], 'leave_requests_administrative_metadata_check'],
            'alasan hilang' => [['administrative_postponement_reason' => null], 'leave_requests_administrative_metadata_check'],
            'alasan terlalu panjang' => [['administrative_postponement_reason' => str_repeat('a', 501)], 'leave_requests_administrative_metadata_check'],
            'aktor hilang' => [['administratively_postponed_by' => null], 'wajib berasal'],
            'waktu hilang' => [['administratively_postponed_at' => null], 'wajib berasal'],
            'metadata tanpa transisi' => [['status' => 'disetujui'], 'leave_requests_administrative_metadata_check'],
            'snapshot ditimpa bersamaan' => [['alasan' => 'Alasan pengajuan diganti.'], 'tanpa mengubah snapshot'],
        ];
    }

    public function test_insert_terminal_langsung_ditolak(): void
    {
        [$leave, , $actor] = $this->fixture();
        $attributes = array_replace($leave->getRawOriginal(), $this->decision($actor), ['id' => (string) Str::uuid()]);

        $this->assertRejected(fn () => DB::table('leave_requests')->insert($attributes), 'administratif');
    }

    #[DataProvider('terminalMutations')]
    public function test_keputusan_terminal_dan_snapshot_pengajuan_tidak_bisa_diedit(array $changes): void
    {
        [$leave, $record, $actor] = $this->fixture();
        $this->postpone($leave, $actor);
        $this->cancelWithEffects($record, $actor);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertRejected(fn () => DB::table('leave_requests')->where('id', $leave->id)->update($changes), 'immutable');
        $this->assertSame('ditangguhkan_administratif', $leave->fresh()->status);
    }

    public static function terminalMutations(): array
    {
        return [
            'reaktivasi' => [['status' => 'disetujui']],
            'alasan keputusan' => [['administrative_postponement_reason' => 'Alasan pengganti.']],
            'timestamp keputusan' => [['administratively_postponed_at' => '2026-09-07 00:00:00']],
            'aktor manual null' => [['administratively_postponed_by' => null]],
            'alasan asli' => [['alasan' => 'Snapshot ditimpa.']],
            'periode asli' => [['tanggal_selesai' => '2026-10-06']],
            'hari asli' => [['jumlah_hari_kerja' => 2]],
        ];
    }

    public function test_penghapusan_user_hanya_menullkan_aktor_tanpa_mengubah_keputusan(): void
    {
        [$leave, $record, $actor] = $this->fixture();
        $this->postpone($leave, $actor);
        $this->cancelWithEffects($record, $actor);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $before = (array) DB::table('leave_requests')->find($leave->id);

        $actor->delete();

        $after = (array) DB::table('leave_requests')->find($leave->id);
        $this->assertNull($after['administratively_postponed_by']);
        unset($before['administratively_postponed_by'], $after['administratively_postponed_by']);
        $this->assertSame($before, $after);
        $this->assertRejected(fn () => DB::table('leave_requests')->where('id', $leave->id)->delete(), 'immutable');
    }

    #[DataProvider('invalidFactMutations')]
    public function test_fakta_approved_tidak_bisa_supersede_atau_mengubah_payload(array $changes): void
    {
        [$leave, $record, $actor] = $this->fixture();
        $this->postpone($leave, $actor);

        $this->assertRejected(fn () => DB::table('leave_usage_records')->where('id', $record->id)
            ->update(array_replace(['record_status' => 'cancelled', 'correction_reason' => 'Koreksi resmi.'], $changes)), 'immutable');
    }

    public static function invalidFactMutations(): array
    {
        return [
            'supersede' => [['record_status' => 'superseded']],
            'hari' => [['workdays' => 2]],
            'tanggal' => [['end_date' => '2026-10-06']],
            'nomor dokumen' => [['approval_document_number' => 'PENGGANTI/2026']],
            'catatan' => [['administrative_note' => 'Catatan pengganti.']],
        ];
    }

    #[DataProvider('mismatchedFacts')]
    public function test_fakta_yang_tidak_cocok_dengan_pengajuan_tidak_bisa_dibatalkan(array $changes): void
    {
        [$leave, $record, $actor] = $this->fixture(factOverrides: $changes);
        $this->postpone($leave, $actor);

        $this->assertRejected(fn () => $this->cancelWithEffects($record, $actor), 'approved_request');
        $this->assertSame('active', $record->fresh()->record_status);
    }

    public static function mismatchedFacts(): array
    {
        return [
            'hari' => [['workdays' => 2]],
            'tanggal efektif' => [['effective_date' => '2026-10-06']],
            'periode' => [['start_date' => '2026-10-06', 'end_date' => '2026-10-06', 'effective_date' => '2026-10-06']],
        ];
    }

    #[DataProvider('missingEffects')]
    public function test_deferred_guard_menolak_reversal_tanpa_ledger_dan_audit_lengkap(bool $ledger, bool $audit): void
    {
        [$leave, $record, $actor] = $this->fixture();

        $this->assertRejected(function () use ($leave, $record, $actor, $ledger, $audit): void {
            $this->postpone($leave, $actor);
            $this->cancelWithEffects($record, $actor, $ledger, $audit);
        }, 'efek resmi');
        $this->assertSame('disetujui', $leave->fresh()->status);
        $this->assertSame('active', $record->fresh()->record_status);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function missingEffects(): array
    {
        return [[false, false], [true, false], [false, true]];
    }

    public function test_fakta_terminal_tidak_bisa_dibatalkan_dua_kali_atau_diaktifkan_kembali(): void
    {
        [$leave, $record, $actor] = $this->fixture();
        $this->postpone($leave, $actor);
        $this->cancelWithEffects($record, $actor);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        foreach (['cancelled', 'active'] as $status) {
            $this->assertRejected(fn () => DB::table('leave_usage_records')->where('id', $record->id)
                ->update(['record_status' => $status]), 'terminal');
        }
        $this->assertRejected(fn () => DB::table('leave_usage_records')->where('id', $record->id)->delete(), 'tidak dapat dihapus');
        $this->assertDatabaseCount('leave_balance_ledger', 1);
    }

    public function test_rollback_schema_menolak_membuang_jejak_keputusan(): void
    {
        [$leave, $record, $actor] = $this->fixture();
        $this->postpone($leave, $actor);
        $this->cancelWithEffects($record, $actor);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $migration = require database_path('migrations/2026_09_06_000001_add_administrative_leave_postponement_support.php');

        $this->assertThrows(fn () => $migration->down(), RuntimeException::class);
        $this->assertTrue(Schema::hasColumn('leave_requests', 'administrative_postponement_reason'));
        $this->assertSame('ditangguhkan_administratif', $leave->fresh()->status);
        $this->assertSame('cancelled', $record->fresh()->record_status);
    }
}
