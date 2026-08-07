<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\RefJabatan;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Mengunci invariant penghapusan item referensi terhadap pembuatan riwayat yang
 * berjalan bersamaan. Tanpa serialisasi, FK nullOnDelete membuat database tidak
 * memblokir penghapusan dan justru mengosongkan kolom jabatan pada riwayat
 * pegawai, sehingga jejak penugasan hilang tanpa pesan kesalahan apa pun.
 */
class ReferenceDeleteConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private ?string $raceDirectory = null;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql') {
            $this->markTestSkipped('Race penghapusan referensi wajib dijalankan pada PostgreSQL.');
        }

        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        if ($this->raceDirectory !== null) {
            File::deleteDirectory($this->raceDirectory);
        }

        parent::tearDown();
    }

    public function test_penghapusan_jabatan_tidak_mengosongkan_riwayat_yang_dibuat_bersamaan(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Race penghapusan referensi wajib dijalankan pada PostgreSQL.');
        }

        $create = null;
        $delete = null;
        $releaseCreator = null;
        $continueDelete = null;

        try {
            $jabatan = RefJabatan::create(['nama' => 'Analis Kepegawaian', 'is_active' => true]);
            $employee = Employee::factory()->create();
            $user = User::factory()->superAdmin()->create();

            $directory = storage_path('framework/testing/ref-delete-'.Str::uuid());
            $this->raceDirectory = $directory;
            File::ensureDirectoryExists($directory);

            $creatorReady = $directory.'/creator-ready';
            $startCreator = $directory.'/start-creator';
            $inserted = $directory.'/inserted';
            $releaseCreator = $directory.'/release-creator';
            $deleteReady = $directory.'/delete-ready';
            $startDelete = $directory.'/start-delete';
            $usageChecked = $directory.'/usage-checked';
            $continueDelete = $directory.'/continue-delete';
            $createResult = $directory.'/create.json';
            $deleteResult = $directory.'/delete.json';
            $worker = base_path('tests/Fixtures/RefJabatanRaceWorker.php');

            $create = new Process([PHP_BINARY, $worker, base64_encode(json_encode([
                'mode' => 'create_history',
                'ready' => $creatorReady,
                'start' => $startCreator,
                'inserted' => $inserted,
                'release_creator' => $releaseCreator,
                'result' => $createResult,
                'employee_id' => $employee->id,
                'jabatan_id' => $jabatan->id,
            ], JSON_THROW_ON_ERROR))], base_path(), timeout: 90);
            $delete = new Process([PHP_BINARY, $worker, base64_encode(json_encode([
                'mode' => 'delete_jabatan',
                'ready' => $deleteReady,
                'start' => $startDelete,
                'usage_checked' => $usageChecked,
                'continue_delete' => $continueDelete,
                'result' => $deleteResult,
                'jabatan_id' => $jabatan->id,
                'user_id' => $user->id,
            ], JSON_THROW_ON_ERROR))], base_path(), timeout: 90);

            $create->start();
            if (! $this->waitForFile($creatorReady)) {
                $create->stop(1);

                self::fail('Worker pembuat tidak siap tepat waktu.');
            }

            File::put($startCreator, 'go');
            $this->assertTrue($this->waitForFile($inserted), 'Worker pembuat tidak menyisipkan riwayat tepat waktu.');

            $delete->start();
            if (! $this->waitForFile($deleteReady)) {
                $delete->stop(1);

                self::fail('Worker penghapus tidak siap tepat waktu.');
            }

            File::put($startDelete, 'go');

            // Pada implementasi lama, pemeriksaan melihat nol sebelum transaksi
            // pembuat commit. Setelah lock dipasang, penghapus tertahan lebih awal
            // pada baris jabatan dan file ini belum ada saat pembuat dilepas.
            $usageCheckedBeforeCommit = $this->waitForFile($usageChecked, 5);
            File::put($releaseCreator, 'commit');
            $create->wait();
            File::put($continueDelete, 'continue');
            $delete->wait();

            $createOutcome = json_decode(File::get($createResult), true, flags: JSON_THROW_ON_ERROR);
            $deleteOutcome = json_decode(File::get($deleteResult), true, flags: JSON_THROW_ON_ERROR);

            $this->assertTrue($createOutcome['ok'], 'Pembuatan riwayat seharusnya berhasil.');

            $history = PositionHistory::query()->where('employee_id', $employee->id)->sole();
            $this->assertSame(
                $jabatan->id,
                $history->jabatan_id,
                sprintf(
                    'Relasi jabatan pada riwayat hilang. Pemakaian dibaca sebelum commit pembuat: %s.',
                    $usageCheckedBeforeCommit ? 'ya' : 'tidak',
                ),
            );

            $this->assertFalse($deleteOutcome['ok'], 'Penghapusan seharusnya ditolak karena jabatan sudah dipakai.');
            $this->assertSame(
                ValidationException::class,
                $deleteOutcome['class'] ?? null,
                'Penolakan harus berasal dari guard pemakaian, bukan kegagalan lain: '.($deleteOutcome['message'] ?? ''),
            );
            $this->assertDatabaseHas('ref_jabatan', ['id' => $jabatan->id]);
            $this->assertDatabaseMissing('audit_logs', [
                'event' => 'DELETE',
                'auditable_type' => 'RefJabatan',
            ]);
        } finally {
            if ($releaseCreator !== null) {
                File::put($releaseCreator, 'cleanup');
            }

            if ($continueDelete !== null) {
                File::put($continueDelete, 'cleanup');
            }

            $create?->stop(1);
            $delete?->stop(1);
            DB::table('audit_logs')->delete();
        }
    }

    private function waitForFile(string $path, int $timeoutSeconds = 30): bool
    {
        $deadline = hrtime(true) + ($timeoutSeconds * 1_000_000_000);

        while (! File::exists($path) && hrtime(true) < $deadline) {
            usleep(10_000);
        }

        return File::exists($path);
    }
}
