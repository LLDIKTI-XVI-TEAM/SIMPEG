<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    private array $auditEventsBeforeReservation = [
        'CREATE',
        'UPDATE',
        'DELETE',
        'SOFT_DELETE',
        'RESTORE',
        'LOGIN',
        'LOGOUT',
        'APPROVE',
        'POSTPONE',
        'IMPORT',
        'SESSION_TIMEOUT',
        'LEAVE_BALANCE_OPENING_SET',
        'LEAVE_BALANCE_CORRECTED',
        'LEAVE_ROLLOVER_APPLIED',
        'LEAVE_BALANCE_DEDUCTED',
        'LEAVE_PROOF_GENERATED',
        'CONFIG_UPDATE',
    ];

    /** @var list<string> */
    private array $auditEventsWithReservation = [
        'CREATE',
        'UPDATE',
        'DELETE',
        'SOFT_DELETE',
        'RESTORE',
        'LOGIN',
        'LOGOUT',
        'APPROVE',
        'POSTPONE',
        'IMPORT',
        'SESSION_TIMEOUT',
        'LEAVE_BALANCE_OPENING_SET',
        'LEAVE_BALANCE_CORRECTED',
        'LEAVE_ROLLOVER_APPLIED',
        'LEAVE_BALANCE_DEDUCTED',
        'LEAVE_PROOF_GENERATED',
        'CONFIG_UPDATE',
        'LEAVE_BALANCE_RESERVED',
        'LEAVE_BALANCE_RESERVATION_ADJUSTED',
        'LEAVE_BALANCE_RESERVATION_CONVERTED',
        'LEAVE_BALANCE_RESERVATION_RELEASED',
    ];

    /** @var list<string> */
    private array $reservationEventTypes = [
        'reserved',
        'adjusted',
        'converted',
        'released',
    ];

    public function up(): void
    {
        $this->applyAuditConstraint($this->auditEventsWithReservation);

        Schema::create('leave_balance_reservation_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('leave_request_id')->constrained('leave_requests')->restrictOnDelete();
            $table->foreignUuid('leave_balance_id')->nullable()->constrained('leave_balances')->nullOnDelete();
            $table->year('tahun');
            $table->string('event_type', 30);
            $table->integer('amount');
            $table->text('reason')->nullable();
            $table->string('dedup_key', 180)->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['employee_id', 'tahun'], 'leave_reservation_employee_year_index');
            $table->index(['leave_request_id', 'tahun'], 'leave_reservation_request_year_index');
            $table->index(['event_type', 'tahun'], 'leave_reservation_event_year_index');
        });

        $this->applyReservationEventConstraint();
        $this->backfillActiveAnnualLeaveReservations();
    }

    public function down(): void
    {
        $this->stopRollbackWhenReservationEvidenceExists();

        Schema::dropIfExists('leave_balance_reservation_events');
        $this->applyAuditConstraint($this->auditEventsBeforeReservation);
    }

    /**
     * Pengajuan aktif yang sudah ada sebelum tabel ini dibuat tetap wajib mencadangkan
     * haknya. Event backfill adalah bukti sistem dan sengaja tidak mengubah saldo final.
     */
    private function backfillActiveAnnualLeaveReservations(): void
    {
        $activeRequests = DB::table('leave_requests')
            ->join('ref_jenis_cuti', 'ref_jenis_cuti.id', '=', 'leave_requests.jenis_cuti_id')
            ->where('ref_jenis_cuti.mengurangi_saldo_tahunan', true)
            ->whereIn('leave_requests.status', ['menunggu_approval', 'ditangguhkan', 'perlu_perubahan'])
            ->orderBy('leave_requests.id')
            ->get([
                'leave_requests.id',
                'leave_requests.employee_id',
                'leave_requests.tanggal_mulai',
                'leave_requests.jumlah_hari_kerja',
                'leave_requests.status',
            ]);

        foreach ($activeRequests as $leaveRequest) {
            $tahun = Carbon::parse($leaveRequest->tanggal_mulai)->year;
            $balanceId = DB::table('leave_balances')
                ->where('employee_id', $leaveRequest->employee_id)
                ->where('tahun', $tahun)
                ->value('id');
            $dedupKey = "leave_reservation:{$leaveRequest->id}:reserved";

            DB::table('leave_balance_reservation_events')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'employee_id' => $leaveRequest->employee_id,
                'leave_request_id' => $leaveRequest->id,
                'leave_balance_id' => $balanceId,
                'tahun' => $tahun,
                'event_type' => 'reserved',
                'amount' => (int) $leaveRequest->jumlah_hari_kerja,
                'reason' => 'Reservasi aktif hasil backfill penerapan kebijakan saldo cuti.',
                'dedup_key' => $dedupKey,
                'metadata' => json_encode([
                    'source' => 'migration_backfill',
                    'status_saat_backfill' => $leaveRequest->status,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]);
        }
    }

    private function applyReservationEventConstraint(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $events = collect($this->reservationEventTypes)
            ->map(fn (string $event): string => "'{$event}'")
            ->implode(', ');

        DB::statement('ALTER TABLE leave_balance_reservation_events DROP CONSTRAINT IF EXISTS leave_reservation_event_type_check');
        DB::statement("ALTER TABLE leave_balance_reservation_events ADD CONSTRAINT leave_reservation_event_type_check CHECK (event_type IN ({$events}))");
    }

    /** @param list<string> $events */
    private function applyAuditConstraint(array $events): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $quotedEvents = collect($events)
                ->map(fn (string $event): string => "'{$event}'")
                ->implode(', ');

            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_event_check');
            DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_check CHECK (event IN ({$quotedEvents}))");

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $quotedEvents = collect($events)
                ->map(fn (string $event): string => "'{$event}'")
                ->implode(', ');

            DB::statement('DROP TABLE IF EXISTS audit_logs_new');
            DB::statement("CREATE TABLE audit_logs_new (
                id varchar not null primary key,
                user_id varchar null,
                user_name varchar null,
                event varchar not null check (event in ({$quotedEvents})),
                auditable_type varchar not null,
                auditable_id varchar null,
                old_values text null,
                new_values text null,
                ip_address varchar null,
                user_agent text null,
                created_at datetime default CURRENT_TIMESTAMP not null
            )");
            DB::statement('INSERT INTO audit_logs_new (id, user_id, user_name, event, auditable_type, auditable_id, old_values, new_values, ip_address, user_agent, created_at) SELECT id, user_id, user_name, event, auditable_type, auditable_id, old_values, new_values, ip_address, user_agent, created_at FROM audit_logs');
            DB::statement('DROP TABLE audit_logs');
            DB::statement('ALTER TABLE audit_logs_new RENAME TO audit_logs');
            DB::statement('CREATE INDEX audit_logs_user_id_index ON audit_logs (user_id)');
            DB::statement('CREATE INDEX audit_logs_event_index ON audit_logs (event)');
            DB::statement('CREATE INDEX audit_logs_auditable_type_index ON audit_logs (auditable_type)');
            DB::statement('CREATE INDEX audit_logs_created_at_index ON audit_logs (created_at)');
        }
    }

    private function stopRollbackWhenReservationEvidenceExists(): void
    {
        if (DB::table('leave_balance_reservation_events')->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena event reservasi saldo cuti sudah tercatat. Arsipkan bukti audit sebelum menurunkan migrasi.');
        }

        $auditEvents = array_diff($this->auditEventsWithReservation, $this->auditEventsBeforeReservation);

        if (DB::table('audit_logs')->whereIn('event', $auditEvents)->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena audit_logs berisi event reservasi saldo cuti. Arsipkan audit log sebelum menurunkan constraint.');
        }
    }
};
