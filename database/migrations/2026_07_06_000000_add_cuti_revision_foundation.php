<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ref_jenis_cuti', function (Blueprint $table): void {
            $table->string('code', 50)->nullable()->unique()->after('nama');
            $table->boolean('mengurangi_saldo_tahunan')->default(false)->after('code');
        });

        Schema::create('leave_approval_chains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('change_reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'is_active']);
        });

        $this->createOneActiveChainIndex();

        Schema::create('leave_approval_chain_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('leave_approval_chain_id')->constrained('leave_approval_chains')->cascadeOnDelete();
            $table->unsignedSmallInteger('step_order');
            $table->string('step_type', 50);
            $table->string('role_label', 100);
            $table->string('approver_role_key', 100)->nullable();
            $table->foreignUuid('approver_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->boolean('is_final')->default(false);
            $table->timestamps();

            $table->unique(['leave_approval_chain_id', 'step_order'], 'leave_chain_steps_order_unique');
            $table->index('approver_employee_id');
        });

        Schema::create('leave_pybmc_global_config', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('approver_employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('effective_from');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('change_reason')->nullable();
            $table->timestamps();

            $table->index(['effective_from', 'approver_employee_id'], 'leave_pybmc_effective_approver_index');
        });

        Schema::create('leave_request_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('step_order');
            $table->string('step_type', 50);
            $table->string('role_label', 100);
            $table->foreignUuid('approver_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 50)->default('pending');
            $table->boolean('is_final')->default(false);
            $table->string('skipped_reason', 100)->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->unique(['leave_request_id', 'step_order'], 'leave_request_steps_order_unique');
            $table->index(['leave_request_id', 'status']);
            $table->index('approver_employee_id');
        });

        Schema::create('leave_balance_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('leave_request_id')->nullable()->constrained('leave_requests')->nullOnDelete();
            $table->foreignUuid('leave_balance_id')->nullable()->constrained('leave_balances')->nullOnDelete();
            $table->year('tahun');
            $table->string('event_type', 50);
            $table->integer('amount');
            $table->year('source_year')->nullable();
            $table->string('sumber_carry_over', 50)->nullable();
            $table->text('reason')->nullable();
            $table->string('dedup_key', 150)->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'tahun']);
            $table->index(['event_type', 'tahun']);
        });

        Schema::create('leave_proofs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->string('token', 120)->unique();
            $table->string('document_path', 255)->nullable();
            $table->foreignUuid('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique('leave_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_proofs');
        Schema::dropIfExists('leave_balance_ledger');
        Schema::dropIfExists('leave_request_steps');
        Schema::dropIfExists('leave_pybmc_global_config');
        Schema::dropIfExists('leave_approval_chain_steps');
        $this->dropOneActiveChainIndex();
        Schema::dropIfExists('leave_approval_chains');

        Schema::table('ref_jenis_cuti', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'mengurangi_saldo_tahunan']);
        });
    }

    private function createOneActiveChainIndex(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX leave_approval_chains_one_active_per_employee ON leave_approval_chains (employee_id) WHERE is_active = true');
        }

        if ($driver === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX leave_approval_chains_one_active_per_employee ON leave_approval_chains (employee_id) WHERE is_active = 1');
        }
    }

    private function dropOneActiveChainIndex(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS leave_approval_chains_one_active_per_employee');
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS leave_approval_chains_one_active_per_employee');
        }
    }
};
