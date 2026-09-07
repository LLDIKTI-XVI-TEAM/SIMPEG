<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('serial')]
class LeaveUsageRetirementMigrationRollbackTest extends TestCase
{
    use DatabaseMigrations;

    public function test_retirement_schema_can_be_rolled_back_for_database_migration_isolation(): void
    {
        $this->assertFalse(Schema::hasTable('leave_usage_reconciliation_sets'));
        $this->assertFalse(Schema::hasTable('leave_usage_reconciliation_memberships'));
        $this->assertFalse(Schema::hasColumn('leave_usage_records', 'reconciliation_set_id'));
        $this->assertFalse(Schema::hasColumn('leave_usage_documents', 'leave_usage_reconciliation_set_id'));
    }
}
