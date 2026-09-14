<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Single super_admin invariant is enforced via bootstrap lock (GET_LOCK/pg_advisory)
        // not via DB unique constraint, to allow intentional multi-super_admin via admin UI
        // (see UserMappingControllerTest). Keep migration as no-op for compatibility.

    }

    public function down(): void {}
};
