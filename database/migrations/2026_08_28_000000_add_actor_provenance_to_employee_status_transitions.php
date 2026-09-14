<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECK_NAME = 'employee_status_transitions_provenance_complete_check';

    private const IMMUTABLE_TRIGGER = 'employee_status_transitions_provenance_immutable_trigger';

    private const IMMUTABLE_FUNCTION = 'prevent_employee_status_transition_provenance_update';

    /**
     * Menambah snapshot provenance tanpa menggagalkan deployment karena row historis.
     * State user/session saat deployment bukan bukti schedule-time. Karena itu seluruh
     * pending legacy tetap missing agar scheduler fail-closed tanpa mengarang identitas.
     */
    public function up(): void
    {
        Schema::table('employee_status_transitions', function (Blueprint $table): void {
            $table->uuid('actor_user_id_snapshot')->nullable();
            $table->string('actor_name_snapshot')->nullable();
            $table->string('actor_original_role')->nullable();
            $table->string('actor_effective_role')->nullable();
            $table->string('authorization_permission')->nullable();
            $table->string('authorization_action', 20)->nullable();
            $table->boolean('actor_simulation')->nullable();
            $table->string('actor_ip_address', 45)->nullable();
            $table->text('actor_user_agent')->nullable();
            $table->string('provenance_status', 30)->default('missing')->index();
        });

        DB::table('employee_status_transitions')
            ->where('is_applied', true)
            ->update(['provenance_status' => 'legacy_historical']);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE employee_status_transitions ADD CONSTRAINT '.self::CHECK_NAME.' CHECK ('
            ."provenance_status IN ('captured', 'missing', 'legacy_historical')"
            .' AND (provenance_status <> \'captured\' OR ('
            .'actor_user_id_snapshot IS NOT NULL'
            .' AND NULLIF(BTRIM(actor_name_snapshot), \'\') IS NOT NULL'
            .' AND NULLIF(BTRIM(actor_original_role), \'\') IS NOT NULL'
            .' AND NULLIF(BTRIM(actor_effective_role), \'\') IS NOT NULL'
            .' AND NULLIF(BTRIM(authorization_permission), \'\') IS NOT NULL'
            .' AND authorization_action IN (\'deactivate\', \'restore\', \'status\')'
            .' AND actor_simulation IS NOT NULL'
            .' AND NULLIF(BTRIM(actor_ip_address), \'\') IS NOT NULL'
            .' AND NULLIF(BTRIM(actor_user_agent), \'\') IS NOT NULL'
            .')))',
        );

        // migrate:fresh menjatuhkan tabel tetapi fungsi PostgreSQL dapat tetap ada.
        // Replace menjaga bootstrap test/development repeatable tanpa melemahkan trigger.
        DB::statement('CREATE OR REPLACE FUNCTION '.self::IMMUTABLE_FUNCTION.'() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.actor_user_id_snapshot IS DISTINCT FROM OLD.actor_user_id_snapshot
                    OR NEW.actor_name_snapshot IS DISTINCT FROM OLD.actor_name_snapshot
                    OR NEW.actor_original_role IS DISTINCT FROM OLD.actor_original_role
                    OR NEW.actor_effective_role IS DISTINCT FROM OLD.actor_effective_role
                    OR NEW.authorization_permission IS DISTINCT FROM OLD.authorization_permission
                    OR NEW.authorization_action IS DISTINCT FROM OLD.authorization_action
                    OR NEW.actor_simulation IS DISTINCT FROM OLD.actor_simulation
                    OR NEW.actor_ip_address IS DISTINCT FROM OLD.actor_ip_address
                    OR NEW.actor_user_agent IS DISTINCT FROM OLD.actor_user_agent
                    OR NEW.provenance_status IS DISTINCT FROM OLD.provenance_status
                THEN
                    RAISE EXCEPTION \'Provenance transisi status bersifat immutable setelah insert.\'
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
            DB::statement(
                'DROP TRIGGER IF EXISTS '.self::IMMUTABLE_TRIGGER.' ON employee_status_transitions',
            );
            DB::statement('DROP FUNCTION IF EXISTS '.self::IMMUTABLE_FUNCTION.'()');
            DB::statement('ALTER TABLE employee_status_transitions DROP CONSTRAINT IF EXISTS '.self::CHECK_NAME);
        }

        Schema::table('employee_status_transitions', function (Blueprint $table): void {
            $table->dropIndex(['provenance_status']);
            $table->dropColumn([
                'actor_user_id_snapshot',
                'actor_name_snapshot',
                'actor_original_role',
                'actor_effective_role',
                'authorization_permission',
                'authorization_action',
                'actor_simulation',
                'actor_ip_address',
                'actor_user_agent',
                'provenance_status',
            ]);
        });
    }
};
