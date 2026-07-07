<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "employee_families" DROP CONSTRAINT IF EXISTS employee_families_hubungan_check;');

            DB::statement(<<<'SQL'
ALTER TABLE "employee_families"
  ALTER COLUMN "hubungan" TYPE varchar(255),
  ALTER COLUMN "hubungan" SET NOT NULL,
  ALTER COLUMN "hubungan" DROP DEFAULT;
SQL
            );

            DB::statement(<<<'SQL'
ALTER TABLE "employee_families"
  ADD CONSTRAINT employee_families_hubungan_check
    CHECK ("hubungan" IN ('Suami', 'Istri', 'Anak', 'Saudara'));
SQL
            );
        } else {
            Schema::table('employee_families', function (Blueprint $table): void {
                $table->enum('hubungan', ['Suami', 'Istri', 'Anak', 'Saudara'])->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "employee_families" DROP CONSTRAINT IF EXISTS employee_families_hubungan_check;');

            DB::statement(<<<'SQL'
ALTER TABLE "employee_families"
  ALTER COLUMN "hubungan" TYPE varchar(255),
  ALTER COLUMN "hubungan" SET NOT NULL,
  ALTER COLUMN "hubungan" DROP DEFAULT;
SQL
            );

            DB::statement(<<<'SQL'
ALTER TABLE "employee_families"
  ADD CONSTRAINT employee_families_hubungan_check
    CHECK ("hubungan" IN ('Suami', 'Istri', 'Anak'));
SQL
            );
        } else {
            Schema::table('employee_families', function (Blueprint $table): void {
                $table->enum('hubungan', ['Suami', 'Istri', 'Anak'])->change();
            });
        }
    }
};
