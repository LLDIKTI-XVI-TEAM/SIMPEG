<?php

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery\Expectation;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class NikHashMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_mempertahankan_nik_hash_pada_record_aktif_saat_duplikat_legacy(): void
    {
        $nikDuplikat = '7101010101010001';
        $recordAktif = Employee::factory()->create([
            'nik' => '7101010101010002',
            'created_at' => now()->subYears(2),
            'updated_at' => now()->subYears(2),
        ]);
        $recordSoftDeleted = Employee::factory()->create([
            'nik' => '7101010101010003',
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);

        // Bentuk ulang schema tepat sebelum migrasi nik_hash: deleted_at masih
        // menjadi sumber status aktif dan record baru bisa berupa soft-deleted.
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique('employees_nik_hash_unique');
            $table->dropColumn('nik_hash');
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->softDeletes();
        });

        $encryptedNik = Crypt::encryptString($nikDuplikat);
        DB::table('employees')->where('id', $recordAktif->id)->update([
            'nik' => $encryptedNik,
            'deleted_at' => null,
        ]);
        DB::table('employees')->where('id', $recordSoftDeleted->id)->update([
            'nik' => $encryptedNik,
            'deleted_at' => now()->subMonth(),
        ]);

        /** @var MockInterface&LoggerInterface $logSpy */
        $logSpy = Log::spy();

        $migration = require database_path('migrations/2026_08_03_000001_add_nik_hash_to_employees.php');
        $this->assertInstanceOf(Migration::class, $migration);
        call_user_func([$migration, 'up']);

        $expectedHash = hash_hmac('sha256', $nikDuplikat, config('app.key'));

        $this->assertSame($expectedHash, DB::table('employees')->where('id', $recordAktif->id)->value('nik_hash'));
        $this->assertNull(DB::table('employees')->where('id', $recordSoftDeleted->id)->value('nik_hash'));
        /** @var Expectation $logExpectation */
        $logExpectation = $logSpy->shouldHaveReceived('warning');
        $logExpectation->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Duplikat NIK ditemukan')
                && $context['employee_id'] === $recordSoftDeleted->id
                && ! array_key_exists('nik_hash', $context))
            ->once();
    }
}
