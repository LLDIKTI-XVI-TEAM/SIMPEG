<?php

use App\Services\AuditService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const BATCH_SIZE = 200;

    private const GATE_MAX_ATTEMPTS = 80;

    private const GATE_RETRY_DELAY_MICROSECONDS = 25_000;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $lastGateException = null;

        for ($attempt = 1; $attempt <= self::GATE_MAX_ATTEMPTS; $attempt++) {
            try {
                DB::transaction(fn () => $this->applyLocked(), 1);

                return;
            } catch (QueryException $exception) {
                if ((string) $exception->getCode() !== '55P03') {
                    throw $exception;
                }

                $lastGateException = $exception;

                if ($attempt < self::GATE_MAX_ATTEMPTS) {
                    // NOWAIT memastikan retry tidak menahan lock lain; jeda pendek tetap dibatasi total percobaan.
                    usleep(self::GATE_RETRY_DELAY_MICROSECONDS);
                }
            }
        }

        throw new RuntimeException(
            'Normalisasi invariant jenis cuti gagal memperoleh gate AccessExclusive setelah '
            .self::GATE_MAX_ATTEMPTS.' percobaan. Hentikan writer lalu jalankan migration kembali.',
            previous: $lastGateException,
        );
    }

    private function applyLocked(): void
    {
        // Gate diambil sebelum row lock agar ALTER tidak menunggu writer yang sedang menunggu kandidat dirty.
        DB::statement('LOCK TABLE ref_jenis_cuti IN ACCESS EXCLUSIVE MODE NOWAIT');
        $lastId = null;

        do {
            // Keyset membatasi memori dan durasi kerja per statement tanpa melepas atomisitas cutover.
            $query = DB::table('ref_jenis_cuti')
                ->whereRaw(<<<'SQL'
mengurangi_saldo_tahunan IS DISTINCT FROM
    CASE WHEN code = 'tahunan' THEN TRUE ELSE FALSE END
SQL);
            if ($lastId !== null) {
                $query->where('id', '>', $lastId);
            }
            $candidates = $query
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->lockForUpdate()
                ->get(['id', 'code', 'mengurangi_saldo_tahunan']);

            if ($candidates->isEmpty()) {
                break;
            }

            $ids = [];
            $auditIds = [];
            $auditRows = [];
            $createdAt = now();
            foreach ($candidates as $candidate) {
                $id = (string) $candidate->id;
                $code = is_string($candidate->code) ? $candidate->code : null;
                $oldFlag = (bool) $candidate->mengurangi_saldo_tahunan;
                $newFlag = $code === 'tahunan';
                $auditId = (string) Str::uuid();
                $ids[] = $id;
                $auditIds[] = $auditId;
                $auditRows[] = [
                    'id' => $auditId,
                    'user_id' => null,
                    'user_name' => AuditService::SYSTEM_DATABASE_UPGRADE,
                    'event' => 'CONFIG_UPDATE',
                    'auditable_type' => 'RefJenisCuti',
                    'auditable_id' => $id,
                    'old_values' => json_encode([
                        'id' => $id,
                        'code' => $code,
                        'mengurangi_saldo_tahunan' => $oldFlag,
                    ], JSON_THROW_ON_ERROR),
                    'new_values' => json_encode([
                        'id' => $id,
                        'code' => $code,
                        'mengurangi_saldo_tahunan' => $newFlag,
                        'actor_type' => 'system',
                    ], JSON_THROW_ON_ERROR),
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => $createdAt,
                ];
            }

            $updated = DB::table('ref_jenis_cuti')
                ->whereIn('id', $ids)
                ->update([
                    'mengurangi_saldo_tahunan' => DB::raw(
                        "CASE WHEN code = 'tahunan' THEN TRUE ELSE FALSE END",
                    ),
                ]);
            if ($updated !== count($ids)) {
                throw new RuntimeException('Jumlah row jenis cuti yang dinormalisasi tidak sesuai batch terkunci.');
            }

            if (! DB::table('audit_logs')->insert($auditRows)) {
                throw new RuntimeException('Bulk audit normalisasi jenis cuti gagal ditulis.');
            }

            // UUID audit yang baru dibuat membuktikan bijeksi tanpa tercampur jejak upgrade sebelumnya.
            $writtenAudits = DB::table('audit_logs')->whereIn('id', $auditIds)->count();
            if ($writtenAudits !== count($auditRows)) {
                throw new RuntimeException('Jumlah audit normalisasi jenis cuti tidak sesuai row yang diubah.');
            }

            $lastCandidate = $candidates->last();
            if (! is_object($lastCandidate)) {
                throw new RuntimeException('Cursor batch normalisasi jenis cuti tidak tersedia.');
            }
            $lastId = (string) $lastCandidate->id;
        } while ($candidates->count() === self::BATCH_SIZE);

        DB::statement(<<<'SQL'
ALTER TABLE ref_jenis_cuti
ADD CONSTRAINT ref_jenis_cuti_annual_balance_flag_check
CHECK (
    (code = 'tahunan' AND mengurangi_saldo_tahunan IS TRUE)
    OR (code IS DISTINCT FROM 'tahunan' AND mengurangi_saldo_tahunan IS FALSE)
)
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE ref_jenis_cuti DROP CONSTRAINT IF EXISTS ref_jenis_cuti_annual_balance_flag_check');
    }
};
