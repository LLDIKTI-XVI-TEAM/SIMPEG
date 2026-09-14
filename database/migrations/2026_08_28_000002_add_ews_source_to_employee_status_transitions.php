<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SOURCE_KIND_CHECK = 'employee_status_transitions_ews_source_kind_check';

    private const IMMUTABLE_TRIGGER = 'employee_status_transitions_ews_source_immutable_trigger';

    private const IMMUTABLE_FUNCTION = 'prevent_employee_status_transition_ews_source_update';

    /**
     * Source alert menjadi owner durable untuk retry intent lifecycle. FK saja tidak
     * cukup karena pemindahan source sesudah schedule akan memindahkan hak recovery.
     */
    public function up(): void
    {
        Schema::table('ews_alerts', function (Blueprint $table): void {
            $table->foreignUuid('followup_group_id')
                ->nullable()
                ->after('followup_notified_at')
                ->constrained('ews_alerts')
                ->nullOnDelete();
            $table->index('followup_group_id');
        });

        Schema::table('employee_status_transitions', function (Blueprint $table): void {
            $table->foreignUuid('source_ews_alert_id')
                ->nullable()
                ->after('document_id')
                ->constrained('ews_alerts')
                ->restrictOnDelete();
            $table->index('source_ews_alert_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE employee_status_transitions ADD CONSTRAINT '.self::SOURCE_KIND_CHECK
            .' CHECK ((kind = \'ews_retirement\') = (source_ews_alert_id IS NOT NULL))',
        );

        DB::statement('CREATE OR REPLACE FUNCTION '.self::IMMUTABLE_FUNCTION.'() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.source_ews_alert_id IS DISTINCT FROM OLD.source_ews_alert_id THEN
                    RAISE EXCEPTION \'Source EWS transisi status bersifat immutable setelah insert.\'
                        USING ERRCODE = \'23514\';
                END IF;

                RETURN NEW;
            END;
        $$');

        DB::statement(
            'CREATE TRIGGER '.self::IMMUTABLE_TRIGGER
            .' BEFORE UPDATE ON employee_status_transitions'
            .' FOR EACH ROW EXECUTE FUNCTION '.self::IMMUTABLE_FUNCTION.'()',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::transaction(function (): void {
                DB::statement(
                    'LOCK TABLE ews_alerts, employee_status_transitions IN ACCESS EXCLUSIVE MODE',
                );

                if (DB::table('employee_status_transitions')
                    ->where('kind', 'ews_retirement')
                    ->exists()) {
                    throw new RuntimeException(
                        'Rollback source EWS ditolak: masih terdapat transisi kind ews_retirement.',
                    );
                }

                DB::statement(
                    'DROP TRIGGER IF EXISTS '.self::IMMUTABLE_TRIGGER.' ON employee_status_transitions',
                );
                DB::statement('DROP FUNCTION IF EXISTS '.self::IMMUTABLE_FUNCTION.'()');
                DB::statement(
                    'ALTER TABLE employee_status_transitions DROP CONSTRAINT IF EXISTS '.self::SOURCE_KIND_CHECK,
                );
            });
        }

        Schema::table('employee_status_transitions', function (Blueprint $table): void {
            $table->dropIndex(['source_ews_alert_id']);
            $table->dropConstrainedForeignId('source_ews_alert_id');
        });

        Schema::table('ews_alerts', function (Blueprint $table): void {
            $table->dropIndex(['followup_group_id']);
            $table->dropConstrainedForeignId('followup_group_id');
        });
    }
};
